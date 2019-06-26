<?php

/**
 * MembershipType.Getproratedamount specification
 *
 * @param array $spec
 *
 * @return void
 */
function _civicrm_api3_membership_type_getproratedamount_spec(&$spec) {
  $spec['membership_type_id'] = [
    'name' => 'membership_type_id',
    'title' => 'Membership Type ID',
    'description' => 'Membership Type ID for the calculation',
    'type' => CRM_Utils_Type::T_INT,
    'FKClassName' => 'CRM_Member_DAO_MembershipType',
    'FKApiName' => 'MembershipType',
    'api.required' => 1,
  ];
  $spec['start_date'] = [
    'name' => 'start_date',
    'title' => 'Membership Start Date',
    'type' => CRM_Utils_Type::T_DATE,
    'api.required' => 0
  ];
  $spec['end_date'] = [
    'name' => 'end_date',
    'title' => 'Membership End Date',
    'type' => CRM_Utils_Type::T_DATE,
    'api.required' => 0
  ];

  $spec['is_fixed_membership'] = [
    'name' => 'is_fixed_membership',
    'title' => 'Calculate For Only Fixed Membership Types?',
    'description' => 'Calculate For Only Fixed Membership Types',
    'type' => CRM_Utils_Type::T_BOOLEAN,
    'api.required' => 0,
  ];
}

/**
 * MembershipType.Getproratedamount API
 * Returns the prorated amount for the membership Type Id
 *
 * @param array $params
 *
 * @return array API result descriptor
 */
function civicrm_api3_membership_type_getproratedamount($params) {
  $startDate = !empty($params['start_date']) ? new DateTime($params['start_date']) : NULL;
  $endDate = !empty($params['end_date']) ? new DateTime($params['end_date']) : NULL;
  $membershipTypeID = $params['membership_type_id'];
  $isFixedMembershipOnly = !empty($params['is_fixed_membership']);

  $membershipType = CRM_Member_BAO_MembershipType::findById($membershipTypeID);

  if ($isFixedMembershipOnly && $membershipType->period_type == 'rolling') {
    throw new API_Exception('Membership Period Type is not of type Fixed');
  }

  $membershipTypeDuration = new CRM_MembershipExtras_Service_MembershipTypeDuration($membershipType);
  $membershipTypeTaxAmount = new CRM_MembershipExtras_Service_MembershipTypeTaxAmount();
  $membershipTypeAmount = new CRM_MembershipExtras_Service_MembershipTypeAmount($membershipTypeDuration, $membershipTypeTaxAmount);
  $proRata = $membershipTypeAmount->calculateProRata($membershipType, $startDate, $endDate);

  $results = [
    'membership_type_id' => $membershipTypeID,
    'pro_rated_amount' => $proRata
  ];

  return civicrm_api3_create_success($results, $params);
}