<?php

use CRM_MembershipExtras_Service_InstallmentReceiveDateCalculator as InstallmentReceiveDateCalculator;

/**
 * Post-processes Recurring Conribution Update form.
 */
class CRM_MembershipExtras_Hook_PostProcess_UpdateSubscription {

  /**
   * Form to be processed.
   *
   * @var \CRM_Contribute_Form_UpdateSubscription
   */
  private $form;

  /**
   * Array with the data of the recurring contribution that is being updated.
   *
   * @var array
   */
  private $recurringContribution;

  /**
   * Object that calcuates installment receive date.
   *
   * @var CRM_MembershipExtras_Service_InstallmentReceiveDateCalculator
   */
  private $receiveDateCalculator;

  /**
   * Due date of next contribution of the payment plan.
   *
   * @var string
   */
  private $nextContributionDate;

  public function __construct(CRM_Contribute_Form_UpdateSubscription $form) {
    $this->form = $form;
    $this->setRecurringContribution();
  }

  /**
   * Loads data for recurring contribution identified by 'crid' parameter in
   * http request.
   */
  private function setRecurringContribution() {
    $recurringContributionID = CRM_Utils_Request::retrieve('crid', 'Integer', $this->form, TRUE);
    $this->recurringContribution = civicrm_api3('ContributionRecur', 'get', [
      'sequential' => 1,
      'id' => $recurringContributionID,
    ])['values'][0];
  }

  /**
   * Post-processes CRM_Contribute_Form_UpdateSubscription to deal with added
   * fields.
   */
  public function postProcess() {
    $this->updateMembership();
    $this->updateRelatedInstallments();
    $this->updateRecurringContribution();
    $this->updateSubscriptionLineItems();
  }

  /**
   * Updates data for recurring contribution.
   */
  private function updateRecurringContribution() {
    $autoRenew = $this->form->getElementValue('auto_renew');
    $params = [
      'id' => $this->recurringContribution['id'],
      'auto_renew' => $autoRenew,
    ];

    if ($this->isUpdatedCycleDate()) {
      $params['next_sched_contribution_date'] = $this->nextContributionDate;
    }

    civicrm_api3('ContributionRecur', 'create', $params);
  }

  /**
   * Updates recurring line items associated to the recurring contribution.
   */
  private function updateSubscriptionLineItems() {
    $autoRenew = $this->form->getElementValue('auto_renew');
    
    if (!$autoRenew) {
      return;
    }

    civicrm_api3('ContributionRecurLineItem', 'get', [
      'sequential' => 1,
      'contribution_recur_id' => $this->recurringContribution['id'],
      'is_removed' => 0,
      'options' => ['limit' => 0],
      'api.ContributionRecurLineItem.create' => [
        'id' => '$value.id',
        'auto_renew' => $autoRenew,
      ]
    ]);
  }
  
  /**
   * Updates membership if necessary.
   */
  private function updateMembership() {
    $autoRenew = $this->form->getElementValue('auto_renew');
    $membershipID = $this->getRelatedMembershipID();

    if ($autoRenew && $membershipID) {
      civicrm_api3('Membership', 'create', [
        'id' => $membershipID,
        'contribution_recur_id' => $this->recurringContribution['id'],
      ]);
    } elseif (!$autoRenew) {
      civicrm_api3('Membership', 'create', [
        'id' => $membershipID,
        'contribution_recur_id' => '',
      ]);
    }
  }

  /**
   * Obtains membership ID of payments done with contributions related to
   * current recurring contribution.
   */
  private function getRelatedMembershipID() {
    $result = civicrm_api3('Contribution', 'get', [
      'sequential' => 1,
      'contribution_recur_id' => $this->recurringContribution['id'],
      'api.MembershipPayment.get' => ['contribution_id' => '$value.id'],
      'options' => ['limit' => 0, 'sort' => 'id DESC'],
    ]);

    if ($result['count']) {
      foreach ($result['values'] as $relatedContribution) {
        $membershipPaymentResult = $relatedContribution['api.MembershipPayment.get'];

        if ($membershipPaymentResult['count'] > 0) {
          $paymentData = array_shift($membershipPaymentResult['values']);

          return $paymentData['membership_id'];
        }
      }
    }

    return false;
  }

  /**
   * Updates related contributions with values set on the form.
   */
  private function updateRelatedInstallments() {
    $contributions = $this->getContributions();
    $updateInstallments = CRM_Utils_Request::retrieve('update_installments', 'Integer', $this->form, FALSE);

    if (count($contributions) < 1 || !$updateInstallments) {
      return;
    }

    $formValues = $this->form->exportValues();
    $newFirstInstallmentReceiveDate = $this->calculateFirstReceiveDate(
      $contributions[0]['receive_date'],
      $formValues['cycle_day']
    );

    $installmentCount = 0;
    $this->receiveDateCalculator = new InstallmentReceiveDateCalculator($this->recurringContribution);
    $this->receiveDateCalculator->setStartDate($newFirstInstallmentReceiveDate);

    foreach ($contributions as $payment) {
      $installmentCount++;

      if ($payment['contribution_status'] != 'Pending') {
        continue;
      }

      $this->updateContribution(
        $payment['id'],
        $formValues['payment_instrument_id'],
        $installmentCount
      );
    }
  }

  /**
   * Returns list of contributions associated to current recurring contribution.
   *
   * @return array
   */
  private function getContributions() {
    $result = civicrm_api3('Contribution', 'get', [
      'sequential' => 1,
      'contribution_recur_id' => $this->recurringContribution['id'],
      'options' => ['limit' => 0, 'sort' => 'receive_date ASC'],
    ]);

    if ($result['count'] > 0) {
      return $result['values'];
    }

    return array();
  }

  /**
   * Calculates first receive date for new cycle day.
   *
   * @param string $currentDate
   * @param int $newCycleDay
   *
   * @return string
   */
  private function calculateFirstReceiveDate($currentDate, $newCycleDay) {
    $frequency = $this->recurringContribution['frequency_unit'];
    $currentCycleDay = CRM_MembershipExtras_Service_CycleDayCalculator::calculate($currentDate, $frequency);

    $difference = $newCycleDay - $currentCycleDay;
    $absoluteDifference = abs($difference);
    $interval = "P{$absoluteDifference}D";

    $date = new DateTime($currentDate);

    if ($difference > 0) {
      $date->add(new DateInterval($interval));
    } elseif ($difference < 0) {
      $date->sub(new DateInterval($interval));
    }

    return $date->format('Y-m-d H:i:s');
  }

  /**
   * Respectively updates receive date and/or payment instrument if either of
   * those were modified for the current recurring contribution.
   *
   * @param int $contributionID
   * @param int $instrumentID
   * @param int $installmentNumber
   */
  private function updateContribution($contributionID, $instrumentID, $installmentNumber) {
    $params = [];

    if ($this->isUpdatedCycleDate()) {
      $params['receive_date'] = $this->receiveDateCalculator->calculate($installmentNumber);

      if (empty($this->nextContributionDate)) {
        $this->nextContributionDate = $params['receive_date'];
      }
    }

    if ($this->isUpdatedPaymentInstrument()) {
      $params['payment_instrument_id'] = $instrumentID;
    }

    if (!empty($params)) {
      $params['id'] = $contributionID;
      civicrm_api3('Contribution', 'create', $params);
    }
  }

  /**
   * Checks if cycle date was updated by user.
   *
   * @return bool
   *   True if the value was changed by user, false otherwise.
   */
  private function isUpdatedCycleDate() {
    $formValues = $this->form->exportValues();
    $oldCycleDay = CRM_Utils_Request::retrieve('old_cycle_day', 'Integer', $this->form, TRUE);

    return $formValues['cycle_day'] != $oldCycleDay;
  }

  /**
   * Checks if payment instrument was updated by user.
   *
   * @return bool
   *   True if the value was changed by user, false otherwise.
   */
  private function isUpdatedPaymentInstrument() {
    $formValues = $this->form->exportValues();
    $oldPaymentInstrument = CRM_Utils_Request::retrieve('old_payment_instrument_id', 'Integer', $this->form, TRUE);

    return $formValues['cycle_day'] != $oldPaymentInstrument;
  }

}
