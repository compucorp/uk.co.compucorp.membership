<?php

use CRM_Member_BAO_MembershipType as MembershipType;

class CRM_MembershipExtras_Service_MembershipTypeDates {

  /**
   * Gets the membership Start, End and Join dates based on parameters provided.
   *
   * @param membershipType $membershipType
   * @param \DateTime|NULL $startDate
   * @param \DateTime|NULL $endDate
   * @param \DateTime|NULL $joinDate
   *
   * @return array
   */
  public function getDatesForMembershipType(MembershipType $membershipType, DateTime $startDate = NULL, DateTime $endDate = NULL, DateTime $joinDate = NULL) {
    $startDate = empty($startDate) ? $joinDate : $startDate;
    $membershipDates = MembershipType::getDatesForMembershipType(
      $membershipType->id,
      $joinDate ? $joinDate->format('Y-m-d'): NULL,
      $startDate ? $startDate->format('Y-m-d'): NULL,
      $endDate ? $endDate->format('Y-m-d') : NULL
    );

    return $membershipDates;
  }
}
