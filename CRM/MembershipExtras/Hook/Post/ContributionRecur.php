<?php
use CRM_MembershipExtras_Service_ManualPaymentProcessors as ManualPaymentProcessors;

/**
 * Implements post-process hooks on ContributionRecur entity.
 */
class CRM_MembershipExtras_Hook_Post_ContributionRecur {

  /**
   * Reference to the recurring contribution's BAO that was stored.
   *
   * @var \CRM_Contribute_BAO_ContributionRecur
   */
  private $contributionRecurBAO;

  /**
   * CRM_MembershipExtras_Hook_Post_ContributionRecur constructor.
   *
   * @param \CRM_Contribute_BAO_ContributionRecur $contributionBAO
   */
  public function __construct(CRM_Contribute_BAO_ContributionRecur $contributionBAO) {
    $this->contributionRecurBAO = CRM_Contribute_BAO_ContributionRecur::findById($contributionBAO->id);
  }

  /**
   * Post processes recurring contribution entity.
   */
  public function postProcess() {
    $isManualPaymentPlan = ManualPaymentProcessors::isManualPaymentProcessor($this->contributionRecurBAO->payment_processor_id);
    if ($isManualPaymentPlan) {
      $this->updateLineItemEndDates();
    }
  }

  /**
   * Updates end dates for line items associated to the recurring contribution.
   */
  private function updateLineItemEndDates() {
    $contributionStatus = CRM_Contribute_PseudoConstant::contributionStatus($this->contributionRecurBAO->contribution_status_id, 'name');

    if ($contributionStatus === 'Completed' && $this->contributionRecurBAO->installments > 1) {
      $subscriptionLines = $this->getSubscriptionLines();

      foreach($subscriptionLines as $line) {
        if (!empty($line['start_date']) && empty($line['end_date'])) {
          civicrm_api3('ContributionRecurLineItem', 'create', [
            'id' => $line['id'],
            'end_date' => $this->contributionRecurBAO->end_date,
          ]);
        }
      }
    }
  }

  /**
   * Returns LineItems associated to a recurring contribution
   *
   * @return array
   */
  private function getSubscriptionLines() {
    $result = civicrm_api3('ContributionRecurLineItem', 'get', [
      'sequential' => 1,
      'contribution_recur_id' => $this->contributionRecurBAO->id,
      'options' => ['limit' => 0],
    ]);

    if ($result['count']) {
      return $result['values'];
    }

    return [];
  }

}
