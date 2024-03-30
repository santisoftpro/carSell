<?php
    /**
     * PayFast IPN
     *
     * @package Wojo Framework
     * @author wojoscripts.com
     * @copyright 2022
     * @version $Id: ipn.php, 2022-08-30 21:12:05 gewa Exp $
     */
    const _WOJO = true;
    
    ini_set('log_errors', true);
    ini_set('error_log', dirname(__file__) . '/ipn_errors.log');
    
    include_once 'pf.inc.php';
    
    if (isset($_POST['payment_status'])) {
        require_once("../../init.php");
        
        $pf = Db::Go()->select(Admin::gTable, array("live", "extra2"))->where("name", "payfast", "=")->first()->run();
        $pfHost = ($pf->live) ? 'https://www.payfast.co.za' : 'https://sandbox.payfast.co.za';
        $error = false;
        
        pflog('ITN received from payfast.co.za');
        if (!pfValidIP($_SERVER['REMOTE_ADDR'])) {
            pflog('REMOTE_IP mismatch: ');
            $error = true;
            return false;
        }
        
        $data = pfGetData();
        
        pflog('POST received from payfast.co.za: ' . print_r($data, true));
        
        if ($data === false) {
            pflog('POST is empty: ' . print_r($data, true));
            $error = true;
            return false;
        }
        
        if (!pfValidSignature($data, $pf->extra3)) {
            pflog('Signature mismatch on POST');
            $error = true;
            return false;
        }
        
        pflog('Signature OK');
        
        $itnPostData = array();
        $itnPostDataValuePairs = array();
        
        foreach ($_POST as $key => $value) {
            if ($key == 'signature')
                continue;
            
            $value = urlencode(stripslashes($value));
            $value = preg_replace('/(.*[^%^0D])(%0A)(.*)/i', '${1}%0D%0A${3}', $value);
            $itnPostDataValuePairs[] = "$key=$value";
        }
        
        $itnVerifyRequest = implode('&', $itnPostDataValuePairs);
        if (!pfValidData($pfHost, $itnVerifyRequest, "$pfHost/eng/query/validate")) {
            pflog("ITN mismatch for $itnVerifyRequest\n");
            pflog('ITN not OK');
            $error = true;
            return false;
        }
        
        pflog('ITN OK');
        pflog("ITN verified for $itnVerifyRequest\n");
        
        if ($_POST['payment_status'] == "COMPLETE") {
            $user_id = intval($_POST['custom_int1']);
            $mc_gross = $_POST['amount_gross'];
            $membership_id = $_POST['m_payment_id'];
            $txn_id = Validator::sanitize($_POST['pf_payment_id']);
            
            $row = Db::Go()->select(Content::msTable)->where("id", intval($membership_id), "=")->first()->run();
            $usr = Db::Go()->select(Users::mTable)->where("id", $user_id, "=")->first()->run();
            
            $cart = Content::getCart($usr->id);
            
            if ($cart) {
                $v1 = Validator::compareNumbers($mc_gross, $cart->totalprice);
            } else {
                $cart = new stdClass;
                $tax = Content::calculateTax($user_id);
                $v1 = Validator::compareNumbers($mc_gross, $row->price, "gte");
                
                $cart->originalprice = $row->price;
                $cart->total = $row->price;
                $cart->totaltax = Validator::sanitize($row->price * $tax, "float");
                $cart->totalprice = Validator::sanitize($tax * $row->price + $row->price, "float");
            }
            
            if ($v1) {
                $data = array(
                    'txn_id' => $txn_id,
                    'membership_id' => $row->id,
                    'user_id' => $usr->id,
                    'amount' => $cart->total,
                    'coupon' => $cart->coupon,
                    'total' => $cart->totalprice,
                    'tax' => $cart->totaltax,
                    'currency' => "ZAR",
                    'ip' => Url::getIP(),
                    'pp' => "PayFast",
                    'status' => 1,
                );
                
                $last_id = Db::Go()->insert(Content::txTable, $data)->run();
                
                //insert user membership
                $udata = array(
                    'transaction_id' => $last_id,
                    'user_id' => $usr->id,
                    'membership_id' => $row->id,
                    'expire' => Date::calculateDays($row->id),
                    'recurring' => 0,
                    'active' => 1,
                );
                
                //update user record
                $xdata = array(
                    'membership_id' => $row->id,
                    'membership_expire' => $udata['expire'],
                );
                
                Db::Go()->insert(Content::mhTable, $udata);
                Db::Go()->update(Users::mTable, $xdata)->where("id", $usr->uid, "=")->run();
                
                Db::Go()->delete(Content::xTable)->where("user_m_id", $usr->uid, "=")->run();
                
                /* == Notify Administrator == */
                $core = App::Core();
                $subject = Lang::$word->HOME_POK1;
                try {
                    $mailer = Mailer::sendMail();
                    $html_message = Utility::getSnippets(BASEPATH . 'mailer/' . $core->lang . '/Payment_Completed_Admin.tpl.php');
                    $body = str_replace(array(
                        '[LOGO]',
                        '[CEMAIL]',
                        '[COMPANY]',
                        '[DATE]',
                        '[SITEURL]',
                        '[NAME]',
                        '[PACKAGE]',
                        '[PRICE]',
                        '[PP]',
                        '[IP]',
                        '[FB]',
                        '[TW]'), array(
                        Utility::getLogo(),
                        $core->site_email,
                        $core->company,
                        date('Y'),
                        SITEURL,
                        $usr->fname . ' ' . $usr->lname,
                        $row->title,
                        $data['total'],
                        "PayPal",
                        Url::getIP(),
                        $core->social->facebook,
                        $core->social->twitter), $html_message);
                    
                    $mailer->setFrom($core->site_email, $core->company);
                    
                    $mailer->addAddress($core->site_email, $core->company);
                    
                    $mailer->isHTML();
                    $mailer->Subject = $subject;
                    $mailer->Body = $body;
                    
                    $mailer->send();
                    
                    pflog("Email Notification [Admin] sent successfully");
                } catch (\PHPMailer\PHPMailer\Exception $e) {
                    Debug::AddMessage("errors", '<i>Error</i>', $e->getMessage(), "session");
                }
                
                /* == Notify User == */
                try {
                    $umailer = Mailer::sendMail();
                    $uhtml_message = Utility::getSnippets(BASEPATH . 'mailer/' . $core->lang . '/Payment_Completed_User.tpl.php');
                    
                    $ubody = str_replace(array(
                        '[LOGO]',
                        '[CEMAIL]',
                        '[COMPANY]',
                        '[DATE]',
                        '[SITEURL]',
                        '[NAME]',
                        '[PACKAGE]',
                        '[PRICE]',
                        '[TAX]',
                        '[PP]',
                        '[FB]',
                        '[TW]'), array(
                        Utility::getLogo(),
                        $core->company,
                        date('Y'),
                        SITEURL,
                        $usr->fname . ' ' . $usr->lname,
                        $row->title,
                        $data['total'],
                        $data['tax'],
                        "PayFast",
                        Url::getIP(),
                        $core->social->facebook,
                        $core->social->twitter), $uhtml_message);
                    
                    $umailer->setFrom($core->site_email, $core->company);
                    $umailer->addAddress($usr->email, $usr->fname . ' ' . $usr->lname);
                    
                    $umailer->isHTML();
                    $umailer->Subject = $subject;
                    $umailer->Body = $ubody;
                    
                    $umailer->send();
                    pflog("Email Notification [User] sent successfully");
                } catch (\PHPMailer\PHPMailer\Exception $e) {
                    Debug::AddMessage("errors", '<i>Error</i>', $e->getMessage(), "session");
                }
            }
        } else {
            /* == Failed or Pending Transaction == */
            pflog("Transaction failed");
        }
    }