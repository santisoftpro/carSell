<?php
    /**
     * Cron
     *
     * @package Wojo Framework
     * @author wojoscripts.com
     * @copyright 2022
     * @version $Id: cron.php, v1.00 2022-03-05 10:12:05 gewa Exp $
     */
    const _WOJO = true;
    require_once("../init.php");
    
    Cron::Run();