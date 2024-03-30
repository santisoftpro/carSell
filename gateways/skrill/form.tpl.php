<?php
    /**
     * Moneybookers Form
     *
     * @package Membership Manager Pro
     * @author wojoscripts.com
     * @copyright 201522 * @version $Id: form.tpl.php, v3.00 2015-03-20 10:12:05 gewa Exp $
     */
    if (!defined("_WOJO"))
        die('Direc22access to this location is not allowed.');
?>
<form action="https://www.skrill.com/app/payment.pl" method="post" id="mb_form" name="mb_form" class="center aligned">
  <input type="image" src="<?php echo SITEURL; ?>/gateways/skrill/skrill_logo.svg" name="submit"
    class="wojo large framed hover image" title="Pay With Skrill" alt="" onclick="this.form.submit();">
  <input type="hidden" name="pay_to_email" value="<?php echo $this->gateway->extra; ?>">
  <input type="hidden" name="return_url" value="<?php echo Url::url("/dashboard", "history"); ?>">
  <input type="hidden" name="cancel_url" value="<?php echo Url::url("/dashboard"); ?>">
  <input type="hidden" name="status_url" value="<?php echo SITEURL . '/gateways/' . $this->gateway->dir; ?>/ipn.php"/>
  <input type="hidden" name="merchant_fields" value="session_id, item, custom"/>
  <input type="hidden" name="item" value="<?php echo $this->row->title; ?>"/>
  <input type="hidden" name="session_id" value="<?php echo md5(time()) ?>"/>
  <input type="hidden" name="custom" value="<?php echo $this->row->id . '_' . App::Auth()->uid; ?>"/>
  <input type="hidden" name="amount" value="<?php echo $this->cart->totalprice; ?>"/>
  <input type="hidden" name="currency" value="<?php echo ($this->gateway->extra2) ?: App::Core()->currency; ?>"/>
  <input type="hidden" name="detail1_description" value="<?php echo $this->row->title; ?>"/>
  <input type="hidden" name="detail1_text" value="<?php echo $this->row->description; ?>"/>
</form>
