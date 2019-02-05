<?php
namespace Paycom;

class Application
{
    public $config;
    public $request;
    public $response;

    protected $db;

    /**
     * Application constructor.
     * @param array $config configuration array with <em>merchant_id</em>, <em>login</em>, <em>keyFile</em> keys.
     */
    public function __construct($config, $db)
    {
        $this->config = $config;
        $this->request = new Request();
        $this->response = new Response($this->request);
        $this->db = $db;
    }

    public function Authorize($request_id)
    {
        if (!function_exists('getallheaders')) {
            function getallheaders()
            {
                $headers = '';
                foreach ($_SERVER as $name => $value) {
                    if (substr($name, 0, 5) == 'HTTP_') {
                        $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
                    }
                }
                return $headers;
            }
        }

        $headers = getallheaders();

        if (!$headers || !isset($headers['Authorization']) ||
            !preg_match('/^\s*Basic\s+(\S+)\s*$/i', $headers['Authorization'], $matches) ||
            base64_decode($matches[1]) != $this->config['login'] . ":" . $this->config['key']
        ) {
            throw new PaycomException(
                $request_id,
                'Insufficient privilege to perform this method.',
                PaycomException::ERROR_INSUFFICIENT_PRIVILEGE
            );
        }

        return true;
    }

    /**
     * Authorizes session and handles requests.
     */
    public function run()
    {
        try {
            // authorize session
            $this->Authorize($this->request->id);

            // handle request
            switch ($this->request->method) {
                case 'CheckPerformTransaction':
                    $this->CheckPerformTransaction();
                    break;
                case 'CheckTransaction':
                    $this->CheckTransaction();
                    break;
                case 'CreateTransaction':
                    $this->CreateTransaction();
                    break;
                case 'PerformTransaction':
                    $this->PerformTransaction();
                    break;
                case 'CancelTransaction':
                    $this->CancelTransaction();
                    break;
                case 'ChangePassword':
                    $this->ChangePassword();
                    break;
                case 'GetStatement':
                    $this->GetStatement();
                    break;
                default:
                    $this->response->error(
                        PaycomException::ERROR_METHOD_NOT_FOUND,
                        'Method not found.',
                        $this->request->method
                    );
                    break;
            }
        } catch (PaycomException $exc) {
            $exc->send();
        }
    }

    private function CheckPerformTransaction()
    {
        $orderClass = $this->config['order_class'];
        $order = new $orderClass($this->request->id, $this->request->params);
        $order->find($this->request->params['account']);

        $order->validate($this->request->params);

        // Check is there another active or completed transaction for this order
        $transaction = new Transaction($this->db, $this->config['db']);
        $found = $transaction->find($this->request->params);

        if ($found && ($found->state == Transaction::STATE_CREATED || $found->state == Transaction::STATE_COMPLETED)) {
            $this->response->error(
                PaycomException::ERROR_COULD_NOT_PERFORM,
                'There is other active/completed transaction for this order.'
            );
        }

        // if control is here, then we pass all validations and checks
        // send response, that order is ready to be paid.
        $this->response->send(['allow' => true]);
    }

    private function CheckTransaction()
    {
        // todo: Find transaction by id
        $transaction = new Transaction($this->db, $this->config['db']);
        $found = $transaction->find($this->request->params);
        if (!$found) {
            $this->response->error(
                PaycomException::ERROR_TRANSACTION_NOT_FOUND,
                'Transaction not found.'
            );
        }

        // todo: Prepare and send found transaction
        $this->response->send([
            'create_time' => Format::timestamp2milliseconds(Format::datetime2timestamp($found->create_time)),
            'perform_time' => Format::timestamp2milliseconds(Format::datetime2timestamp($found->perform_time)),
            'cancel_time' => Format::timestamp2milliseconds(Format::datetime2timestamp($found->cancel_time)),
            'transaction' => $found->id,
            'state' => $found->state,
            'reason' => isset($found->reason) ? 1 * $found->reason : null
        ]);
    }

    private function CreateTransaction()
    {
        $orderClass = $this->config['order_class'];
        $order = new $orderClass($this->request->id, $this->request->params);
        $order->find($this->request->params['account']);

//        try {
            $order->validate($this->request->params);
//        } catch (\Exception $e) {
//            $this->response->error(
//                PaycomException::ERROR_INVALID_ACCOUNT,
//                $e->getMessage()
//            );
//        }

        $transaction = new Transaction($this->db, $this->config['db']);
        $found = $transaction->find($this->request->params);

        if ($found) {
            if ($found->state != Transaction::STATE_CREATED) { // validate transaction state
                $this->response->error(
                    PaycomException::ERROR_COULD_NOT_PERFORM,
                    'Transaction found, but is not active.'
                );
            } elseif ($found->isExpired()) { // if transaction timed out, cancel it and send error
                $found->cancel(Transaction::REASON_CANCELLED_BY_TIMEOUT);
                $this->response->error(
                    PaycomException::ERROR_COULD_NOT_PERFORM,
                    'Transaction is expired.'
                );
            } else { // if transaction found and active, send it as response
                $this->response->send([
                    'create_time' => Format::timestamp2milliseconds(Format::datetime2timestamp($found->create_time)),
                    'transaction' => $found->id,
                    'state' => $found->state,
                    'receivers' => $found->receivers
                ]);
            }
        } else { // transaction not found, create new one
            $err = ''; $errCode = 0;
            if (!$transaction->canCreate($this->request->params, $err, $errCode)) {
                $this->response->error(
                    $errCode,
                    $err
                );
            }
            // validate new transaction time
            if (Format::timestamp2milliseconds(1 * $this->request->params['time']) - Format::timestamp(true) >= Transaction::TIMEOUT) {
                $this->response->error(
                    PaycomException::ERROR_INVALID_ACCOUNT,
                    PaycomException::message(
                        'С даты создания транзакции прошло ' . Transaction::TIMEOUT . 'мс',
                        'Tranzaksiya yaratilgan sanadan ' . Transaction::TIMEOUT . 'ms o`tgan',
                        'Since create time of the transaction passed ' . Transaction::TIMEOUT . 'ms'
                    ),
                    'time'
                );
            }

            // create new transaction
            // keep create_time as timestamp, it is necessary in response
            $create_time = Format::timestamp();
            $transaction->paycom_transaction_id = $this->request->params['id'];
            $transaction->paycom_time = $this->request->params['time'];
            $transaction->paycom_time_datetime = Format::timestamp2datetime($this->request->params['time']);
            $transaction->create_time = Format::timestamp2datetime($create_time);
            $transaction->state = Transaction::STATE_CREATED;
            $transaction->amount = $this->request->amount;
            $transaction->order_id = $this->request->account('order_id');
            $transaction->save(); // after save $transaction->id will be populated with the newly created transaction's id.

            // send response
            $this->response->send([
                'create_time' => $create_time * 1000,
                'transaction' => $transaction->id,
                'state' => $transaction->state,
                'receivers' => null
            ]);
        }
    }

    private function PerformTransaction()
    {
        $transaction = new Transaction($this->db, $this->config['db']);
        // search transaction by id
        $found = $transaction->find($this->request->params);

        // if transaction not found, send error
        if (!$found) {
            $this->response->error(PaycomException::ERROR_TRANSACTION_NOT_FOUND, 'Transaction not found.');
        }

        switch ($found->state) {
            case Transaction::STATE_CREATED: // handle active transaction
                if ($found->isExpired()) { // if transaction is expired, then cancel it and send error
                    $found->cancel(Transaction::REASON_CANCELLED_BY_TIMEOUT);
                    $this->response->error(
                        PaycomException::ERROR_COULD_NOT_PERFORM,
                        'Transaction is expired.'
                    );
                } else { // perform active transaction
                    // todo: Mark order/service as completed
                    $params = ['order_id' => $found->order_id];
                    $orderClass = $this->config['order_class'];
                    $order = new $orderClass($this->request->id);
                    $order->find($params);
                    $order->transaction_id = $found->id;
                    $order->setPaid();

                    // todo: Mark transaction as completed
                    $perform_time = Format::timestamp();
                    $found->state = Transaction::STATE_COMPLETED;
                    $found->perform_time = Format::timestamp2datetime($perform_time);
                    $found->save();

                    $this->response->send([
                        'transaction' => $found->id,
                        'perform_time' => $perform_time * 1000,
                        'state' => $found->state
                    ]);
                }
                break;

            case Transaction::STATE_COMPLETED: // handle complete transaction
                // todo: If transaction completed, just return it
                $this->response->send([
                    'transaction' => $found->id,
                    'perform_time' => Format::timestamp2milliseconds(Format::datetime2timestamp($found->perform_time)),
                    'state' => $found->state
                ]);
                break;

            default:
                // unknown situation
                $this->response->error(
                    PaycomException::ERROR_COULD_NOT_PERFORM,
                    'Could not perform this operation.'
                );
                break;
        }
    }

    private function CancelTransaction()
    {
        $transaction = new Transaction($this->db, $this->config['db']);

        // search transaction by id
        $found = $transaction->find($this->request->params);

        // if transaction not found, send error
        if (!$found) {
            $this->response->error(PaycomException::ERROR_TRANSACTION_NOT_FOUND, 'Transaction not found.');
        }

        switch ($found->state) {
            // if already cancelled, just send it
            case Transaction::STATE_CANCELLED:
            case Transaction::STATE_CANCELLED_AFTER_COMPLETE:
                $this->response->send([
                    'transaction' => $found->id,
                    'cancel_time' => Format::timestamp2milliseconds(Format::datetime2timestamp($found->cancel_time)),
                    'state' => $found->state
                ]);
                break;

            // cancel active transaction
            case Transaction::STATE_CREATED:
                // cancel transaction with given reason
                $found->cancel(1 * $this->request->params['reason']);
                // after $found->cancel(), cancel_time and state properties populated with data

                // change order state to cancelled
                $orderClass = $this->config['order_class'];
                $order = new $orderClass($this->request->id);
                $order->find(['order_id' => $found->order_id]);
                $order->cancel();

                // send response
                $this->response->send([
                    'transaction' => $found->id,
                    'cancel_time' => Format::timestamp2milliseconds(Format::datetime2timestamp($found->cancel_time)),
                    'state' => $found->state
                ]);
                break;

            case Transaction::STATE_COMPLETED:
                // find order and check, whether cancelling is possible this order
                $orderClass = $this->config['order_class'];
                $order = new $orderClass($this->request->id);
                $order->find(['order_id' => $found->order_id]);
                $order->transaction_id = $found->id;
                if ($order->allowCancel()) {
                    // cancel and change state to cancelled
                    $found->cancel(1 * @$this->request->params['reason']);
                    // after $found->cancel(), cancel_time and state properties populated with data

                    $order->cancel(true);

                    // send response
                    $this->response->send([
                        'transaction' => $found->id,
                        'cancel_time' => Format::timestamp2milliseconds(Format::datetime2timestamp($found->cancel_time)),
                        'state' => $found->state
                    ]);
//                    'cancel_time' => Format::datetime2timestamp($found->cancel_time),
                } else {
                    // todo: If cancelling after performing transaction is not possible, then return error -31007
                    $this->response->error(
                        PaycomException::ERROR_COULD_NOT_CANCEL,
                        'Could not cancel transaction. Order is delivered/Service is completed.'
                    );
                }
                break;
        }
    }

    private function ChangePassword()
    {
        // validate, password is specified, otherwise send error
        if (!isset($this->request->params['password']) || !trim($this->request->params['password'])) {
            $this->response->error(PaycomException::ERROR_INVALID_ACCOUNT, 'New password not specified.', 'password');
        }

        // if current password specified as new, then send error
        if ($this->config['key'] == $this->request->params['password']) {
            $this->response->error(PaycomException::ERROR_INSUFFICIENT_PRIVILEGE, 'Insufficient privilege. Incorrect new password.');
        }

        // todo: Implement saving password into data store or file
        // example implementation, that saves new password into file specified in the configuration

        //\S4Y_Log::log('payme', 0, 'New password: '.$this->request->params['password'],'change_password');
        $this->response->error(PaycomException::ERROR_INTERNAL_SYSTEM, 'Internal System Error.');


        // if control is here, then password is saved into data store
        // send success response
        //$this->response->send(['success' => true]);
    }

    private function GetStatement()
    {
        // validate 'from'
        if (!isset($this->request->params['from'])) {
            $this->response->error(PaycomException::ERROR_INVALID_ACCOUNT, 'Incorrect period.', 'from');
        }

        // validate 'to'
        if (!isset($this->request->params['to'])) {
            $this->response->error(PaycomException::ERROR_INVALID_ACCOUNT, 'Incorrect period.', 'to');
        }

        // validate period
        if (1 * $this->request->params['from'] >= 1 * $this->request->params['to']) {
            $this->response->error(PaycomException::ERROR_INVALID_ACCOUNT, 'Incorrect period. (from >= to)', 'from');
        }

        // get list of transactions for specified period
        $transaction = new Transaction($this->db, $this->config['db']);
        $transactions = $transaction->report($this->request->params['from'], $this->request->params['to']);

        // send results back
        $this->response->send(['transactions' => $transactions]);
    }
}
