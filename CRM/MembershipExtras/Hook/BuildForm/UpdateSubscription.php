<?php

use CRM_MembershipExtras_Service_ManualPaymentProcessors as ManualPaymentProcessors;

/**
 * Alters UpdateSubscription form.
 */
class CRM_MembershipExtras_Hook_BuildForm_UpdateSubscription {

  /**
   * Form that needs to be altered.
   *
   * @var \CRM_Contribute_Form_UpdateSubscription
   */
  private $form;

  /**
   * Path to where extension templates are physically stored.
   *
   * @var string
   */
  private $templatePath;

  /**
   * Array with the data of the recurring contribution that is being updated.
   *
   * @var array
   */
  private $recurringContribution;

  public function __construct(CRM_Contribute_Form_UpdateSubscription $form) {
    $this->form = $form;
    $this->templatePath = CRM_MembershipExtras_ExtensionUtil::path() . '/templates';
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
   * Implements modifications to UpdateSubscription form.
   */
  public function buildForm() {
    $isManualPaymentPlan = ManualPaymentProcessors::isManualPaymentProcessor($this->recurringContribution['payment_processor_id']);
    if (!$isManualPaymentPlan) {
      return;
    }

    $amount = $this->form->getElement('amount');
    $amount->setAttribute('readonly', true);

    $installments = $this->form->getElement('installments');
    $installments->setAttribute('readonly', true);

    $this->form->add('checkbox', 'auto_renew', ts('Auto-renew?'));
    $this->form->setDefaults(['auto_renew' => $this->recurringContribution['auto_renew']]);

    $this->form->add('select', 'payment_instrument_id',
      ts('Payment Method'),
      ['' => ts('- select -')] + CRM_Contribute_PseudoConstant::paymentInstrument(),
      TRUE
    );
    $this->form->setDefaults(['payment_instrument_id' => $this->recurringContribution['payment_instrument_id']]);
    $this->form->assign('isBackOffice', 1);

    $this->form->add('text', 'cycle_day', ts('Cycle Day'), TRUE);
    $this->form->setDefaults(['cycle_day' => $this->recurringContribution['cycle_day']]);

    CRM_Core_Region::instance('page-body')->add([
      'template' => "{$this->templatePath}/CRM/Member/Form/UpdateSubscriptionModifications.tpl"
    ]);

    $this->form->addButtons([
      [
        'type' => 'upload',
        'name' => ts('Confirm'),
        'spacing' => '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;',
        'isDefault' => TRUE,
        'js' => ['onclick' => "return processUpdate(this,'" . $this->form->getName() . "','" . ts('Processing') . "');"],
      ],
      [
        'type' => 'cancel',
        'name' => ts('Cancel'),
      ],
    ]);
  }

}
