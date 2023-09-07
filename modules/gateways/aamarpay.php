<?php

/**
 * aamarPay WHMCS Gateway
 *
 * Copyright (c) 2023 RtRasel
 * Developer: rtrasel.com
 * Github: https://github.com/rtraselbd
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

function aamarpay_config()
{
    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'aamarPay',
        ],
        'store_id' => [
            'FriendlyName' => 'Store/Merchant ID',
            'Type' => 'text',
            'Size' => '20',
        ],
        'signature_key' => [
            'FriendlyName' => 'Signature Key',
            'Type' => 'text',
            'Size' => '30',
        ],
        'additional_fee' => [
            'FriendlyName' => 'Additional Service Charge %',
            'Type' => 'text',
            'Size' => '30',
            'Description' => '<br>If You Want to add Additional Service Charge Then Enter The Ratio in Integer Format. Like for 3% input value will be 3',
        ],
        'sandbox' => [
            'FriendlyName' => 'Test Mode',
            'Type' => 'yesno',
            'Description' => 'Tick this to Run on test MODE',
        ],
    ];
}

function aamarpay_link($params)
{
    $url = $params['systemurl'] . '/modules/gateways/callback/' . $params['paymentmethod'] . '.php';
    $invId = $params['invoiceid'];
    $payTxt = $params['langpaynow'];
    $errorMsg = aamarpay_errormessage();

    return <<<HTML
    <form method="GET" action="$url">
    <input type="hidden" name="action" value="init" />
    <input type="hidden" name="id" value="$invId" />
    <input class="btn btn-primary" type="submit" value="$payTxt" />
</form>
$errorMsg
HTML;
}

function aamarpay_errormessage()
{
    $errorMessage = [
        'failed'    => 'Payment has failed',
        'cancelled' => 'Payment has cancelled',
        'irs'       => 'Invalid response from aamarPay API.',
        'tau'       => 'The transaction has been already used.',
        'lpa'       => 'You\'ve paid less than amount is required.',
        'sww'       => 'Something went wrong'
    ];

    $code = isset($_REQUEST['error']) ? $_REQUEST['error'] : null;
    if (empty($code)) {
        return null;
    }

    $error = isset($errorMessage[$code]) ? $errorMessage[$code] : 'Unknown error!';

    return '<div class="alert alert-danger" style="margin-top: 10px;" role="alert">' . $error . '</div>';
}
