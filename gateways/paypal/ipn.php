<?php
    /**
     * PayPal IPN
     *
     * @package Wojo Framework
     * @author wojoscripts.com
     * @copyright 2022
     * @version $Id: ipn.php, v1.00 2022-04-08 10:12:05 gewa Exp $
     */
    const _WOJO = true;
    
    ini_set('log_errors', true);
    ini_set('error_log', dirname(__file__) . '/ipn_errors.log');
    
    if (isset($_POST['payment_status'])) {
        require_once("../../init.php");
        require_once("paypal.class.php");
        
        $pp = Db::Go()->select(Admin::gTable, array("live", "extra"))->where("name", "paypal", "=")->first()->run();
        
        $listener = new IpnListener();
        $listener->use_live = $pp->live;
        $listener->use_curl = true;
        
        try {
            $listener->requirePostMethod();
            $verify = $listener->processIpn();
        } catch (exception $e) {
            error_log('Process IPN failed: ' . $e->getMessage() . " [" . $_SERVER['REMOTE_ADDR'] . "] \n" . $listener->getResponse(), 3, "pp_errorlog.log");
            exit;
        }
        
        $payment_status = $_POST['payment_status'];
        $receiver_email = $_POST['receiver_email'];
        $mc_currency = Validator::sanitize($_POST['mc_currency']);
        list($membership_id, $user_id) = explode("_", $_POST['item_number']);
        $mc_gross = $_POST['mc_gross'];
        $txn_id = isset($_POST['txn_id']) ? Validator::sanitize($_POST['txn_id']) : time();
        
        $row = Db::Go()->select(Content::msTable)->where("id", intval($membership_id), "=")->first()->run();
        $usr = Db::Go()->select(Users::mTable)->where("id", intval($user_id), "=")->first()->run();
        
        $cart = Content::getCart($usr->id);
        
        if ($cart) {
            $v1 = Validator::compareNumbers($mc_gross, $cart->totalprice);
        } else {
            $cart = new stdClass;
            $tax = Content::calculateTax($usr->id);
            $v1 = Validator::compareNumbers($mc_gross, $row->price, "gte");
            
            $cart->originalprice = $row->price;
            $cart->total = $row->price;
            $cart->totaltax = Validator::sanitize($row->price * $tax, "float");
            $cart->totalprice = Validator::sanitize($tax * $row->price + $row->price, "float");
        }
        
        if ($verify) {
            if ($_POST['payment_status'] == 'Completed') {
                if ($row and $v1 and $receiver_email == $pp->extra) {
                    $data = array(
                        'txn_id' => $txn_id,
                        'membership_id' => $row->id,
                        'user_id' => $usr->id,
                        'amount' => $cart->total,
                        'coupon' => $cart->coupon,
                        'total' => $cart->totalprice,
                        'tax' => $cart->totaltax,
                        'currency' => strtoupper($mc_currency),
                        'ip' => Url::getIP(),
                        'pp' => "PayPal",
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
                    
                    Db::Go()->insert(Content::mhTable, $udata)->run();
                    Db::Go()->update(Users::mTable, $xdata)->where("id", $usr->id, "=")->run();
                    
                    Db::Go()->delete(Content::xTable)->where("user_id", $usr->id, "=")->run();
                    
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
                            $core->site_email,
                            $core->company,
                            date('Y'),
                            SITEURL,
                            $usr->fname . ' ' . $usr->lname,
                            $row->title,
                            $data['total'],
                            $data['tax'],
                            "PayPal",
                            Url::getIP(),
                            $core->social->facebook,
                            $core->social->twitter), $uhtml_message);
                        
                        $umailer->setFrom($core->site_email, $core->company);
                        $umailer->addAddress($usr->email, $usr->fname . ' ' . $usr->lname);
                        
                        $umailer->isHTML();
                        $umailer->Subject = $subject;
                        $umailer->Body = $ubody;
                        
                        $umailer->send();
                    } catch (\PHPMailer\PHPMailer\Exception $e) {
                        Debug::AddMessage("errors", '<i>Error</i>', $e->getMessage(), "session");
                    }
                }
            } else {
                /* == Failed Transaction= = */
                error_log("PayPal payment_status not completed", 3, "pp_errorlog.log");
            }
        }
    }