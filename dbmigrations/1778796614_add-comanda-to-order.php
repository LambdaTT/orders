<?php

namespace Orders\Migrations;

use SplitPHP\DbManager\Migration;

class AddComandaToOrder extends Migration
{
  public function apply()
  {
    $this->Table("ORD_ORDER")
      ->int("nr_comanda")->nullable()->setDefaultValue(null)
    ;
  }
}
