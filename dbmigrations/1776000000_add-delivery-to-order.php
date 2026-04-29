<?php

namespace Application\Migrations;

use SplitPHP\DbManager\Migration;

class AddDeliveryToOrder extends Migration
{
  public function apply()
  {
    $this->Table("ORD_ORDER")
      ->text("tx_delivery_address")->nullable()->setDefaultValue(null)
    ;
  }
}
