{include file="CRM/common/paymentBlock.tpl"}
{crmScript ext=uk.co.compucorp.membershipextras file=js/UpdateSubscriptionModifications.js}
<table id="additional_fields">
  <tr id="payment_instrument_id_field">
    <td class="label">
      {$form.payment_instrument_id.label}
    </td>
    <td>
      {$form.payment_instrument_id.html}
      <input type="hidden" name="old_payment_instrument_id" id="old_payment_instrument_id"
             value="{$form.payment_instrument_id.value.0}"/>
      <input type="hidden" name="update_installments" id="update_installments" value="0"/>
    </td>
  </tr>
  <tr id="cycle_day_field">
    <td class="label">
      {$form.cycle_day.label}
    </td>
    <td>
      {$form.cycle_day.html}
      <input type="hidden" name="old_cycle_day" id="old_cycle_day" value="{$form.cycle_day.value}"/>
    </td>
  </tr>
  <tr id="autorenew_field">
    <td class="label">
      {$form.auto_renew.label}
    </td>
    <td>
      {$form.auto_renew.html}
    </td>
  </tr>
  <tr id="billing_optional_fields" class="crm-membership-form-block-billing">
    <td colspan="2">
      <div id="billing-payment-block" class="crm-ajax-container"></div>
    </td>
  </tr>
</table>
<div id="confirmInstallmentsUpdate" style="display: none;">
  <div class="messages status no-popup">
    <i aria-hidden="true" class="crm-i fa-info-circle"></i>{ts}Do you want to update any outstanding instalment
      contribution with the new Payment Method or Cycle Day?{/ts}
  </div>
</div>
