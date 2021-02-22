<?php

use CRM_MembershipExtras_Test_Fabricator_Contact as ContactFabricator;
use Civi\Test\HookInterface;
use CRM_MembershipExtras_Test_Fabricator_Membership as MembershipFabricator;
use CRM_MembershipExtras_Test_Fabricator_MembershipType as MembershipTypeFabricator;
use CRM_MembershipExtras_Hook_Pre_MembershipPaymentPlanProcessor_Contribution as MembershipPaymentPlanProcessor;

/**
 * Class CRM_MembershpExtras_Hook_Pre_MembershipPaymentPlanProcessor_ContributionTest
 *
 * @group headless
 */
class CRM_MembershpExtras_Hook_Pre_MembershipPaymentPlanProcessor_ContributionTest extends BaseHeadlessTest implements HookInterface {

  use CRM_MembershipExtras_Test_Helper_FinancialAccountTrait;

  /**
   * Implements calculateContributionReceiveDate hook for testing.
   *
   * @param $instalment
   * @param $receiveDate
   * @param $contributionCreationParams
   */
  public function hook_membershipextras_calculateContributionReceiveDate($instalment, &$receiveDate, &$contributionCreationParams) {
    if (isset($contributionCreationParams['test_receive_date_calculation_hook'])) {
      $receiveDate = $contributionCreationParams['test_receive_date_calculation_hook'];
    }
  }

  public function testMonthlyCycleDayIsCalculatedFromReceiveDate() {
    $_REQUEST['payment_plan_schedule'] = 'monthly';
    $startDate = date('Y-m-27');
    $contact = ContactFabricator::fabricate();
    $membershipType = $this->mockMembershipType('rolling', 'month');
    $membership = $this->mockMembership($contact['id'], $membershipType['id'], $startDate);
    $params = [
      'is_pay_later' => TRUE,
      'skipLineItem' => 1,
      'skipCleanMoney' => TRUE,
      'receive_date' => $startDate,
      'contact_id' => $contact['id'],
      'fee_amount' => 0,
      'net_amount' => "1200",
      'total_amount' => "1200",
      'payment_instrument_id' => $this->getOptionValue('EFT', 'payment_instrument'),
      'financial_type_id' => $this->getFinancialTypeID('Member Dues'),
      'contribution_status_id' => 'Pending',
      'currency' => NULL,
      'is_test' => FALSE,
      'campaign_id' => NULL,
      'membership_id' => $membership['id'],
    ];
    $paymentPlanCreator = new CRM_MembershipExtras_Hook_Pre_MembershipPaymentPlanProcessor_Contribution($params);
    $paymentPlanCreator->createPaymentPlan();
    $recurringContribution = $paymentPlanCreator->getRecurringContribution();
    $recurringContribution = civicrm_api3('ContributionRecur', 'get', [
      'sequential' => 1,
      'id' => $recurringContribution['id'],
      'options' => ['limit' => 0],
    ])['values'][0];

    $this->assertEquals('27', $recurringContribution['cycle_day']);
  }

  public function testYearlyCycleDayIsCalculatedFromReceiveDate() {
    $_REQUEST['payment_plan_schedule'] = 'annual';

    $contact = ContactFabricator::fabricate();
    $startDate = date('2020-02-01');
    $membershipType = $this->mockMembershipType('rolling', 'year');
    $membership = $this->mockMembership($contact['id'], $membershipType['id'], $startDate);
    $params = [
      'is_pay_later' => TRUE,
      'skipLineItem' => 1,
      'skipCleanMoney' => TRUE,
      'receive_date' => '2020-02-01',
      'contact_id' => $contact['id'],
      'fee_amount' => 0,
      'net_amount' => "1200",
      'total_amount' => "1200",
      'payment_instrument_id' => $this->getOptionValue('EFT', 'payment_instrument'),
      'financial_type_id' => $this->getFinancialTypeID('Member Dues'),
      'contribution_status_id' => 'Pending',
      'currency' => NULL,
      'is_test' => FALSE,
      'campaign_id' => NULL,
      'membership_id' => $membership['id'],
    ];
    $paymentPlanCreator = new CRM_MembershipExtras_Hook_Pre_MembershipPaymentPlanProcessor_Contribution($params);
    $paymentPlanCreator->createPaymentPlan();
    $recurringContribution = $paymentPlanCreator->getRecurringContribution();
    $recurringContribution = civicrm_api3('ContributionRecur', 'get', [
      'sequential' => 1,
      'id' => $recurringContribution['id'],
      'options' => ['limit' => 0],
    ])['values'][0];

    $this->assertEquals('32', $recurringContribution['cycle_day']);
  }

  public function testReceiveDateCalculationHookChangesReceiveDate() {
    $_REQUEST['payment_plan_schedule'] = 'monthly';

    $contact = ContactFabricator::fabricate();
    $startDate = date('Y-01-01');
    $membershipType = $this->mockMembershipType('rolling', 'month');
    $membership = $this->mockMembership($contact['id'], $membershipType['id'], $startDate);
    $newReceiveDate = date('Y-m-27');
    $params = [
      'is_pay_later' => TRUE,
      'skipLineItem' => 1,
      'skipCleanMoney' => TRUE,
      'receive_date' => $startDate,
      'contact_id' => $contact['id'],
      'fee_amount' => 0,
      'net_amount' => "1200",
      'total_amount' => "1200",
      'payment_instrument_id' => $this->getOptionValue('EFT', 'payment_instrument'),
      'financial_type_id' => $this->getFinancialTypeID('Member Dues'),
      'contribution_status_id' => 'Pending',
      'currency' => NULL,
      'is_test' => FALSE,
      'campaign_id' => NULL,
      'test_receive_date_calculation_hook' => $newReceiveDate,
      'membership_id' => $membership['id'],
    ];
    $paymentPlanCreator = new CRM_MembershipExtras_Hook_Pre_MembershipPaymentPlanProcessor_Contribution($params);
    $paymentPlanCreator->createPaymentPlan();
    $recurringContribution = $paymentPlanCreator->getRecurringContribution();
    $recurringContribution = civicrm_api3('ContributionRecur', 'get', [
      'sequential' => 1,
      'id' => $recurringContribution['id'],
      'options' => ['limit' => 0],
    ])['values'][0];

    $this->assertEquals($newReceiveDate . ' 00:00:00', $recurringContribution['start_date']);
    $this->assertEquals('27', $recurringContribution['cycle_day']);
  }

  /**
   * Tests create payment plan with month duration
   * for rolling membership type with monthly schedule
   */
  public function testCreatePaymentPlanWithMonthDurationRollingMembershipTypeWithMonthlySchedule() {
    $_REQUEST['payment_plan_schedule'] = 'monthly';
    $params = $this->mockFormParams('rolling', 'month');
    $processor = new MembershipPaymentPlanProcessor($params);
    $processor->createPaymentPlan();
    $createdPaymentPlan = $this->getCreatedPaymentPlan($processor);
    $this->assertEquals(1, $createdPaymentPlan['installments']);
    $this->assertEquals($params['total_amount'], $createdPaymentPlan['amount']);
  }

  /**
   * Tests create payment plan with year duration
   * for rolling membership type with monthly schedule
   */
  public function testCreatePaymentPlanWithYearDurationRollingMembershipTypeWithMonthlySchedule() {
    $_REQUEST['payment_plan_schedule'] = 'monthly';
    $params = $this->mockFormParams('rolling', 'year');
    $processor = new MembershipPaymentPlanProcessor($params);
    $processor->createPaymentPlan();
    $createdPaymentPlan = $this->getCreatedPaymentPlan($processor);
    $this->assertEquals(12, $createdPaymentPlan['installments']);
    $this->assertEquals($params['total_amount'], $createdPaymentPlan['amount']);
  }

  /**
   * Tests create payment plan with year duration
   * for rolling membership type with quarterly schedule
   */
  public function testCreatePaymentPlanWithYearDurationRollingMembershipTypeAndQuarterlySchedule() {
    $_REQUEST['payment_plan_schedule'] = 'quarterly';
    $params = $this->mockFormParams('rolling', 'year');
    $processor = new MembershipPaymentPlanProcessor($params);
    $processor->createPaymentPlan();
    $createdPaymentPlan = $this->getCreatedPaymentPlan($processor);
    $this->assertEquals(4, $createdPaymentPlan['installments']);
    $this->assertEquals($params['total_amount'], $createdPaymentPlan['amount']);
  }

  /**
   * Tests create payment plan with year duration
   * for rolling membership type with annual schedule
   */
  public function testCreatePaymentPlanWithYearDurationRollingMembershipTypeAndAnnualSchedule() {
    $_REQUEST['payment_plan_schedule'] = 'annual';
    $params = $this->mockFormParams('rolling', 'year');
    $processor = new MembershipPaymentPlanProcessor($params);
    $processor->createPaymentPlan();
    $createdPaymentPlan = $this->getCreatedPaymentPlan($processor);
    $this->assertEquals(1, $createdPaymentPlan['installments']);
    $this->assertEquals($params['total_amount'], $createdPaymentPlan['amount']);
  }

  /**
   * Tests create payment plan with year duration
   * for fixed membership type with monthly schedule
   */
  public function testCreatePaymentPlanWithYearDurationFixedMembershipTypeAndMonthlySchedule() {
    $_REQUEST['payment_plan_schedule'] = 'monthly';
    $params = $this->mockFormParams('fixed', 'year');
    $processor = new MembershipPaymentPlanProcessor($params);
    $processor->createPaymentPlan();
    $createdPaymentPlan = $this->getCreatedPaymentPlan($processor);
    $this->assertEquals(12, $createdPaymentPlan['installments']);
    $this->assertEquals($params['total_amount'], $createdPaymentPlan['amount']);
  }

  /**
   * Tests create payment plan with year duration
   * for fixed membership type with annual schedule
   */
  public function testCreatePaymentPlanWithYearDurationFixMembershipTypeAndAnnualSchedule() {
    $_REQUEST['payment_plan_schedule'] = 'annual';
    $params = $this->mockFormParams('fixed', 'year');
    $processor = new MembershipPaymentPlanProcessor($params);
    $processor->createPaymentPlan();
    $createdPaymentPlan = $this->getCreatedPaymentPlan($processor);
    $this->assertEquals(1, $createdPaymentPlan['installments']);
    $this->assertEquals($params['total_amount'], $createdPaymentPlan['amount']);
  }

  /**
   * Tests if the recurring start date is altered as per membeship start date
   */
  public function testAlterReceiveDate() {
    $_REQUEST['payment_plan_schedule'] = 'monthly';
    $params = $this->mockFormParams('rolling', 'year', date('Y-01-01'));
    //mock receive date as of today
    $params['receive_date'] = date('Y-m-d');
    $processor = new MembershipPaymentPlanProcessor($params);
    $processor->createPaymentPlan();
    $createdPaymentPlan = $this->getCreatedPaymentPlan($processor);
    //civicrm returns YmdHis format by default, so we mock date as per civicrm format
    $expectedDate = date('Y0101000000');
    $this->assertEquals($expectedDate, $createdPaymentPlan['start_date']);
  }

  /**
   * Get payment plan from reflection object
   *
   * @param CRM_MembershipExtras_Hook_Pre_MembershipPaymentPlanProcessor_Contribution $processor
   * @return mixed
   */
  private function getCreatedPaymentPlan(MembershipPaymentPlanProcessor $processor) {
    return $processor->getRecurringContribution();
  }

  /**
   * Mock contribution params as the membership form is submitted.
   *
   * @param $membershipPeriodType
   * @param $durationUnit
   * @param null $startDate
   *
   * @return array
   * @throws CiviCRM_API3_Exception
   */
  private function mockFormParams($membershipPeriodType, $durationUnit, $startDate = NULL) {
    $this->mockSalesTaxFinancialAccount();
    $contact = ContactFabricator::fabricate();
    if (is_null($startDate)) {
      $startDate = date('Y-m-d');
    }
    $membershipType = $this->mockMembershipType($membershipPeriodType, $durationUnit);
    $membership = $this->mockMembership($contact['id'], $membershipType['id'], $startDate);
    $financialTypeId = $this->getFinancialTypeID('Member Dues');
    $taxRates = CRM_Core_PseudoConstant::getTaxRates();
    $rate = CRM_Utils_Array::value($financialTypeId, $taxRates, 0);
    $taxAmount  = ($membershipType['minimum_fee'] * $rate) / 100;
    $totalAmount = $membershipType['minimum_fee'] + $taxAmount;
    return [
      'currency' => 'GBP',
      'receipt_date' => NULL,
      'source' => NULL,
      'non_deductible_amount' => 0,
      'skipCleanMoney' => 1,
      'payment_processor' => NULL,
      'contact_id' => $contact['id'],
      'fee_amount' => 0,
      'total_amount' => $totalAmount,
      'receive_date' => $startDate,
      'financial_type_id' => $financialTypeId,
      'payment_instrument_id' => 4,
      'trxn_id' => NULL,
      'invoice_id' => NULL,
      'is_test' => NULL,
      'contribution_status_id]' => 2,
      'check_number' => NULL,
      'campaign_id' => NULL,
      'is_pay_later' => 1,
      'membership_id' => $membership['id'],
      'tax_amount' => $taxAmount,
      'skipLineItem' => 1,
      'contribution_recur_id' => NULL,
      'pan_truncation' => NULL,
      'card_type_id' => NULL,
    ];
  }

  private function mockMembershipType($membershipPeriodType, $durationUnit) {
    return MembershipTypeFabricator::fabricate([
      'name' => 'Mock Membership type',
      'period_type' => $membershipPeriodType,
      'minimum_fee' => 120,
      'duration_interval' => 1,
      'duration_unit' => $durationUnit,
      //01 Oct
      'fixed_period_start_day' => 1001,
      // 30 Sep
      'fixed_period_rollover_day' => 930,
    ]);
  }

  private function mockMembership($contactID, $membershipTypeID, $startDate) {
    return MembershipFabricator::fabricate([
      'contact_id' => $contactID,
      'membership_type_id' => $membershipTypeID,
      'join_date' => $startDate,
      'start_date' => $startDate,
    ]);
  }

  /**
   * Obtains value for the given name option in the option group.
   *
   * @param string $name
   * @param string $group
   *
   * @return array|string
   * @throws \CiviCRM_API3_Exception
   */
  private function getOptionValue($name, $group) {
    return civicrm_api3('OptionValue', 'getvalue', [
      'return' => 'value',
      'option_group_id' => $group,
      'name' => $name,
    ]);
  }

  /**
   * Obtains ID for the given financial type name.
   *
   * @param $financialType
   *
   * @return int|array
   * @throws \CiviCRM_API3_Exception
   */
  private function getFinancialTypeID($financialType) {
    return civicrm_api3('FinancialType', 'getvalue', [
      'return' => 'id',
      'name' => $financialType,
    ]);
  }

}
