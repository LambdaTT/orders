<?php

namespace Orders\Migrations;

use SplitPHP\DbManager\Migration;
use SplitPHP\Database\DbVocab;

class CreateOrderTables extends Migration
{
  public function apply()
  {
    $this->Table("ORD_ORDER")

      // Fields:
      ->id("id_ord_order")
      ->string("ds_key", 17)
      ->datetime("dt_created")->setDefaultValue(DbVocab::SQL_CURTIMESTAMP())
      ->datetime("dt_updated")->setDefaultValue(DbVocab::SQL_CURTIMESTAMP())
      ->int("id_iam_user_created")->nullable()->setDefaultValue(null)
      ->int("id_iam_user_updated")->nullable()->setDefaultValue(null)
      ->int("nr_tablenumber")->nullable()->setDefaultValue(null)
      ->int("id_bpm_execution")->nullable()->setDefaultValue(null) // Em andamento, Entregue, Concluído, Cancelado
      ->float("vl_amount")

      // Indexes:
      ->Index("ds_key", DbVocab::IDX_UNIQUE)->onColumn("ds_key")
    ;

    $this->Table("ORD_ORDER_ITEM")

      // Fields:
      ->id("id_ord_order_item")
      ->string("ds_key", 17)
      ->datetime("dt_created")->setDefaultValue(DbVocab::SQL_CURTIMESTAMP())
      ->datetime("dt_updated")->setDefaultValue(DbVocab::SQL_CURTIMESTAMP())
      ->int("id_iam_user_created")->nullable()->setDefaultValue(null)
      ->int("id_iam_user_updated")->nullable()->setDefaultValue(null)
      ->fk("id_ord_order")
      ->fk("id_srv_serving_variant")->nullable()->setDefaultValue(null)
      ->int("id_bpm_execution")->nullable()->setDefaultValue(null) // Prearing in Kitchen, Waiting Delivery, Delivered, Canceled
      ->string("ds_serving_representation", 255)
      ->int("qt_quantity")
      ->float("vl_price")
      ->float("vl_total")

      // Indexes:
      ->Index("ds_key", DbVocab::IDX_UNIQUE)->onColumn("ds_key")

      // Foreign Keys:
      ->Foreign("id_ord_order")
      ->references("id_ord_order")
      ->atTable("ORD_ORDER")
      ->onUpdate(DbVocab::FKACTION_CASCADE)
      ->onDelete(DbVocab::FKACTION_CASCADE)

      ->Foreign("id_srv_serving_variant")
      ->references("id_srv_serving_variant")
      ->atTable("SRV_SERVING_VARIANT")
      ->onUpdate(DbVocab::FKACTION_CASCADE)
      ->onDelete(DbVocab::FKACTION_CASCADE)
    ;
  }
}
