<?php
    /**
     * PP Listener Class
     *
     * @package CMS Pro
     * @author wojoscripts.com
     * @copyright 2022
     * @version $Id: class_security.php, v2.00 2022-04-20 18:20:24 gewa Exp $
     */
    
    if (!defined("_VALID_PHP"))
        die('Direct access to this location is not allowed.');
    
    class IpnListener
    {
        
        /**
         *  If true, the recommended cURL PHP library is used to send the post back
         *  to PayPal. If flase then fsockopen() is used. Default true.
         *
         * @var boolean
         */
        public $use_curl = true;
        
        
        /**
         *  If true, cURL will use the CURLOPT_FOLLOWLOCATION to follow any
         *  "Location: ..." headers in the response.
         *
         * @var boolean
         */
        public $follow_location = false;
        
        /**
         *  If true, the paypal live URI paypal.com is used for the
         *  post back. If false, the sandbox URI sandbox.paypal.com is used. Default false.
         *
         * @var boolean
         */
        public $use_live = false;
        
        /**
         *  The amount of time, in seconds, to wait for the PayPal server to respond
         *  before timing out. Default 30 seconds.
         *
         * @var int
         */
        public $timeout = 30;
        
        private $post_data = array();
        private $post_uri = '';
        private $response_status = '';
        private $response = '';
        
        const PAYPAL_HOST = 'www.paypal.com';
        const SANDBOX_HOST = 'www.sandbox.paypal.com';
        
        /**
         * curlPost()
         *
         * @param $encoded_data
         * @return void
         * @throws Exception
         */
        protected function curlPost($encoded_data)
        {
            
            $uri = 'https://' . $this->getPaypalHost() . '/cgi-bin/webscr';
            $this->post_uri = $uri;
            
            $ch = curl_init();
            
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            //curl_setopt($ch, CURLOPT_CAINFO, dirname(__file__) . "/cert/cacert.pem");
            curl_setopt($ch, CURLOPT_URL, $uri);
            curl_setopt($ch, CURLOPT_USERAGENT, App::Core()->company);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Connection: Close', 'User-Agent: ' . App::Core()->company));
            curl_setopt($ch, CURLOPT_POSTFIELDS, $encoded_data);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, $this->follow_location);
            curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_FORBID_REUSE, 1);
            curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
            
            $this->response = curl_exec($ch);
            $this->response_status = strval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
            
            if ($this->response === false || $this->response_status == '0') {
                $errno = curl_errno($ch);
                $errstr = curl_error($ch);
                throw new Exception("cURL error: [$errno] $errstr");
            }
        }
        
        /**
         * fsockPost
         *
         * @param $encoded_data
         * @return void
         * @throws Exception
         */
        protected function fsockPost($encoded_data)
        {
            
            $uri = 'ssl://' . $this->getPaypalHost();
            $port = '443';
            $this->post_uri = $uri . '/cgi-bin/webscr';
            
            $fp = fsockopen($uri, $port, $errno, $errstr, $this->timeout);
            
            if (!$fp) {
                // fsockopen error
                throw new Exception("fsockopen error: [$errno] $errstr");
            }
            
            $header = "POST /cgi-bin/webscr HTTP/1.1\r\n";
            $header .= "Host: " . $this->getPaypalHost() . "\r\n";
            $header .= "Content-Type: application/x-www-form-urlencoded\r\n";
            $header .= "Content-Length: " . strlen($encoded_data) . "\r\n";
            $header .= "Connection: Close\r\n\r\n";
            
            fputs($fp, $header . $encoded_data . "\r\n\r\n");
            
            while (!feof($fp)) {
                if (empty($this->response)) {
                    // extract HTTP status from first line
                    $this->response .= $status = fgets($fp, 1024);
                    $this->response_status = trim(substr($status, 9, 4));
                } else {
                    $this->response .= fgets($fp, 1024);
                }
            }
            
            fclose($fp);
        }
        
        private function getPaypalHost()
        {
            if ($this->use_live)
                return self::PAYPAL_HOST;
            else
                return self::SANDBOX_HOST;
        }
        
        /**
         *  Get POST URI
         *
         *  Returns the URI that was used to send the post back to PayPal. This can
         *  be useful for troubleshooting connection problems. The default URI
         *  would be "ssl://www.sandbox.paypal.com:443/cgi-bin/webscr"
         *
         * @return string
         */
        public function getPostUri()
        {
            return $this->post_uri;
        }
        
        /**
         *  Get Response
         *
         *  Returns the entire response from PayPal as a string including all the
         *  HTTP headers.
         *
         * @return string
         */
        public function getResponse()
        {
            return $this->response;
        }
        
        /**
         *  Get Response Status
         *
         *  Returns the HTTP response status code from PayPal. This should be "200"
         *  if the post back was successful.
         *
         * @return string
         */
        public function getResponseStatus()
        {
            return $this->response_status;
        }
        
        /**
         *  Get Text Report
         *
         *  Returns a report of the IPN transaction in plain text format. This is
         *  useful in emails to order processors and system administrators. Override
         *  this method in your own class to customize the report.
         *
         * @return string
         */
        public function getTextReport()
        {
            
            // date and POST url
            $r = str_repeat('-', 80);
            $r .= "\n[" . date('m/d/Y g:i A') . '] - ' . $this->getPostUri();
            if ($this->use_curl)
                $r .= " (curl)\n";
            else
                $r .= " (fsockopen)\n";
            
            // HTTP Response
            $r .= str_repeat('-', 80);
            $r .= "\n{$this->getResponse()}\n";
            
            // POST vars
            $r .= str_repeat('-', 80);
            $r .= "\n";
            
            foreach ($this->post_data as $key => $value) {
                $r .= str_pad($key, 25) . "$value\n";
            }
            $r .= "\n\n";
            
            return $r;
        }
        
        /**
         * processIpn
         *
         * @param $post_data
         * @return bool
         * @throws Exception
         */
        public function processIpn($post_data = null)
        {
            
            $encoded_data = 'cmd=_notify-validate';
            
            if ($post_data === null) {
                // use raw POST data
                if (!empty($_POST)) {
                    $this->post_data = $_POST;
                    $encoded_data .= '&' . file_get_contents('php://input');
                } else {
                    throw new Exception("No POST data found.");
                }
            } else {
                // use provided data array
                $this->post_data = $post_data;
                
                foreach ($this->post_data as $key => $value) {
                    $encoded_data .= "&$key=" . urlencode($value);
                }
            }
            
            if ($this->use_curl)
                $this->curlPost($encoded_data);
            else
                $this->fsockPost($encoded_data);
            
            if (!str_contains($this->response_status, '200')) {
                throw new Exception("Invalid response status: " . $this->response_status);
            }
            
            if (str_contains($this->response, "VERIFIED")) {
                return true;
            } elseif (str_contains($this->response, "INVALID")) {
                return false;
            } else {
                throw new Exception("Unexpected response from PayPal.");
            }
        }
        
        /**
         *  Require Post Method
         *
         *  Throws an exception and sets a HTTP 405 response header if the request
         *  method was not POST.
         * @throws Exception
         */
        public function requirePostMethod()
        {
            // require POST requests
            if ($_SERVER['REQUEST_METHOD'] && $_SERVER['REQUEST_METHOD'] != 'POST') {
                header('Allow: POST', true, 405);
                throw new Exception("Invalid HTTP request method.");
            }
        }
    }