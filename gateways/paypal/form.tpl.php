<?php
    /**
     * Paypal Form
     *
     * @package Wojo Framework
     * @author wojoscripts.com
     * @copyright 2022
     * @version $Id: form.tpl.php, v1.00 2022-10-05 10:12:05 gewa Exp $
     */
    if (!defined("_WOJO"))
        die('Direct access to this location is not allowed.');
?>
<?php $url = ($this->gateway->live) ? 'www.paypal.com' : 'www.sandbox.paypal.com'; ?>
<form action="https://<?php echo $url; ?>/cgi-bin/webscr" method="post" id="pp_form" name="pp_form"
  class="center aligned">
  <input type="image" src="<?php echo SITEURL; ?>/gateways/paypal/paypal_logo.svg" name="submit"
    class="wojo large framed hover image" title="Pay With Paypal" alt="" onclick="document.pp_form.submit();">
  <input type="hidden" name="cmd" value="_xclick"/>
  <input type="hidden" name="amount" value="<?php echo $this->cart->totalprice; ?>">
  <input type="hidden" name="business" value="<?php echo $this->gateway->extra; ?>">
  <input type="hidden" name="item_name" value="<?php echo $this->row->title; ?>">
  <input type="hidden" name="item_number" value="<?php echo $this->row->id . '_' . App::Auth()->uid; ?>">
  <input type="hidden" name="return" value="<?php echo Url::url("/dashboard", "history"); ?>">
  <input type="hidden" name="rm" value="2"/>
  <input type="hidden" name="notify_url" value="<?php echo SITEURL . '/gateways/' . $this->gateway->dir; ?>/ipn.php">
  <input type="hidden" name="cancel_return" value="<?php echo Url::url("/dashboard"); ?>">
  <input type="hidden" name="no_note" value="1"/>
  <input type="hidden" name="currency_code" value="<?php echo ($this->gateway->extra2) ?: App::Core()->currency; ?>">
</form>