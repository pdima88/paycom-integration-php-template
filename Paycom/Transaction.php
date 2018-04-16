<?php
namespace Paycom;

use s4y\Log;
/**
 * Class Transaction
 *
 * Example MySQL table might look like to the following:
 *
 * CREATE TABLE `transactions` (
 *   `id` INT(11) NOT NULL AUTO_INCREMENT,
 *   `paycom_transaction_id` VARCHAR(25) NOT NULL COLLATE 'utf8_unicode_ci',
 *   `paycom_time` VARCHAR(13) NOT NULL COLLATE 'utf8_unicode_ci',
 *   `paycom_time_datetime` DATETIME NOT NULL,
 *   `create_time` DATETIME NOT NULL,
 *   `perform_time` DATETIME NULL DEFAULT NULL,
 *   `cancel_time` DATETIME NULL DEFAULT NULL,
 *   `amount` INT(11) NOT NULL,
 *   `state` TINYINT(2) NOT NULL,
 *   `reason` TINYINT(2) NULL DEFAULT NULL,
 *   `receivers` VARCHAR(500) NULL DEFAULT NULL COMMENT 'JSON array of receivers' COLLATE 'utf8_unicode_ci',
 *   `order_id` INT(11) NOT NULL,
 *
 *   PRIMARY KEY (`id`)
 * )
 *   COLLATE='utf8_unicode_ci'
 *   ENGINE=InnoDB
 *   AUTO_INCREMENT=1;
 *
 */
class Transaction
{
    /** Transaction expiration time in milliseconds. 43 200 000 ms = 12 hours. */
    const TIMEOUT = 43200000;

    const STATE_CREATED = 1;
    const STATE_COMPLETED = 2;
    const STATE_CANCELLED = -1;
    const STATE_CANCELLED_AFTER_COMPLETE = -2;

    const REASON_RECEIVERS_NOT_FOUND = 1;
    const REASON_PROCESSING_EXECUTION_FAILED = 2;
    const REASON_EXECUTION_FAILED = 3;
    const REASON_CANCELLED_BY_TIMEOUT = 4;
    const REASON_FUND_RETURNED = 5;
    const REASON_UNKNOWN = 10;

    /** @var string Paycom transaction id. */
    public $paycom_transaction_id;

    /** @var int Paycom transaction time as is without change. */
    public $paycom_time;

    /** @var string Paycom transaction time as date and time string. */
    public $paycom_time_datetime;

    /** @var int Transaction id in the merchant's system. */
    public $id;

    /** @var string Transaction create date and time in the merchant's system. */
    public $create_time;

    /** @var string Transaction perform date and time in the merchant's system. */
    public $perform_time;

    /** @var string Transaction cancel date and time in the merchant's system. */
    public $cancel_time;

    /** @var int Transaction state. */
    public $state;

    /** @var int Transaction cancelling reason. */
    public $reason;

    /** @var int Amount value in coins, this is service or product price. */
    public $amount;

    /** @var string Pay receivers. Null - owner is the only receiver. */
    public $receivers;

    // additional fields:
    // - to identify order or product, for example, code of the order
    // - to identify client, for example, account id or phone number

    /** @var string Code to identify the order or service for pay. */
    public $order_id;

    /** @var \Zend_Db_Adapter_Mysqli */
    private $db;

    private $config;

    public function __construct($db, $config)
    {
        $this->db = $db;
        $this->config = $config;
    }

    private $tableName = null;

    private function getTableName() {
        if (!isset($this->tableName)) {
            $this->tableName = $this->config['schema'] . '.' .$this->config['table'];
        }
        return $this->tableName;
    }

    public function toArray() {
        return [
            'id' => $this->id,
            'paycom_transaction_id' => $this->paycom_transaction_id,
            'paycom_time' => $this->paycom_time,
            'paycom_time_datetime' => $this->paycom_time_datetime,
            'create_time' => $this->create_time,
            'perform_time' => $this->perform_time,
            'cancel_time' => $this->cancel_time,
            'state' => $this->state,
            'reason' => $this->reason,
            'amount' => $this->amount,
            'receivers' => $this->receivers,
            'order_id' => $this->order_id
        ];
    }

    private function getTransactionById($id) {
        $rows = $this->db->select()->from($this->getTableName())->where('id = ?', $id)
            ->query()->fetchAll();
        if (count($rows) > 0) {
            return $rows[0];
        }
    }

    /**
     * Saves current transaction instance in a data store.
     * @return void
     */
    public function save()
    {
        if ($this->id) {
            $this->update();
        } else {
            $this->id = $this->insert();
        }
    }

    public function insert() {
        $data = $this->toArray();
        unset($data['id']);

        $this->db->beginTransaction();
        $res = $this->db->insert($this->getTableName(), $data);
        if ($res > 0) {
            $id = $this->db->lastInsertId($this->getTableName());
            $log = 'Inserted with ID: '.$id.PHP_EOL.
                json_encode($data).PHP_EOL.
                'Rows affected: '.$res;
            Log::log('transactions_payme', $id, $log, 'insert');
        } else throw new \Exception('No rows affected');

        $this->db->commit();
        return $id;
    }

    public function update($fields = [])
    {
        if (!isset($this->id)) throw new \Exception('Id not set');
        $data = $this->toArray();
        unset($data['id']);
        if (!empty($fields)) {
            $data_src = $data;
            $data = [];
            foreach ($fields as $f) {
                $data[$f] = @$data_src[$f];
            }
        }

        $log = 'Before update:' . PHP_EOL . json_encode($this->getTransactionById($this->id)) . PHP_EOL .
               'Update:' . PHP_EOL . json_encode($data) . PHP_EOL;
        $this->db->beginTransaction();
        $res = $this->db->update($this->getTableName(), $data, ['id = ?' => $this->id]);
        $log .= 'Rows affected: ' . $res;
        Log::log('transactions_payme', $this->id, $log, 'update');
        $this->db->commit();
    }

    /**
     * Cancels transaction with the specified reason.
     * @param int $reason cancelling reason.
     * @return void
     */
    public function cancel($reason)
    {
        $this->cancel_time = Format::timestamp2datetime(Format::timestamp());

        if ($this->state == self::STATE_COMPLETED) {
            $this->state = self::STATE_CANCELLED_AFTER_COMPLETE;
        } else {
            $this->state = self::STATE_CANCELLED;
        }

        if (!$reason) {
            $reason = (($this->state == self::STATE_CANCELLED_AFTER_COMPLETE) ?
                self::REASON_FUND_RETURNED : self::REASON_PROCESSING_EXECUTION_FAILED);
        }
        $this->reason = $reason;

        Log::log('transactions_payme', $this->id, 'Reason: '.$reason.PHP_EOL.
            ', State: '.$this->state , 'cancel');
        $this->update(['cancel_time', 'state', 'reason']);
    }

    /**
     * Determines whether current transaction is expired or not.
     * @return bool true - if current instance of the transaction is expired, false - otherwise.
     */
    public function isExpired()
    {
        // todo: Implement transaction expiration check
        // for example, if transaction is active and passed TIMEOUT milliseconds after its creation, then it is expired
        return $this->state == self::STATE_CREATED && Format::datetime2timestamp($this->create_time) - time() > self::TIMEOUT;
    }

    /**
     * Find transaction by given parameters.
     * @param mixed $params parameters
     * @return Transaction|Transaction[]
     */
    public function find($params)
    {
        $row = false;
        $db_stmt = null;

        if (isset($params['id'])) {
            $q = $this->db->select()->from($this->getTableName())
                ->where('paycom_transaction_id = ?', $params['id'])->query();
            $row = $q->fetch();
            $q->closeCursor();
        } elseif (isset($params['account']['order_id'])) {
            $orderId = $params['account']['order_id'];
            $rows = $this->db->select()->from($this->getTableName())
                ->where('order_id = ?', $orderId)->query()->fetchAll();
            foreach ($rows as $r) {
                if ($r['state'] != self::STATE_CANCELLED) {
                    $row = $r;
                    break;
                }
            }
        }

        // if there is row available, then populate properties with values
        if ($row) {

            $this->id = $row['id'];
            $this->paycom_transaction_id = $row['paycom_transaction_id'];
            $this->paycom_time = 1 * $row['paycom_time'];
            $this->paycom_time_datetime = $row['paycom_time_datetime'];
            $this->create_time = $row['create_time'];
            $this->perform_time = $row['perform_time'];
            $this->cancel_time = $row['cancel_time'];
            $this->state = 1 * $row['state'];
            $this->reason = $row['reason'] ? 1 * $row['reason'] : null;
            $this->amount = 1 * $row['amount'];
            $this->order_id = 1 * $row['order_id'];

            // assume, receivers column contains list of receivers in JSON format as string
            $this->receivers = $row['receivers'] ? json_decode($row['receivers'], true) : null;

            // return populated instance
            return $this;
        }




        // transaction not found, return null
        return null;

        // Possible features:
        // Search transaction by product/order id that specified in $params
        // Search transactions for a given period of time that specified in $params
    }

    public function canCreate($params, &$errMsg, &$errCode) {
        if (isset($params['account']['order_id'])) {
            $orderId = $params['account']['order_id'];
            $rows = $this->db->select()->from($this->getTableName())
                ->where('order_id = ?', $orderId)->query()->fetchAll();
            foreach ($rows as $r) {
                if ($r['state'] != self::STATE_CANCELLED && $r['state'] != self::STATE_CANCELLED_AFTER_COMPLETE) {
                    $errMsg = 'Transaction '.$r['id'].'('.
                        $r['paycom_transaction_id'].') with orderId: '.$orderId.' already exists!';
                    $errCode = PaycomException::ERROR_INVALID_ACCOUNT;
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Gets list of transactions for the given period including period boundaries.
     * @param int $from_date start of the period in timestamp.
     * @param int $to_date end of the period in timestamp.
     * @return array list of found transactions converted into report format for send as a response.
     */
    public function report($from_date, $to_date)
    {
        $from_date = Format::timestamp2datetime($from_date);
        $to_date = Format::timestamp2datetime($to_date);

        // container to hold rows/document from data store
        $rows = $this->db->select()->from($this->getTableName())
            ->where('paycom_time_datetime BETWEEN ? AND ?', [$from_date, $to_date])
            ->query()->fetchAll();

        // assume, here we have $rows variable that is populated with transactions from data store
        // normalize data for response
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'id' => $row['paycom_transaction_id'], // paycom transaction id
                'time' => 1 * $row['paycom_time'], // paycom transaction timestamp as is
                'amount' => 1 * $row['amount'],
                'account' => [
                    'order_id' => $row['order_id'], // account parameters to identify client/order/service
                    // ... additional parameters may be listed here, which are belongs to the account
                ],
                'create_time' => Format::datetime2timestamp($row['create_time']),
                'perform_time' => Format::datetime2timestamp($row['perform_time']),
                'cancel_time' => Format::datetime2timestamp($row['cancel_time']),
                'transaction' => $row['id'],
                'state' => 1 * $row['state'],
                'reason' => isset($row['reason']) ? 1 * $row['reason'] : null,
                'receivers' => $row['receivers']
            ];
        }

        return $result;
    }
}