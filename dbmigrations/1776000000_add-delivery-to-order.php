<?php

namespace Application\Migrations;

use SplitPHP\DbManager\Migration;

class AddDeliveryToOrder extends Migration
{
  public function apply()
  {
    $this->Table("CTP_ORDER")
      ->text("tx_delivery_address")->nullable()->setDefaultValue(null)
    ;
  }
}
