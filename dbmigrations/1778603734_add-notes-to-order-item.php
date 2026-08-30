<?php

namespace Orders\Migrations;

use SplitPHP\DbManager\Migration;

class AddNotesToOrderItem extends Migration
{
  public function apply()
  {
    $this->Table("ORD_ORDER_ITEM")
      ->text("tx_notes")->nullable()->setDefaultValue(null)
    ;
  }
}
