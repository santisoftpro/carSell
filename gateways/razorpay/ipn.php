<?php
    /**
     * RazorPay IPN
     *
     * @package CMS Pro
     * @author wojoscripts.com
     * @copyright 2022
     * @version $Id: ipn.php, 2022-11-14 21:12:05 gewa Exp $
     */
    const _WOJO = true;
    require_once("../../init.php");
    
    if (!App::Auth()->is_User())
        exit;
    
    ini_set('log_errors', true);
    ini_set('error_log', dirname(__file__) . '/ipn_errors.log');
    
    require 'lib/Razorpay.php';
    
    use Razorpay\Api\Api;
    use Razorpay\Api\Errors\SignatureVerificationError;
    
    if (isset($_POST['razorpay_payment_id'])) {
        $rules = array(
            'razorpay_signature' => array('required|string', "Invalid Signature"),
            'razorpay_payment_id' => array('required|string', "Invalid Payment Id"),
        );
        
        $validate = Validator::instance();
        $safe = $validate->doValidate($_POST, $rules);
        
        if (!$cart = Content::getCart()) {
            Message::$msgs['cart'] = Lang::$word->FRONT_ERROR02;
        }
        
        if (empty(Message::$msgs)) {
            $apikey = Db::Go()->select(Admin::gTable, array("extra", "extra2", "extra3"))->where("name", "razorpay", "=")->first()->run();
            $api = new Api($apikey->extra, $apikey->extra3);
            
            try {
                $attributes = array(
                    'razorpay_order_id' => $cart->order_id,
                    'razorpay_payment_id' => $_POST['razorpay_payment_id'],
                    'razorpay_signature' => $_POST['razorpay_signature']);
                
                $api->utility->verifyPaymentSignature($attributes);
                
                // insert payment record
                $row = Db::Go()->select(Content::msTable)->where("id", $cart->membership_id, "=")->first()->run();
                $data = array(
                    'txn_id' => $safe->razorpay_payment_id,
                    'membership_id' => $row->id,
                    'user_id' => App::Auth()->uid,
                    'amount' => $cart->total,
                    'coupon' => $cart->coupon,
                    'total' => $cart->totalprice,
                    'tax' => $cart->totaltax,
                    'currency' => $apikey->extra2,
                    'ip' => Url::getIP(),
                    'pp' => "RazorPay",
                    'status' => 1,
                );
                $last_id = Db::Go()->insert(Content::txTable, $data)->run();
                
                //insert user membership
                $udata = array(
                    'transaction_id' => $last_id,
                    'user_id' => App::Auth()->uid,
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
                Db::Go()->update(Users::mTable, $xdata)->where("id", App::Auth()->uid, "=")->run();
                
                Db::Go()->delete(Content::xTable)->where("user_id", App::Auth()->uid, "=")->run();
                
                //update membership status
                App::Auth()->membership_id = App::Session()->set('membership_id', $row->id);
                App::Auth()->mem_expire = App::Session()->set('mem_expire', $xdata['mem_expire']);
                
                $json['type'] = 'success';
                $json['title'] = Lang::$word->SUCCESS;
                $json['message'] = Lang::$word->HOME_POK1;
                print json_encode($json);
                
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
                        App::Auth()->name,
                        $row->title,
                        $data['total'],
                        "RazorPay",
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
                        '[PP]',
                        '[FB]',
                        '[TW]'), array(
                        Utility::getLogo(),
                        $core->site_email,
                        $core->company,
                        date('Y'),
                        SITEURL,
                        App::Auth()->name,
                        $row->title,
                        $data['total'],
                        "RazorPay",
                        Url::getIP(),
                        $core->social->facebook,
                        $core->social->twitter), $uhtml_message);
                    
                    $umailer->setFrom($core->site_email, $core->company);
                    $umailer->addAddress(App::Auth()->email, App::Auth()->name);
                    
                    $umailer->isHTML();
                    $umailer->Subject = $subject;
                    $umailer->Body = $ubody;
                    
                    $umailer->send();
                } catch (\PHPMailer\PHPMailer\Exception $e) {
                    Debug::AddMessage("errors", '<i>Error</i>', $e->getMessage(), "session");
                }
                
            } catch (SignatureVerificationError $e) {
                $json['type'] = 'error';
                $json['title'] = Lang::$word->ERROR;
                $json['message'] = $e->getMessage();
                print json_encode($json);
            }
        } else {
            Message::msgSingleStatus();
        }
    }