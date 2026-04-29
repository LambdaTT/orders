<?php

namespace Application\Migrations;

use SplitPHP\DbManager\Migration;

class AddDiscountToOrder extends Migration
{
  public function apply()
  {
    $this->Table("CTP_ORDER")
      ->float("vl_discount")->nullable()->setDefaultValue(null)
    ;
  }
}
