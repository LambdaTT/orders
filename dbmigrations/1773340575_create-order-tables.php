<?php

namespace Application\Migrations;

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
      ->int("nr_tablenumber")
      ->int("id_bpm_execution")->nullable()->setDefaultValue(null) // Em andamento, Entregue, Concluído, Cancelado
      ->int('id_iam_user_delivered')->nullable()->setDefaultValue(null)
      ->int('id_iam_user_finished')->nullable()->setDefaultValue(null)
      ->int('id_iam_user_canceled')->nullable()->setDefaultValue(null)
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
      ->fk("id_prd_product")->nullable()->setDefaultValue(null)
      ->int("id_bpm_execution")->nullable()->setDefaultValue(null) // Prearing in Kitchen, Waiting Delivery, Delivered, Canceled
      ->string("ds_product_representation", 255)
      ->int("qt_quantity")
      ->float("vl_price")
      ->float("vl_total")
      ->int('id_iam_user_prepared')->nullable()->setDefaultValue(null)
      ->int('id_iam_user_delivered')->nullable()->setDefaultValue(null)
      ->int('id_iam_user_canceled')->nullable()->setDefaultValue(null)

      // Indexes:
      ->Index("ds_key", DbVocab::IDX_UNIQUE)->onColumn("ds_key")

      // Foreign Keys:
      ->Foreign("id_ord_order")
      ->references("id_ord_order")
      ->atTable("ORD_ORDER")
      ->onUpdate(DbVocab::FKACTION_CASCADE)
      ->onDelete(DbVocab::FKACTION_CASCADE)

      ->Foreign("id_prd_product")
      ->references("id_prd_product")
      ->atTable("CTP_PRODUCT")
      ->onUpdate(DbVocab::FKACTION_SETNULL)
      ->onDelete(DbVocab::FKACTION_SETNULL)
    ;
  }
}
