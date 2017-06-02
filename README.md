# Paycom integration template

## Prerequisites

- `PHP 5.4` or greater
- [`PDO`](http://php.net/manual/en/book.pdo.php) extension
- [`Composer`](https://getcomposer.org/download/) dependency manager

## Installation

```bash
$ git clone https://github.com/umidjons/paycom-integration-php-template.git
$ cd paycom-integration-php-template
$ composer dumpautoload
$ cp paycom.config.sample.php paycom.config.php
```

Edit `paycom.config.php` and set your real settings there. Then go to [Merchant Cabinet](https://merchant.paycom.uz) and in merchant settings set `endpoint` of your API. Assuming your domain is `https://myshop.uz`, `endpoint` of your API will be `https://myshop.uz/index.php`.

Feel free to use our [Test Cabinet](http://merchant.test.paycom.uz).

PRs are welcome. GL&HF!
