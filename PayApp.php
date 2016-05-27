<?php


/**
 * BoxBilling PayApp payment method extension.
 *
 * @copyright Changwoo Nam
 * @license   Apache-2.0
 * @author    Changwoo Nam (ep6tri@hotmail.com)
 * @version   1.0.0
 */
class Payment_Adapter_PayApp extends Payment_AdapterAbstract implements \Box\InjectionAwareInterface
{
    /**
     * @var Box_Di
     */
    protected $di;

    /**
     * @param Box_Di $di
     */
    public function setDi($di)
    {
        $this->di = $di;
    }

    /**
     * @return Box_Di
     */
    public function getDi()
    {
        return $this->di;
    }

    public function init()
    {
        if (!$this->getParam('user_id')) {
            throw new Payment_Exception('Payment gateway "Payapp" is not configured properly. Please update configuration parameter "User ID" (페이앱 판매점(사업자) 회원 ID) at "Configuration -> Payments".');
        }

        if (!$this->getParam('key')) {
            throw new Payment_Exception('Payment gateway "Payapp" is not configured properly. Please update configuration parameter "Key" (페이앱 연동 KEY) at "Configuration -> Payments".');
        }

        if (!$this->getParam('value')) {
            throw new Payment_Exception('Payment gateway "Payapp" is not configured properly. Please update configuration parameter "Value" (페이앱 연동 VALUE) at "Configuration -> Payments".');
        }
    }

    /**
     * Return gateway type
     *
     * @return string
     */
    public function getType()
    {
        return Payment_AdapterAbstract::TYPE_HTML;
    }

    /**
     * Return config, and is also related with config setting screen.
     *
     * @return array
     */
    public static function getConfig()
    {
        return array(
            'supports_one_time_payments' => true,
            'supports_subscriptions'     => false,
            'description'                => '페이앱 게이트웨이. <a href="https://seller.payapp.kr/c/apiconnect_info.html" target="_blank">연동정보</a> 페이지 참고.',
            'form'                       => array(
                'user_id' => array(
                    'text',
                    array(
                        'label'       => '연동 아이디',
                        'description' => '판매점(사업자) 회원 아이디입니다.',
                    ),
                ),
                'key'     => array(
                    'text',
                    array(
                        'label'       => '연동 KEY',
                        'description' => '웹페이지에서 연동할때 사용되는 키값입니다.',
                    ),
                ),
                'value'   => array(
                    'text',
                    array(
                        'label'       => '연동 VALUE',
                        'description' => '웹페이지에서 연동할때 사용되는 비교값입니다. feedbackurl 로 값이 전송되며, 위 값을 비교해서 정상적인 접속인지 확인 합니다.',
                    ),
                ),
            ),
        );
    }

    /**
     * Return service call URL
     *
     * @return string
     */
    public function getServiceURL()
    {
        return PayApp::PAY_URL;
    }

    /**
     * 이 함수가 있으면 singlePayment 보다 우선해서 실행되고, singlePayment/recurrentPayment 는 무시된다.
     *
     * @param $api_admin
     * @param $invoice_id
     * @param $subscribe
     *
     * @return string
     */
    public function getHtml($api_admin, $invoice_id, $subscribe)
    {
        $invoice = $api_admin->invoice_get(array('id' => $invoice_id));

        if ($invoice['status'] == 'unpaid') {

            $lines = $invoice['lines'];
            $count = count($lines);

            if ($count > 1) {
                $title_desc = sprintf('%s 외 %d 개 상품', $lines[0]['title'], ($count - 1));
            } else {
                $title_desc = $lines[0]['title'];
            }

            /**
             * 전화번호에 국제전화번호 국가 코드가 붙어 있는 경우 별도 처리
             * 시스템이 +82 처럼 +기호가 붙는 건 알아서 없애준다.
             * @see https://countrycode.org/
             */
            $buyer_phone = preg_replace('/[^\d-]/', '', $invoice['buyer']['phone']);
            $matches     = array();
            if (preg_match('/((\d+-)?\d+)?(01[07-9]-?\d{3,4}-?\d{3,4})/', $buyer_phone, $matches)) {
                $country_code = $matches[1];
                $phone        = $matches[3];
            } else {
                $country_code = '';
                $phone        = $buyer_phone;
            }

            // invoice 내용을 바탕으로 payapp payurl 획득
            $request = PayApp::buildRequest(
                array(
                    'cmd'         => 'payrequest',
                    'userid'      => $this->getParam('user_id'),
                    'goodname'    => $title_desc,
                    'price'       => $invoice['total'],
                    'recvphone'   => $phone,
                    'memo'        => '',
                    'reqaddr'     => '0',
                    'feedbackurl' => $this->getParam('notify_url'),
                    'var1'        => $invoice['hash'],
                    'var2'        => $invoice['id'],
                    'smsuse'      => 'n',
                    'currency'    => strtolower($invoice['currency']),
                    'vccode'      => $country_code,
                    'returnurl'   => '',
                    'openpaytype' => 'card',
                    'checkretry'  => 'n',
                )
            );

            $pay_url = PayApp::getCallbackURL($request);
            $hash    = $invoice['hash'];

            $thankyou_url = $this->getParam('thankyou_url');
            $redirect_url = $this->getParam('redirect_url');

            $directory = basename(__DIR__);

            ob_start();
            include('includes/template.php');

            return ob_get_clean();
        }

        return '';
    }

    /**
     * Process transaction received from payment gateway
     *
     * processing transaction
     * validate post value, and fill some fields ...
     *
     *  txn_id            transaction_id. from mul_no
     *  txn_status        transaction_status. from pay_state
     *  amount            from price
     *  currency          from currency. KRW if not present. MUST CAPITALIZE first
     *  type              from pay_type.
     *  status            processed or something else.
     *
     * @throws
     *
     * @param Api_Admin $api_admin
     * @param int       $id         - transaction id to process
     * @param array     $data       - post, get, server, http_raw_post_data
     * @param int       $gateway_id - payment gateway id on BoxBilling
     *
     * @return mixed
     */
    public function processTransaction($api_admin, $id, $data, $gateway_id)
    {
        $feedback = PayApp::checkFeedbackData($data['post'], PayApp::getFeedbackSpec());

        if (!$this->isIpnValid($feedback, $api_admin)) {
            throw new Payment_Exception('IPN is not valid');
        }

        $tx_data = array(
            'id'         => $id,
            'invoice_id' => $feedback['var2'],
            'txn_id'     => $feedback['mul_no'],
            'txn_status' => $feedback['pay_state'],
            'amount'     => $feedback['price'],
            'currency'   => isset($feedback['currency']) ? strtoupper($feedback['currency']) : 'KRW',
            'type'       => $feedback['pay_type'],
            'status'     => 'unknown',
            'error'      => '',
            'error_code' => '',
        );

        switch ($feedback['pay_state']) {
            case '4':
                $tx_data['status'] = 'processed';
                break;
            case '9':
            case '64':
                $tx_data['status'] = 'refunded';
                break;
            case '8':
            case '16':
            case '32':
                $tx_data['status'] = 'canceled';
                break;
        }

        $tx             = $this->di['db']->getExistingModelByid('Transaction', $id, 'Transaction not found');
        $invoice        = $this->di['db']->getExistingModelById('Invoice', $tx->invoice_id, 'Invoice not found');
        $invoiceService = $this->di['mod_service']('Invoice');

        if ($feedback['pay_state'] == '4') {
            // 승인 처리
            $fund_data = array(
                'client_id'   => $invoice->client_id,
                'type'        => 'PayApp',
                'rel_id'      => $tx_data['txn_id'],
                'amount'      => $tx_data['amount'],
                'description' => 'PayApp transaction ' . $tx->txn_id,
            );

            if ($this->isIpnDuplicate($feedback)) {
                throw new Payment_Exception('IPN is duplicate');
            }

            $client        = $this->di['db']->getExistingModelById('Client', $invoice->client_id, 'Client not found');
            $clientService = $this->di['mod_service']('Client');
            $clientService->addFunds($client, $fund_data['amount'], $fund_data['description'], $fund_data);

            if ($tx->invoice_id) {
                $invoiceService->payInvoiceWithCredits($invoice);
            }
            $invoiceService->doBatchPayWithCredits(array('client_id' => $client->id));

        } else if (in_array($feedback['pay_state'], array('9', '64'))) {
            /// 승인 취소 처리: balance 기록을 조작힐 필요는 없고, invoice 를 unpaid 상태로 돌림
            $invoiceService->refundInvoice($invoice, 'PayApp refund');
            $tx_data['amount'] = -1 * $tx_data['amount'];
        }

        $txService = $this->di['mod_service']('Invoice', 'Transaction');
        $txService->update($tx, $tx_data);
        
        return true;
    }

    private function isIpnValid($feedback, $api_admin)
    {
        if ($feedback['userid'] != $this->getParam('user_id')) {
            return false;
        }

        if ($feedback['linkkey'] != $this->getParam('key')) {
            return false;
        }

        if ($feedback['linkval'] != $this->getParam('value')) {
            return false;
        }

        $hash       = $feedback['var1'];
        $invoice_id = $feedback['var2'];
        $invoice    = $api_admin->invoice_get(array('id' => $invoice_id));

        if (!$invoice) {
            return false;
        }

        if ($hash != $invoice['hash']) {
            return false;
        }

        return true;
    }

    private function isIpnDuplicate(array $ipn)
    {
        $sql = 'SELECT `id`
                FROM `transaction`
                WHERE `txn_id` = :transaction_id
                  AND `txn_status` = :transaction_status
                  AND `amount` = :transaction_amount
                LIMIT 2';

        $bindings = array(
            ':transaction_id'     => $ipn['mul_no'],
            ':transaction_status' => $ipn['pay_state'],
            ':transaction_amount' => $ipn['price'],
        );

        $rows = $this->di['db']->getAll($sql, $bindings);

        return count($rows) > 1;
    }

    public function singlePayment(Payment_Invoice $invoice)
    {
        throw new Payment_Exception('Payapp payment gateway do not support single payments');
    }

    public function recurrentPayment(Payment_Invoice $invoice)
    {
        throw new Payment_Exception('Payapp payment gateway do not support recurrent payments');
    }
}


/**
 * Class BoxBillingPayApp
 *
 * @license   Apache-2.0
 * @author    Changwoo Nam (ep6tri@hotmail.com)
 * @version   1.0.0
 */
class PayApp
{
    const VERSION = '1.0.0';

    const PAY_URL = 'http://api.payapp.kr/oapi/apiLoad.html';

    const DATETIME_REGEXP = '/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/';

    const URL_REGEXP = '/^https?:\/\/.+/';

    const BINARY_REGEXP = '/^0|1$/';

    const MOBILE_REGEXP = '/01[07-9]-?\d{3,4}-?\d{3,4}/';

    const NUMERIC_REGEXP = '/\d+(\.\d+)?/';

    const SHA1_REGEXP = '/^[a-f0-9]{40}$/i';

    const CURRENCY_REGEXP = '/^[a-z]{3}$/i';

    const YN_REGEXP = '/^y|n$/i';

    const ID_REGEXP = '/^\d+$/';

    public static function buildRequest(array $data)
    {
        $specs   = self::getRequestSpec();
        $request = self::checkRequestData($data, $specs);

        return $request;
    }

    public static function getRequestSpec()
    {
        /*
         * 페이앱 호출 파라미터
         *
         * cmd          required    string  결제요청. 'payrequest'로 고정
         * userid       required    string  판매자 회원 아이디
         * goodname     required    string  상품명
         * price        required    string  결제요청 금액 (1,000원 이상)
         * recvphone    required    string  구매자의 수신 가능 휴대폰 번호
         * memo         optional    string  결제요청시 메모
         * reqaddr      optional    string  주소요청 여부 (1: 요청, 0: 요청하지 않음)
         * feedbackurl  optional    string  결제완료 피드백 URL
         * var1         optional    string  임의 사용 변수 1
         * var2         optional    string  임의 사용 번수 2
         * smsuse       optional    string  결제요청 SMS 발송여부 ('n'인 경우 SMS 발송 안함)
         * currency     optional    string  통화기호 (krw:원화결제, usd:US달러 결제)
         * vccode       optional    string  국제전화 국가번호 (currency가 usd일 경우 필수)
         * returnurl    optional    string  결제완료 이동 URL (결제완료 후 매출전표 페이지에서 "확인" 버튼 클릭시 이동)
         * openpaytype  optional    string  결제수단 선택 (휴대전화:phone, 신용카드:card, 계좌이체:rbank, 가상계좌:vbank).
         *                                  판매자 사이트 "설정" 메뉴의 "결제 설정"이 우선 합니다.
         *                                  해외결제는 현재 신용카드 결제만 가능하며, 입력된 값은 무시됩니다.
         * checkretry   optional    string  y/n. feedbackurl 의 응답이 'SUCCESS'가 아닌 경우 feedbackurl 호출을 재시도 합니다. (총 10회)
         */

        // Array structure:
        //  * name      – request item name.
        //  * maxlen    – max allowed value for item.
        //  * required  – is this item is required.
        //  * user      – if true, user can set value of this item, if false
        //                item value is generated.
        //  * isrequest – if true, item will be included in request array, if
        //                false, item only be used internaly and will not be
        //                included in outgoing request array.
        //  * regexp    – regexp to test item value.

        return array(
            array('cmd', 10, true, true, true, '/^payrequest$/'),
            array('userid', 50, true, true, true, ''),
            array('goodname', 255, true, true, true, ''),
            array('price', 11, true, true, true, self::NUMERIC_REGEXP),
            array('recvphone', 14, true, true, true, self::MOBILE_REGEXP),
            array('memo', 255, false, true, true, ''),
            array('reqaddr', 1, false, true, true, self::BINARY_REGEXP),
            array('feedbackurl', 255, false, true, true, self::URL_REGEXP),
            array('var1', 255, false, true, true, self::SHA1_REGEXP),
            array('var2', 255, false, true, true, self::ID_REGEXP),
            array('smsuse', 1, false, true, true, '/y|n/'),
            array('currency', 3, false, true, true, self::CURRENCY_REGEXP),
            array('vccode', 255, false, true, true, ''),
            array('returnurl', 255, false, true, true, ''),
            array('openpaytype', 20, false, true, true, '/phone|card|rbank|vbank/'),
            array('checkretry', 1, false, true, true, self::YN_REGEXP),
        );
    }

    public static function checkRequestData(array $data, array $specs)
    {
        $request = array();

        foreach ($specs as $spec) {

            list($name, $max_len, $required, $user, $is_request, $regexp) = $spec;

            if (!$user) {
                continue;
            }

            $is_set = isset($data[ $name ]);

            // required field check
            if ($required && !$is_set) {
                $e = new PayAppException(sprintf("'%s' is required but missing.", $name), PayAppException::E_MISSING);
                $e->setField($name);
                throw $e;
            }

            $value = $data[ $name ];

            if (!empty($value)) {
                if ($max_len && strlen($value) > $max_len) {
                    $e = new PayAppException(
                        sprintf("'%s' value %s is too long, %d characters allowed.", $name, $value, $max_len),
                        PayAppException::E_MAX_LEN
                    );
                    $e->setField($name);
                    throw $e;
                }

                if ('' != $regexp && !preg_match($regexp, $value)) {
                    $e = new PayAppException(
                        sprintf("'%s' value '%s' is invalid.", $name, $value),
                        PayAppException::E_REGEXP
                    );
                    $e->setField($name);
                    throw $e;
                }
            }

            if ($is_request && $is_set) {
                $request[ $name ] = $value;
            }
        }

        if (!isset($data['currency'])) {
            $request['currency'] = 'krw';
        }

        // minimum price check
        // price should be equal or greater than 1,000 won.
        $min_price = 1000;
        if ($request['currency'] == 'krw' && $request['price'] < $min_price) {
            $e = new PayAppException(
                sprintf('price should be larger than %d won!', $min_price),
                PayAppException::E_INVALID
            );
            $e->setField('price');
            throw $e;
        }

        // recvphone
        $request['recvphone'] = preg_replace('/[^\d]/', '', $request['recvphone']);

        return $request;
    }

    public static function getCallbackURL(array $request)
    {
        $response = self::checkResponseData(self::sendRequest($request), self::getResponseSpec());

        if (!$response['state']) {
            $e = new PayAppException('Unsuccessful API call', PayAppException::E_INVALID);
            $e->setField('state');
            throw $e;
        }

        return $response['payurl'];
    }

    public static function sendRequest(array $request)
    {
        if (!function_exists('curl_version')) {
            throw new Payment_Exception('CURL is not installed!');
        }

        $resource = curl_init(self::PAY_URL);

        curl_setopt($resource, CURLOPT_POST, 1);
        curl_setopt($resource, CURLOPT_POSTFIELDS, $request);
        curl_setopt($resource, CURLOPT_RETURNTRANSFER, 1);

        /** @var string $response */
        $response = curl_exec($resource);
        $result   = array();

        curl_close($resource);

        parse_str($response, $result);

        return $result;
    }

    public static function getResponseSpec()
    {
        return array(
            array('state', 1, true, true, true, self::BINARY_REGEXP),
            array('errorMessage', 255, true, true, true, ''),
            array('mul_no', 11, true, true, true, self::ID_REGEXP),
            array('payurl', 255, true, true, true, self::URL_REGEXP),
        );
    }

    public static function checkResponseData($data, $specs)
    {
        $response = array();

        foreach ($specs as $spec) {

            // $user, $is_request is ignored.
            list($name, $max_len, $required, $user, $is_request, $regexp) = $spec;

            $is_set = isset($data[ $name ]);

            // required field check
            if ($required && !$is_set) {
                $e = new PayAppException(sprintf("'%s' is required but missing.", $name), PayAppException::E_MISSING);
                $e->setField($name);
                throw $e;
            }

            $value = $data[ $name ];

            if (!empty($value)) {

                if ($max_len && strlen($value) > $max_len) {
                    $e = new PayAppException(
                        sprintf("'%s' value %s is too long, %d characters allowed.", $name, $value, $max_len),
                        PayAppException::E_MAX_LEN
                    );
                    $e->setField($name);
                    throw $e;
                }

                if ('' != $regexp && !preg_match($regexp, $value)) {
                    $e = new PayAppException(
                        sprintf("'%s' value '%s' is invalid.", $name, $value),
                        PayAppException::E_REGEXP
                    );
                    $e->setField($name);
                    throw $e;
                }
            }

            $response[ $name ] = $value;
        }

        return $response;
    }

    public static function getFeedbackSpec()
    {
        /**
         * Feedback 전달
         * -------------
         * userid           판매자 회원 아이디
         * linkkey          연동 KEY
         * linkval          연동 VALUE
         * goodname         상품명
         * price            결제요청 금액
         * recvphone        수신 휴대폰 번호
         * memo             메모
         * reqaddr          주소요청 (1:요청, 0: 요청 안함)
         * reqdate          결제요청 일시
         * pay_memo         결제자가 입력한 메모
         * pay_addr         결제시 입력한 주소
         * pay_date         결제 승인 일시
         * pay_type         결제 수단 (1: 신용카드, 2: 휴대전화)
         * pay_state        결제요청 상태 (1: 요청, 4: 결제 완료,  (8, 16, 32): 요청 취소, (9, 64): 승인 취소
         * var1             해시값
         * var2             invoice id
         * mul_no           결제 요청 번호
         * payurl           결제 페이지 주소
         * csturl           매출 전표 URL
         * card_name        카드 이름
         * currency         통화 코드 (krw, usd)
         * vccode           국제전화 국가번호
         * score            DM score (currency = usd, 결제 성공일 때)
         * vbank            은행명 (가상계좌 결제인 경우)
         * vbankno          입금계좌번호 (가상계좌 결제인 경우)
         *
         * parameter_name, key-must-present, regexp
         */
        return array(
            array('userid', true, ''),
            array('linkkey', true, ''),
            array('linkval', true, ''),
            array('goodname', true, ''),
            array('price', true, self::NUMERIC_REGEXP),
            array('recvphone', true, self::MOBILE_REGEXP),
            array('memo', true, ''),
            array('reqaddr', true, self::BINARY_REGEXP),
            array('reqdate', true, self::DATETIME_REGEXP),
            array('pay_memo', true, ''),
            array('pay_addr', true, ''),
            array('pay_date', true, self::DATETIME_REGEXP),
            array('pay_type', true, self::BINARY_REGEXP),
            array('pay_state', true, self::ID_REGEXP),
            array('var1', true, self::SHA1_REGEXP),
            array('var2', true, self::ID_REGEXP),
            array('mul_no', true, self::ID_REGEXP),
            array('payurl', true, self::URL_REGEXP),
            array('csturl', true, self::URL_REGEXP),
            array('card_name', false, ''),
            array('currency', false, ''),
            array('vccode', false, ''),
            array('score', false, ''),
            array('vbank', false, ''),
            array('vbankno', false, ''),
        );
    }

    public static function checkFeedbackData($data, $specs)
    {
        $response = array();

        foreach ($specs as $spec) {

            list($name, $required, $regexp) = $spec;

            $is_set = isset($data[ $name ]);

            // required field check
            if ($required && !$is_set) {
                $e = new PayAppException(sprintf("'%s' is required but missing.", $name), PayAppException::E_MISSING);
                $e->setField($name);
                throw $e;
            }

            if ($is_set) {

                $value = $data[ $name ];

                if (!empty($value)) {

                    if ('' != $regexp && !preg_match($regexp, $value)) {
                        $e = new PayAppException(
                            sprintf("'%s' value '%s' is invalid.", $name, $value),
                            PayAppException::E_REGEXP
                        );
                        $e->setField($name);
                        throw $e;
                    }
                }

                $response[ $name ] = $value;
            }
        }

        return $response;
    }
}


class PayAppException extends Exception
{
    const E_MISSING = 1;

    const E_INVALID = 2;

    const E_MAX_LEN = 3;

    const E_REGEXP = 4;

    const E_USER_PARAMS = 5;

    protected $field_name = false;

    public function setField($field_name)
    {
        $this->field_name = $field_name;
    }

    public function getField()
    {
        return $this->field_name;
    }
}
