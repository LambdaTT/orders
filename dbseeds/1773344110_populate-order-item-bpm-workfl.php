<?php

namespace Application\Seeds;

use SplitPHP\DbManager\Seed;
use SplitPHP\Database\DbVocab;

class PopulateOrderItemBpmWorkfl extends Seed
{
  public function apply()
  {
    // ORDER ITEM WORKFLOW
    $this->SeedTable("BPM_WORKFLOW", batchSize: 1)
      ->onField("ds_key", true)->setByFunction(fn() => "itm-" . uniqid())
      ->onField("ds_tag")->setFixedValue("order_item")
      ->onField("ds_title")->setFixedValue("Fluxo do Item do Pedido")
      ->onField("ds_reference_entity_name")->setFixedValue("ORD_ORDER_ITEM")
    ;

    $this->SeedTable("BPM_STEP", batchSize: 1)
      ->onField("ds_key", true)->setByFunction(fn() => "stp-" . uniqid())
      ->onField("ds_title")->setFixedValue("Preparando")
      ->onField("nr_step_order")->setFixedValue(0)
      ->onField("tx_in_rules")->setFixedValue("orders/item::preparingInRules")
      ->onField("tx_out_rules")->setFixedValue("orders/item::preparingOutRules")
      ->onField("ds_tag")->setFixedValue("preparing")
      ->onField("id_bpm_workflow")->setFromOperation(-1)
    ;

    $this->SeedTable("BPM_STEP", batchSize: 1)
      ->onField("ds_key", true)->setByFunction(fn() => "stp-" . uniqid())
      ->onField("ds_title")->setFixedValue("Aguardando Entrega")
      ->onField("nr_step_order")->setFixedValue(1)
      ->onField("tx_in_rules")->setFixedValue("orders/item::waitDeliveryInRules")
      ->onField("tx_out_rules")->setFixedValue("orders/item::waitDeliveryOutRules")
      ->onField("ds_tag")->setFixedValue("waiting_delivery")
      ->onField("id_bpm_workflow")->setFromOperation(-2)
    ;

    $this->SeedTable("BPM_STEP", batchSize: 1)
      ->onField("ds_key", true)->setByFunction(fn() => "stp-" . uniqid())
      ->onField("ds_title")->setFixedValue("Entregue")
      ->onField("nr_step_order")->setFixedValue(2)
      ->onField("tx_in_rules")->setFixedValue("orders/item::deliveredInRules")
      ->onField("tx_out_rules")->setFixedValue("orders/item::deliveredOutRules")
      ->onField("ds_tag")->setFixedValue("delivered")
      ->onField("do_is_terminal")->setFixedValue("Y")
      ->onField("id_bpm_workflow")->setFromOperation(-3)
    ;

    $this->SeedTable("BPM_STEP", batchSize: 1)
      ->onField("ds_key", true)->setByFunction(fn() => "stp-" . uniqid())
      ->onField("ds_title")->setFixedValue("Cancelado")
      ->onField("nr_step_order")->setFixedValue(3)
      ->onField("tx_in_rules")->setFixedValue("orders/item::canceledInRules")
      ->onField("tx_out_rules")->setFixedValue("orders/item::canceledOutRules")
      ->onField("ds_tag")->setFixedValue("canceled")
      ->onField("do_is_terminal")->setFixedValue("Y")
      ->onField("id_bpm_workflow")->setFromOperation(-4)
    ;

    $this->SeedTable("BPM_TRANSITION", batchSize: 1)
      ->onField("ds_key", true)->setByFunction(fn() => "trn-" . uniqid())
      ->onField("ds_title")->setFixedValue("Finalizar Preparo")
      ->onField("ds_icon")->setFixedValue("fa fa-check")
      ->onField("id_bpm_workflow")->setFromOperation(-5)
      ->onField("id_bpm_step_origin")->setFromOperation(-4)
      ->onField("id_bpm_step_destination")->setFromOperation(-3)
      ->onField("ds_tag")->setFixedValue('ready')
    ;

    $this->SeedTable("BPM_TRANSITION", batchSize: 1)
      ->onField("ds_key", true)->setByFunction(fn() => "trn-" . uniqid())
      ->onField("ds_title")->setFixedValue("Marcar Entregue")
      ->onField("ds_icon")->setFixedValue("fa fa-check-double")
      ->onField("id_bpm_workflow")->setFromOperation(-6)
      ->onField("id_bpm_step_origin")->setFromOperation(-4)
      ->onField("id_bpm_step_destination")->setFromOperation(-3)
      ->onField("ds_tag")->setFixedValue('deliver')
    ;

    $this->SeedTable("BPM_TRANSITION", batchSize: 1)
      ->onField("ds_key", true)->setByFunction(fn() => "trn-" . uniqid())
      ->onField("ds_title")->setFixedValue("Cancelar")
      ->onField("ds_icon")->setFixedValue("fa fa-ban")
      ->onField("id_bpm_workflow")->setFromOperation(-7)
      ->onField("id_bpm_step_origin")->setFixedValue(null)
      ->onField("id_bpm_step_destination")->setFromOperation(-3)
      ->onField("ds_tag")->setFixedValue('cancel')
    ;
  }
}
