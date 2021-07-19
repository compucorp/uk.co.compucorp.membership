<?php

use CRM_MembershipExtras_Service_MembershipEndDateCalculator as MembershipEndDateCalculator;
use CRM_MembershipExtras_Service_ManualPaymentProcessors as ManualPaymentProcessors;

/**
 * Implements hook to be run before a membership is created/edited.
 */
class CRM_MembershipExtras_Hook_Pre_MembershipEdit {

  /**
   * Parameters that will be used to create the membership.
   *
   * @var array
   */
  private $params;

  /**
   * ID of the membership.
   *
   * @var int
   */
  private $id;

  /**
   * The membership payment contribution ID.
   *
   * @var int
   */
  private $paymentContributionID;

  /**
   * The payment type e.g. refund or owed
   *
   * see CRM_Contribute_Form_AdditionalPayment::getPaymentType
   *
   * @var string
   */
  private $paymentType;

  /**
   * We don't want to extend the same membership
   * more than one time if for whatever reason
   * this hook get called more than one time
   * during the same session, so here
   * we keep the list of already extended
   * memberships to achieve that.
   *
   * @var array
   */
  private static $extendedMemberships = [];

  public function __construct($id, &$params, $contributionID, $paymentType) {
    $this->id = $id;
    $this->params = &$params;
    $this->paymentContributionID = $contributionID;
    $this->paymentType = $paymentType;
  }

  /**
   * Preprocesses parameters used for Membership operations.
   */
  public function preProcess() {
    if ($this->paymentContributionID || $this->isRecordingPayment() || $this->isBulkStatusUpdate()) {
      $this->preventEditingTheActiveMembershipIfItsContributionHasAnotherPayment();
      $this->preventExtendingPaymentPlanMembership();
    }

    if (!in_array($this->id, self::$extendedMemberships)) {
      $isPaymentPlan = $this->isPaymentPlanBeingRecordedOnForm();
      $isMembershipRenewal = CRM_Utils_Request::retrieve('action', 'String') & CRM_Core_Action::RENEW;
      if ($isMembershipRenewal && $isPaymentPlan) {
        self::$extendedMemberships[] = $this->id;
        $this->extendPendingPaymentPlanMembershipOnRenewal();
      }
    }

    if ($this->isOfflinePaymentPlanMembership()) {
      $this->verifyMembershipStartDate();
    }
  }

  private function isRecordingPayment() {
    $paymentRecordingDetails = $this->parsePaymentRecordingInformation();

    if (empty($paymentRecordingDetails)) {
      return FALSE;
    }

    $isAddAction = FALSE;
    if (!empty($paymentRecordingDetails['action']) && $paymentRecordingDetails['action'] == 'add') {
      $isAddAction = TRUE;
    }

    $contributionId = NULL;
    if (!empty($paymentRecordingDetails['id'])) {
      $contributionId = $paymentRecordingDetails['id'];
    }

    $isRecordPayment = $this->paymentType === 'owed';

    if ($isAddAction && $contributionId && $isRecordPayment) {
      $this->paymentContributionID = $contributionId;

      return TRUE;
    }

    return FALSE;
  }

  /**
   * Checks the request to see if a bulk status update is being done.
   *
   * @return bool
   * @throws \CRM_Core_Exception
   */
  private function isBulkStatusUpdate() {
    $statusNext = CRM_Utils_Request::retrieve('_qf_Status_next', 'String');
    $contributionStatusID = CRM_Utils_Request::retrieve('contribution_status_id', 'String');
    $currentPath = CRM_Utils_System::currentPath();

    if (stripos($currentPath, 'civicrm/contribute/search') !== FALSE && $statusNext === 'Update Pending Status' && !empty($contributionStatusID)) {
      return TRUE;
    }

    return FALSE;
  }

  private function parsePaymentRecordingInformation() {
    $recordPaymentEntryURL = CRM_Utils_Request::retrieve('entryURL', 'String');
    $recordPaymentEntryURL = html_entity_decode($recordPaymentEntryURL);

    $urlParts = parse_url($recordPaymentEntryURL);

    if (!empty($urlParts['query'])) {
      parse_str($urlParts['query'], $urlParams);
      return $urlParams;
    }

    return [];
  }

  /**
   * Prevents extending offline payment plan Membership.
   *
   * If a membership price will be paid using
   * payment plan then each time an installment get
   * paid the membership will get extended.
   * For example if you have 12 installments for
   * a 1 year membership, then each time an
   * installment get paid the membership will get extended
   * by one year, this method prevent civicrm from doing that
   * so the membership gets only extended once when you renew it.
   */
  public function preventExtendingPaymentPlanMembership() {
    if ($this->isOfflinePaymentPlanMembership()) {
      unset($this->params['end_date']);
    }
  }

  /**
   * Prevents editing the active Membership if its non payment-plan
   * contribution has another payment.
   *
   * Usually recording the payment for the membership's contribution means :
   *
   * - Activating the membership by changing its status from pending to active.
   * - Renewing the membership by extending its end_date.
   *
   * At Compucorp, we use the "biz.jmaconsulting.lineitemedit" extension to add
   * and edit the line items.
   *
   * Adding a line item changes the contribution status to "Partially Paid"
   * and recording a payment for an active membership's contribution will renew
   * it by default and extend its end_date, but this is undesirable behaviour
   * and this method will prevent it.
   */
  public function preventEditingTheActiveMembershipIfItsContributionHasAnotherPayment() {
    // Check if the contribution is a non payment plan.
    $recContributionID = $this->getMembershipRecurringContributionID();
    if ($recContributionID !== NULL) {
      return FALSE;
    }

    // Check if the membership is active.
    $result = civicrm_api3('Membership', 'get', [
      'sequential' => 1,
      'active_only' => 1,
      'id' => $this->id,
    ]);
    if ($result['count'] < 1) {
      return;
    }

    // Check if the contribution is "Partially paid".
    $result = civicrm_api3('Contribution', 'getcount', [
      'sequential' => 1,
      'return' => ["contribution_status_id"],
      'id' => $this->paymentContributionID,
      'contribution_status_id' => "Partially paid",
    ]);
    if ($result['count'] < 1) {
      return;
    }

    $this->params = [
      'id' => $this->params['id'],
    ];
  }

  /**
   * Determines if the payment for a membership
   * subscription is offline (pay later) and paid
   * as payment plan.
   *
   * @return bool
   */
  private function isOfflinePaymentPlanMembership() {
    $recContributionID = $this->getMembershipRecurringContributionID();

    if ($recContributionID === NULL) {
      return FALSE;
    }

    return $this->isOfflinePaymentPlanContribution($recContributionID);
  }

  /**
   * Determines if the recurring contribution
   * is offline (pay later) and is for
   * a payment plan.
   *
   * @param $recurringContributionID
   * @return bool
   */
  private function isOfflinePaymentPlanContribution($recurringContributionID) {
    $recurringContribution = civicrm_api3('ContributionRecur', 'get', [
      'sequential' => 1,
      'id' => $recurringContributionID,
    ])['values'][0];

    $isOfflineContribution = ManualPaymentProcessors::isManualPaymentProcessor($recurringContribution['payment_processor_id']);
    if ($isOfflineContribution) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Gets the associated recurring contribution ID for
   * the membership payment(contribution) if it does exist.
   *
   * @return int|null
   *   The recurring contribution ID or NULL
   *   if no recurring contribution exist.
   */
  private function getMembershipRecurringContributionID() {
    if (!empty($this->paymentContributionID)) {
      return $this->getRecurringContributionIDFromContributionID($this->paymentContributionID);
    }

    return $this->getRecurringContributionFromMembership($this->id);
  }

  /**
   * Obtains recurring contribution from the membership.
   *
   * @param $membershipID
   *
   * @return mixed|null
   * @throws \CiviCRM_API3_Exception
   */
  private function getRecurringContributionFromMembership($membershipID) {
    $membership = civicrm_api3('Membership', 'getsingle', [
      'sequential' => 1,
      'id' => $membershipID,
      'options' => ['limit' => 0],
    ]);
    $recurringContributionID = CRM_Utils_Array::value('contribution_recur_id', $membership, NULL);
    if (!empty($recurringContributionID)) {
      return $recurringContributionID;
    }

    $result = civicrm_api3('LineItem', 'get', [
      'sequential' => 1,
      'entity_id' => $membershipID,
      'contribution_id' => ['IS NULL' => 1],
      'options' => ['limit' => 0],
      'api.ContributionRecurLineItem.get' => [
        'sequential' => 1,
        'line_item_id' => '$value.id',
        'options' => ['limit' => 0],
      ],
    ]);

    if ($result['count'] < 1) {
      return NULL;
    }

    $line = $result['values'][0];
    if ($line['api.ContributionRecurLineItem.get']['count'] < 1) {
      return NULL;
    }

    $recurringLine = $line['api.ContributionRecurLineItem.get']['values'][0];

    return $recurringLine['contribution_recur_id'];
  }

  /**
   * Obtains recurring contribution ID from the contribution.
   *
   * @param int $contributionID
   *
   * @return int|null
   * @throws \CiviCRM_API3_Exception
   */
  private function getRecurringContributionIDFromContributionID($contributionID) {
    $paymentContribution = civicrm_api3('Contribution', 'get', [
      'sequential' => 1,
      'id' => $contributionID,
      'return' => ['id', 'contribution_recur_id'],
    ]);

    if (empty($paymentContribution['values'][0]['contribution_recur_id'])) {
      return NULL;
    }

    return $paymentContribution['values'][0]['contribution_recur_id'];
  }

  /**
   * Determines if the membership is paid using payment plan option.
   *
   * @return bool
   * @throws \CRM_Core_Exception
   */
  private function isPaymentPlanBeingRecordedOnForm() {
    $isSavingContribution = CRM_Utils_Request::retrieve('record_contribution', 'Int');
    $contributionIsPaymentPlan = CRM_Utils_Request::retrieve('contribution_type_toggle', 'String') === 'payment_plan';

    if ($isSavingContribution && $contributionIsPaymentPlan) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   *
   * Extends the membership at renewal if the selected
   * payment status is pending.
   *
   * When renewing a membership through civicrm and selecting
   * the payment status as pending, then the membership will not
   * get extended unless you marked the first payment as complete,
   * So this method make sure it get extended without the need to
   * complete the first payment.
   */
  public function extendPendingPaymentPlanMembershipOnRenewal() {
    $pendingStatusValue = civicrm_api3('OptionValue', 'getvalue', [
      'return' => 'value',
      'option_group_id' => 'contribution_status',
      'name' => 'Pending',
    ]);
    $isPaymentPending = (CRM_Utils_Request::retrieve('contribution_status_id', 'String') === $pendingStatusValue);
    if (!$isPaymentPending) {
      return;
    }

    $this->params['end_date'] = MembershipEndDateCalculator::calculate($this->id);
  }

  /**
   * Checks if the start_date set in $params array is changed and if so, if it
   * makes sense within payment plan. If not, it corrects it.
   */
  private function verifyMembershipStartDate() {
    if (empty($this->params['start_date']) || $this->startDateSetInForm()) {
      return;
    }

    $membershipStartDate = $this->getCurrentMembershipStartDate();
    if ($this->params['start_date'] == $membershipStartDate) {
      return;
    }

    $this->params['start_date'] = $membershipStartDate;
  }

  /**
   * Checks if start_date has been sent on a form by checkin if the value is on
   * the request.
   *
   * @return bool
   */
  private function startDateSetInForm() {
    try {
      $startDateFromForm = CRM_Utils_Request::retrieve('start_date', 'Date');
    }
    catch (CRM_Core_Exception $e) {
      return FALSE;
    }

    if (empty($startDateFromForm)) {
      return FALSE;
    }

    $formDate = new Date($startDateFromForm);
    $paramsDate = new Date($this->params['start_date']);

    if ($formDate === $paramsDate) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Returns start_date of membership to be updated.
   *
   * @return string
   */
  private function getCurrentMembershipStartDate() {
    try {
      $membership = civicrm_api3('Membership', 'getsingle', [
        'sequential' => 1,
        'id' => $this->id,
      ]);
    }
    catch (Exception $e) {
      return '';
    }

    return $membership['start_date'];
  }

}
