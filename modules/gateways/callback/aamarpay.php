<?php

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

class aamarPay
{
    /**
     * @var self
     */
    private static $instance;

    /**
     * @var string
     */
    protected $gatewayModuleName;

    /**
     * @var array
     */
    protected $gatewayParams;

    /**
     * @var boolean
     */
    public $isSandbox;

    /**
     * @var boolean
     */
    public $isActive;

    /**
     * @var integer
     */
    protected $customerCurrency;


    /**
     * @var object
     */
    protected $gatewayCurrency;

    /**
     * @var integer
     */
    protected $clientCurrency;

    /**
     * @var object
     */
    protected $clientDetails;

    /**
     * @var float
     */
    protected $convoRate;

    /**
     * @var array
     */
    protected $invoice;

    /**
     * @var float
     */
    protected $due;

    /**
     * @var float
     */
    protected $fee;

    /**
     * @var float
     */
    public $total;

    /**
     * @var string
     */
    protected $baseUrl;

    /**
     * @var \Symfony\Component\HttpFoundation\Request
     */
    public $request;

    /**
     * @var array
     */
    private $credential;

    /**
     * bKashCheckout constructor.
     */
    public function __construct()
    {
        $this->setRequest();
        $this->setGateway();
        $this->setInvoice();
    }

    /**
     * The instance.
     *
     * @return self
     */
    public static function init()
    {
        if (self::$instance == null) {
            self::$instance = new aamarPay;
        }

        return self::$instance;
    }

    /**
     * Set the payment gateway.
     */
    private function setGateway()
    {
        $this->gatewayModuleName    = basename(__FILE__, '.php');
        $this->gatewayParams        = getGatewayVariables($this->gatewayModuleName);
        $this->isSandbox            = !empty($this->gatewayParams['sandbox']);
        $this->isActive             = !empty($this->gatewayParams['type']);
        $this->baseUrl              = $this->isSandbox ? 'https://sandbox.aamarpay.com/' : 'https://secure.aamarpay.com/';

        $this->credential   = [
            'store_id'      => $this->gatewayParams['store_id'],
            'signature_key' => $this->gatewayParams['signature_key'],
        ];
    }

    /**
     * Set request.
     */
    private function setRequest()
    {
        $this->request = \Symfony\Component\HttpFoundation\Request::createFromGlobals();
    }

    /**
     * Set the invoice.
     */
    private function setInvoice()
    {
        $this->invoice = localAPI('GetInvoice', [
            'invoiceid' => $this->request->get('id'),
        ]);

        $this->setCurrency();
        $this->setClient();
        $this->setDue();
        $this->setFee();
        $this->setTotal();
    }

    /**
     * Set currency.
     */
    private function setCurrency()
    {
        $this->gatewayCurrency  = (int) $this->gatewayParams['convertto'];
        $this->customerCurrency = (int) \WHMCS\Database\Capsule::table('tblclients')
            ->where('id', '=', $this->invoice['userid'])
            ->value('currency');

        if (!empty($this->gatewayCurrency) && ($this->customerCurrency !== $this->gatewayCurrency)) {
            $this->convoRate = \WHMCS\Database\Capsule::table('tblcurrencies')
                ->where('id', '=', $this->gatewayCurrency)
                ->value('rate');
        } else {
            $this->convoRate = 1;
        }
    }

    /**
     * Set Client
     */

    private function setClient()
    {
        $this->clientDetails = \WHMCS\Database\Capsule::table('tblclients')
            ->where('id', '=', $this->invoice['userid'])
            ->first();
    }

    /**
     * Set due.
     */
    private function setDue()
    {
        $this->due = $this->invoice['balance'];
    }

    /**
     * Set fee.
     */
    private function setFee()
    {
        $this->fee = empty($this->gatewayParams['additional_fee']) ? 0 : (($this->gatewayParams['additional_fee'] / 100) * $this->due);
    }

    /**
     * Set total.
     */
    private function setTotal()
    {
        $this->total = ceil(($this->due + $this->fee) * $this->convoRate);
    }

    /**
     * Check if transaction if exists.
     *
     * @param string $trxId
     *
     * @return mixed
     */
    private function checkTransaction($trxId)
    {
        return localAPI('GetTransactions', ['transid' => $trxId]);
    }

    /**
     * Log the transaction.
     *
     * @param array $payload
     *
     * @return mixed
     */
    private function logTransaction($payload)
    {
        return logTransaction(
            $this->gatewayParams['name'],
            [
                $this->gatewayModuleName    => $payload,
                'request_data'              => $this->request->request->all(),
            ],
            $payload['transactionStatus']
        );
    }

    /**
     * Add transaction to the invoice.
     *
     * @param string $trxId
     *
     * @return array
     */
    private function addTransaction($trxId)
    {
        $fields = [
            'invoiceid' => $this->invoice['invoiceid'],
            'transid'   => $trxId,
            'gateway'   => $this->gatewayModuleName,
            'date'      => \Carbon\Carbon::now()->toDateTimeString(),
            'amount'    => $this->due,
            'fees'      => $this->fee
        ];
        $add = localAPI('AddInvoicePayment', $fields);

        return array_merge($add, $fields);
    }

    /**
     * Create payment session.
     *
     * @return array
     */
    public function createPayment()
    {
        $systemUrl      = \WHMCS\Config\Setting::getValue('SystemURL');
        $callbackURL    = $systemUrl . '/modules/gateways/callback/' . $this->gatewayModuleName . '.php?id=' . $this->invoice['invoiceid'] . '&action=verify';
        $failedURL      = $systemUrl . '/viewinvoice.php?error=failed&id=' . $this->invoice['invoiceid'];
        $cancelURL      = $systemUrl . '/viewinvoice.php?error=cancelled&id=' . $this->invoice['invoiceid'];
        $firstName      = $this->clientDetails->firstname;
        $lastName       = $this->clientDetails->lastname;
        $customerName   = $firstName . ' ' . $lastName;
        $email          = $this->clientDetails->email;
        $phone          = $this->clientDetails->phonenumber;

        $fields = [
            "store_id"      => $this->credential['store_id'],
            "signature_key" => $this->credential['signature_key'],
            "tran_id"       => date('Ymdhis'),
            "amount"        => $this->total,
            "currency"      => 'BDT',
            "desc"          => 'Invoice #' . $this->invoice['invoiceid'],
            "cus_name"      => $customerName,
            "cus_email"     => $email,
            "cus_phone"     => $phone,
            "success_url"   => $callbackURL,
            "fail_url"      => $failedURL,
            "cancel_url"    => $cancelURL,
            "type"          => "json"
        ];

        $client = new \GuzzleHttp\Client();
        $response = $client->request('POST', $this->baseUrl . 'jsonpost.php', [
            'body'      => json_encode($fields),
            'headers'   => [
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
            ],
            'verify'    => false,
            'timeout'   => 30
        ]);

        $data = json_decode($response->getBody(), true);

        if (is_array($data) && isset($data['payment_url'])) {
            return [
                'status'        => 'success',
                'payment_url'   => $data['payment_url']
            ];
        }

        return [
            'status'    => 'error',
            'message'   => 'Invalid response from aamarPay API.',
            'errorCode' => 'irs'
        ];
    }

    /**
     * Search Transaction
     *
     * @return array
     */
    private function searchTransaction()
    {
        $requestId = $this->request->get('mer_txnid');

        $client = new \GuzzleHttp\Client();
        $response = $client->request('POST', $this->baseUrl . 'api/v1/trxcheck/request.php', [
            'query'     => [
                'request_id'    => $requestId,
                'store_id'      => $this->credential['store_id'],
                'signature_key' => $this->credential['signature_key'],
                'type'          => 'json'
            ],
            'verify'    => false,
            'timeout'   => 30
        ]);

        $data = json_decode($response->getBody(), true);

        if (is_array($data)) {
            return $data;
        }

        return [
            'status' => 'error',
            'message' => 'Invalid response from aamarPay API.',
            'errorCode' => 'irs'
        ];
    }

    /**
     * Make the transaction.
     *
     * @return array
     */
    public function makeTransaction()
    {
        $executePayment = $this->searchTransaction();

        if (isset($executePayment['pay_status']) && $executePayment['pay_status'] === 'Successful') {
            $existing = $this->checkTransaction($executePayment['pg_txnid']);

            if ($existing['totalresults'] > 0) {
                return [
                    'status'    => 'error',
                    'message'   => 'The transaction has been already used.',
                    'errorCode' => 'tau'
                ];
            }

            if ($executePayment['amount'] < $this->total) {
                return [
                    'status'    => 'error',
                    'message'   => 'You\'ve paid less than amount is required.',
                    'errorCode' => 'lpa'
                ];
            }

            $this->logTransaction($executePayment);

            $trxAddResult = $this->addTransaction($executePayment['pg_txnid']);

            if ($trxAddResult['result'] === 'success') {
                return [
                    'status'    => 'success',
                    'message'   => 'The payment has been successfully verified.',
                ];
            }
        }

        return [
            'status'    => 'error',
            'message'   => 'Something went wrong',
            'errorCode' => 'sww'
        ];
    }
}

$aamarPay = aamarPay::init();
if (!$aamarPay->isActive) {
    die("The gateway is unavailable.");
}

$action = $aamarPay->request->get('action');
$invid = $aamarPay->request->get('id');

if ($action === 'init') {
    $response = $aamarPay->createPayment();
    if ($response['status'] === 'success') {
        header('Location: ' . $response['payment_url']);
        exit;
    } else {
        redirSystemURL("id={$invid}&error={$response['errorCode']}", "viewinvoice.php");
        exit;
    }
}
if ($action === 'verify') {
    $response = $aamarPay->makeTransaction();
    if ($response['status'] === 'success') {
        redirSystemURL("id={$invid}", "viewinvoice.php");
        exit;
    } else {
        redirSystemURL("id={$invid}&error={$response['errorCode']}", "viewinvoice.php");
        exit;
    }
}

redirSystemURL("id={$invid}&error=sww", "viewinvoice.php");
