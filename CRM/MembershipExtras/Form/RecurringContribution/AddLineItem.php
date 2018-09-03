<?php

use CRM_MembershipExtras_ExtensionUtil as E;

/**
 * Abstract class defining methods used to add a new line item to a recurring
 * contribution.
 */
abstract class CRM_MembershipExtras_Form_RecurringContribution_AddLineItem extends CRM_Core_Form {

  /**
   * Recurring contribution's data.
   *
   * @var array
   */
  protected $recurringContribution;

  /**
   * Parameters to be used to create the new line item.
   *
   * @var
   */
  protected $lineItemParams;

  /**
   * @inheritdoc
   */
  public function preProcess() {
    $recurringContributionID = CRM_Utils_Request::retrieve('contribution_recur_id', 'Text', $this);
    $this->recurringContribution = $this->getRecurringContribution($recurringContributionID);
    $this->lineItemParams = CRM_Utils_Request::retrieve('line_item', 'Text', $this);
  }

  /**
   * Returns information for the recurring contribution identified by $id.
   *
   * @param int $id
   *
   * @return array
   */
  protected function getRecurringContribution($id) {
    return civicrm_api3('ContributionRecur', 'getsingle', [
      'id' => $id
    ]);
  }

  /**
   * @inheritdoc
   */
  public function setDefaultValues() {
    return [
      'first_installment_amount' => $this->lineItemParams['amount']
    ];
  }

  /**
   * @inheritdoc
   */
  public function buildQuickForm() {
    $this->add('checkbox', 'adjust_first_amount', ts('Adjust the amount of the first instalment?'), [], FALSE);
    $this->addMoney('first_installment_amount', ts('First Installment Amount'), FALSE, [], FALSE);
    $this->assign('newLineItem', $this->lineItemParams);

    $this->addButtons([
      [
        'type' => 'submit',
        'name' => E::ts('Apply'),
        'isDefault' => TRUE,
      ],
      [
        'type' => 'cancel',
        'name' => E::ts('Cancel'),
        'isDefault' => FALSE,
      ],
    ]);
  }

  /**
   * Returns tax rate used for given financial type ID.
   *
   * @param $financialTypeID
   *
   * @return double
   */
  protected function getTaxRateForFinancialType($financialTypeID) {
    $taxRates = CRM_Core_PseudoConstant::getTaxRates();
    $rate = round(CRM_Utils_Array::value($financialTypeID, $taxRates, 0), 2);

    return $rate;
  }

  /**
   * Adds new line item to pending contributions and updates their amounts,
   * recording appropriate financial transactions.
   *
   * @param $recurringLineItem
   */
  protected function addLineItemToPendingContributions($recurringLineItem) {
    $firstContribution = TRUE;

    foreach ($this->getPendingContributions() as $contribution) {
      $lineItemParams = $recurringLineItem;
      $lineItemParams['contribution_id'] = $contribution['id'];
      unset($lineItemParams['id']);

      if ($firstContribution && $this->getElementValue('adjust_first_amount')) {
        $firstAmountTotal = $this->getElementValue('first_installment_amount');
        $taxRates = CRM_Core_PseudoConstant::getTaxRates();
        $rate = CRM_Utils_Array::value($recurringLineItem['financial_type_id'], $taxRates, 0);

        $lineItemParams['tax_amount'] = round(($firstAmountTotal * ($rate / 100)) / (1 + ($rate / 100)), 2);
        $lineItemParams['unit_price'] = round($firstAmountTotal - $lineItemParams['tax_amount'], 2);
        $lineItemParams['line_total'] = $lineItemParams['unit_price'];

        $firstContribution = FALSE;
      }

      $existingLineItem = $this->findExistingLineItemForContribution($lineItemParams);
      if (CRM_Utils_Array::value('id', $existingLineItem, FALSE)) {
        $lineItemParams['id'] = $existingLineItem['id'];
      }

      $lineItemCreateResult = civicrm_api3('LineItem', 'create', $lineItemParams);
      $lineItem = array_shift($lineItemCreateResult['values']);

      // calculate balance, tax and paid amount later used to adjust transaction
      $updatedAmount = CRM_Price_BAO_LineItem::getLineTotal($contribution['id']);
      $taxAmount = CRM_MembershipExtras_Service_FinancialTransactionManager::calculateTaxAmountTotalFromContributionID($contribution['id']);

      // Record adjusted amount by updating contribution info
      CRM_MembershipExtras_Service_FinancialTransactionManager::recordAdjustedAmount($contribution, $updatedAmount, $taxAmount);

      // Record financial item on adding of line item
      CRM_MembershipExtras_Service_FinancialTransactionManager::insertFinancialItemOnLineItemAddition($lineItem);
    }
  }

  /**
   * Returns an array with the information of pending recurring contributions
   * after selected start date.
   *
   * @return array
   */
  protected function getPendingContributions() {
    $result = civicrm_api3('Contribution', 'get', [
      'sequential' => 1,
      'contribution_recur_id' => $this->recurringContribution['id'],
      'contribution_status_id' => 'Pending',
      'receive_date' => ['>=' => $this->lineItemParams['start_date']],
      'options' => ['limit' => 0],
    ]);

    if ($result['count'] > 0) {
      return $result['values'];
    }

    return [];
  }

  /**
   * Creates new line item associated to the recurring contribution.
   *
   * @return array
   */
  abstract protected function createRecurringLineItem();

  /**
   * Looks for an existing line item for the contribution.
   *
   * @param $lineItemParams
   *
   * @return array
   */
  abstract protected function findExistingLineItemForContribution($lineItemParams);

}
