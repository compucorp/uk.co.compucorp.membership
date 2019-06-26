<?php

/**
 * MembershipType.getinstalmentamountsforpriceset API specification
 *
 * @param array $spec
 *
 * @return void
 */
function _civicrm_api3_membership_type_getinstalmentamountsforpriceset_spec(&$spec) {
  $spec['price_field_value_id'] = [
    'name' => 'price_field_value_id',
    'title' => 'Price Field Value ID',
    'description' => 'Price Field Value ID for the calculation',
    'type' => CRM_Utils_Type::T_INT,
    'FKClassName' => 'CRM_Price_DAO_PriceFieldValue',
    'FKApiName' => 'PriceFieldValue',
    'api.required' => 1,
  ];
  $spec['start_date'] = [
    'name' => 'start_date',
    'title' => 'Membership Start Date',
    'type' => CRM_Utils_Type::T_DATE,
    'api.required' => 1
  ];
  $spec['end_date'] = [
    'name' => 'end_date',
    'title' => 'Membership End Date',
    'type' => CRM_Utils_Type::T_DATE,
    'api.required' => 1
  ];
}

/**
 * MembershipType.GetInstalmentAmountsForPriceSet API
 * Returns the First Instalment and Following instalment amounts for the price field
 * values of a price set.
 *
 * @param array $params
 *
 * @return array API result descriptor
 */
function civicrm_api3_membership_type_getinstalmentamountsforpriceset($params) {
  $startDate = new DateTime($params['start_date']);
  $endDate = new DateTime($params['end_date']);
  $priceFieldValueIds = _civicrm_api3_membership_type_getPriceFieldValueIdsFromParams($params);
  $priceFieldValueItems = _civicrm_api3_membership_type_getPriceFieldValueItems($priceFieldValueIds);
  $membershipTypes = [];

  foreach ($priceFieldValueItems as $priceFieldValue) {
    if (empty($priceFieldValue['membership_type_id'])) {
      throw new Exception('All price field items must be of type membership');
    }

    $membershipType = CRM_Member_BAO_MembershipType::findById($priceFieldValue['membership_type_id']);
    $membershipType->minimum_fee = $priceFieldValue['amount'];
    $membershipType->financial_type_id = $priceFieldValue['financial_type_id'];
    $membershipTypes[] = $membershipType;
  }
  $membershipTypeTaxAmount = new CRM_MembershipExtras_Service_MembershipTypeTaxAmount();
  $membershipTypeInstalment = new CRM_MembershipExtras_Service_MembershipTypeInstalmentAmount(
    $membershipTypes,
    $membershipTypeTaxAmount,
    $startDate,
    $endDate
  );
  $results = [
    'fi_amount' => $membershipTypeInstalment->calculateFirstInstalmentAmount(),
    'foi_amount' => $membershipTypeInstalment->calculateFollowingInstalmentAmount()
  ];

  return civicrm_api3_create_success($results, $params);
}

/**
 * Returns the Price field value Items fetched from the API.
 *
 * @param array $priceFieldValues
 *
 * @return array
 */
function _civicrm_api3_membership_type_getPriceFieldValueItems(array $priceFieldValues) {
  $result = civicrm_api3('PriceFieldValue', 'get', [
    'sequential' => 1,
    'id' => ['IN' => $priceFieldValues],
  ]);

  return $result['values'];
}

/**
 * Extracts the list of Price Field value Id's from the $params array
 *
 * Currently, the API only supports the IN operator for passing an array of price value Id's.
 * Supporting other operators would be extremely complex and it would not even
 * make sense to support operators like >= and <.
 *
 * @param array $params
 *   The $params array passed to the MembershipType.GetInstalmentAmountsForPriceSet API
 *
 * @return array
 */
function _civicrm_api3_membership_type_getPriceFieldValueIdsFromParams($params) {
  if (!is_array($params['price_field_value_id'])) {
    return [$params['price_field_value_id']];
  }

  if (!array_key_exists('IN', $params['price_field_value_id'])) {
    throw new InvalidArgumentException('The price_field_value_id parameter only supports the IN operator');
  }

  return $params['price_field_value_id']['IN'];
}