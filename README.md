# Paycom integration template

This is not a complete implementation of the Marchant API, instead a basic template.
One **MUST** implement all the `todo:` entries found in the source files according his/her own requirements.

## Prerequisites

- `PHP 5.4` or greater
- `MySQL` or `MariaDB` latest stable version
- [PDO](http://php.net/manual/en/book.pdo.php) extension
- [Composer](https://getcomposer.org/download/) dependency manager

## Installation

Clone this repository:

```bash
$ git clone https://github.com/PaycomUZ/paycom-integration-php-template.git
```

Change working directory:

```bash
$ cd paycom-integration-php-template
```

Generate auto-loader, this step will create **`vendor/`** folder with autoloader:

```bash
$ composer dumpautoload
```

Copy the sample config file as `paycom.config.php` and adjust the settings according to your needs:

```bash 
$ cp paycom.config.sample.php paycom.config.php
```

Edit `paycom.config.php` and set your settings there:

- Set `merchant_id`;
- Do not change the `login`, it is always `Paycom`;
- Set a path to the password file in the `keyFile`;
- Adjust connection settings in the `db` key to your `mysql` database.

Following is an example `paycom.config.php` configuration file content:

```php
<?php
return [
    'merchant_id' => '69240ea9058e46ea7a1b806a',
    'login'       => 'Paycom',
    'keyFile'     => 'password.paycom',
    'db'          => [
        'host'     => 'localhost',
        'database' => 'db_shop',
        'username' => 'db_shop_admin',
        'password' => 'bh6U8M8tR5sQGsfLVHdB'
    ],
];
```

and an example `password.paycom` file content:

```
fkWW6UNrzvzyV6DhrdHJ6aEhr3dRcvJYkaGx
```

If you need to adjust other database settings, such as character set, you can do that in the `Paycom/Database.php` file.

### Transactions table

This template requires `transactions` table at least with the following structure:

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

Additional fields can be added into this table or above data types and sizes can be adjusted.

## Additional resources

- To test your [Merchant API](https://help.paycom.uz/pw/protokol-merchant-api) implementation we highly recommend using the following tools: 
  - [Test Merchant Cabinet](http://merchant.test.paycom.uz);
  - [Merchant Sandbox](http://test.paycom.uz/).
- For production use [Merchant Cabinet](https://merchant.paycom.uz).

## Endpoint

In the merchant cabinet on the cashbox settings point the `endpoint` to your Merchant API implementation.
Assuming your domain is `https://example.com`, and your `Merchant API` implementation is located under `api/` folder 
or a URL rewriting is configured to access API by `https://example.com/api/`,  then `endpoint` should be set as `https://example.com/api/index.php`.

## Contributing

PRs are welcome. GL&HF!
