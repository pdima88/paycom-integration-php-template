# Paycom integration template

## Prerequisites

- `PHP 5.4` or greater
- `MySQL` or `MariaDB` latest stable version
- [PDO](http://php.net/manual/en/book.pdo.php) extension
- [Composer](https://getcomposer.org/download/) dependency manager

## Installation

```bash
$ git clone https://github.com/umidjons/paycom-integration-php-template.git
$ cd paycom-integration-php-template
$ composer dumpautoload
$ cp paycom.config.sample.php paycom.config.php
```

Edit `paycom.config.php` and set your real settings there.

- For tests use our [Test Merchant Cabinet](http://merchant.test.paycom.uz)
- For production use [Merchant Cabinet](https://merchant.paycom.uz)

In merchant cabinet in cashbox settings set `endpoint` of your API. Assuming your domain is `https://example.com`, then `endpoint` of your API will be `https://example.com/index.php`.

## Setup database

Connect to your database and then execute:

```sql
CREATE TABLE `transactions` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `paycom_transaction_id` VARCHAR(25) NOT NULL COLLATE 'utf8_unicode_ci',
    `paycom_time` VARCHAR(13) NOT NULL COLLATE 'utf8_unicode_ci',
    `paycom_time_datetime` DATETIME NOT NULL,
    `create_time` DATETIME NOT NULL,
    `perform_time` DATETIME NULL DEFAULT NULL,
    `cancel_time` DATETIME NULL DEFAULT NULL,
    `amount` INT(11) NOT NULL,
    `state` TINYINT(2) NOT NULL,
    `reason` TINYINT(2) NULL DEFAULT NULL,
    `receivers` VARCHAR(500) NULL DEFAULT NULL COMMENT 'JSON array of receivers' COLLATE 'utf8_unicode_ci',
    `order_id` INT(11) NOT NULL,

    PRIMARY KEY (`id`)
)
COLLATE='utf8_unicode_ci'
ENGINE=InnoDB
AUTO_INCREMENT=1;
```
___
PRs are welcome. GL&HF!
