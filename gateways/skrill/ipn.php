<?php
    
    /**
     * Skrill IPN
     *
     * @package Wojo Framework
     * @author wojoscripts.com
     * @copyright 2022
     * @version $Id: ipn.php, v1.00 2022-06-08 10:12:05 gewa Exp $
     */
    const _WOJO = true;
    require_once("../../init.php");
    
    /* only for debuggin purpose. Create logfile.txt and chmot to 0777
    ob_start();
    echo '<pre>';
    print_r($_POST);
    echo '</pre>';
    $logInfo = ob_get_contents();
    ob_end_clean();
    
    $file = fopen('logfile.txt', 'a');
    fwrite($file, $logInfo);
    fclose($file);
    */
    
    /* Check for mandatory fields */
    $r_fields = array(
        'status',
        'md5sig',
        'merchant_id',
        'pay_to_email',
        'mb_amount',
        'mb_transaction_id',
        'currency',
        'amount',
        'transaction_id',
        'pay_from_email',
        'mb_currency');
    
    $skrill = Db::Go()->select(Admin::gTable, array("extra3"))->where("name", "skrill", "=")->first()->run();
    
    foreach ($r_fields as $f)
        if (!isset($_POST[$f]))
            die();
    
    /* Check for MD5 signature */
    $md5 = strtoupper(md5($_POST['merchant_id'] . $_POST['transaction_id'] . strtoupper(md5($skrill->extra3)) . $_POST['mb_amount'] . $_POST['mb_currency'] . $_POST['status']));
    if ($md5 != $_POST['md5sig'])
        die();
    
    if (intval($_POST['status']) == 2) {
        $mb_currency = Validator::sanitize($_POST['mb_currency']);
        $mc_gross = $_POST['amount'];
        $txn_id = Validator::sanitize($_POST['mb_transaction_id']);
        
        list($membership_id, $user_id) = explode("_", $_POST['custom']);
        
        $row = Db::Go()->select(Content::msTable)->where("id", intval($membership_id), "=")->first()->run();
        $usr = Db::Go()->select(Users::mTable)->where("id", intval($user_id), "=")->first()->run();
        $cart = Content::getCart($usr->id);
        
        if ($cart) {
            $v1 = Validator::compareNumbers($mc_gross, $cart->totalprice);
        } else {
            $cart = new stdClass;
            $tax = Content::calculateTax(intval($user_id));
            $v1 = Validator::compareNumbers($mc_gross, $row->price, "gte");
            
            $cart->originalprice = $row->price;
            $cart->total = $row->price;
            $cart->totaltax = Validator::sanitize($row->price * $tax, "float");
            $cart->totalprice = Validator::sanitize($tax * $row->price + $row->price, "float");
        }
        
        $data = array(
            'txn_id' => $txn_id,
            'membership_id' => $row->id,
            'user_id' => $usr->id,
            'amount' => $cart->total,
            'coupon' => $cart->coupon,
            'total' => $cart->totalprice,
            'tax' => $cart->totaltax,
            'currency' => strtoupper($mb_currency),
            'ip' => Url::getIP(),
            'pp' => "Skrill",
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
                "Skrill",
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
    } else {
        /* == Failed or Pending Transaction == */
        Debug::AddMessage("errors", '<i>Error</i>', "Failed skrill transaction", "session");
    }