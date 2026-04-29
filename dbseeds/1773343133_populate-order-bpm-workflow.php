<?php

namespace Application\Seeds;

use SplitPHP\DbManager\Seed;
use SplitPHP\Database\DbVocab;

class PopulateOrderBpmWorkflow extends Seed
{
  public function apply()
  {
    // ORDER WORKFLOW
    $this->SeedTable("BPM_WORKFLOW", batchSize: 1)
      ->onField("ds_key", true)->setByFunction(fn() => "ord-" . uniqid())
      ->onField("ds_tag")->setFixedValue("order")
      ->onField("ds_title")->setFixedValue("Fluxo do Pedido")
      ->onField("ds_reference_entity_name")->setFixedValue("CTP_ORDER")
    ;

    $this->SeedTable("BPM_STEP", batchSize: 1)
      ->onField("ds_key", true)->setByFunction(fn() => "stp-" . uniqid())
      ->onField("ds_title")->setFixedValue("Em Andamento")
      ->onField("nr_step_order")->setFixedValue(0)
      ->onField("tx_in_rules")->setFixedValue("orders/order::inProgressInRules")
      ->onField("tx_out_rules")->setFixedValue("orders/order::inProgressOutRules")
      ->onField("ds_tag")->setFixedValue("in_progress")
      ->onField("id_bpm_workflow")->setFromOperation(-1)
    ;

    $this->SeedTable("BPM_STEP", batchSize: 1)
      ->onField("ds_key", true)->setByFunction(fn() => "stp-" . uniqid())
      ->onField("ds_title")->setFixedValue("Entregue")
      ->onField("nr_step_order")->setFixedValue(1)
      ->onField("tx_in_rules")->setFixedValue("orders/order::deliveredInRules")
      ->onField("tx_out_rules")->setFixedValue("orders/order::deliveredOutRules")
      ->onField("ds_tag")->setFixedValue("delivered")
      ->onField("do_is_terminal")->setFixedValue("N")
      ->onField("id_bpm_workflow")->setFromOperation(-2)
    ;

    $this->SeedTable("BPM_STEP", batchSize: 1)
      ->onField("ds_key", true)->setByFunction(fn() => "stp-" . uniqid())
      ->onField("ds_title")->setFixedValue("Concluído")
      ->onField("nr_step_order")->setFixedValue(2)
      ->onField("tx_in_rules")->setFixedValue("orders/order::completedInRules")
      ->onField("ds_tag")->setFixedValue("completed")
      ->onField("do_is_terminal")->setFixedValue("Y")
      ->onField("id_bpm_workflow")->setFromOperation(-3)
    ;

    $this->SeedTable("BPM_STEP", batchSize: 1)
      ->onField("ds_key", true)->setByFunction(fn() => "stp-" . uniqid())
      ->onField("ds_title")->setFixedValue("Cancelado")
      ->onField("nr_step_order")->setFixedValue(3)
      ->onField("tx_in_rules")->setFixedValue("orders/order::canceledInRules")
      ->onField("ds_tag")->setFixedValue("canceled")
      ->onField("do_is_terminal")->setFixedValue("Y")
      ->onField("id_bpm_workflow")->setFromOperation(-4)
    ;

    $this->SeedTable("BPM_TRANSITION", batchSize: 1)
      ->onField("ds_key", true)->setByFunction(fn() => "trn-" . uniqid())
      ->onField("ds_title")->setFixedValue("Marcar Entregue")
      ->onField("ds_icon")->setFixedValue("fa fa-check")
      ->onField("id_bpm_workflow")->setFromOperation(-5)
      ->onField("id_bpm_step_origin")->setFromOperation(-4)
      ->onField("id_bpm_step_destination")->setFromOperation(-3)
      ->onField("ds_tag")->setFixedValue('deliver')
    ;

    $this->SeedTable("BPM_TRANSITION", batchSize: 1)
      ->onField("ds_key", true)->setByFunction(fn() => "trn-" . uniqid())
      ->onField("ds_title")->setFixedValue("Fechar")
      ->onField("ds_icon")->setFixedValue("fa fa-check")
      ->onField("id_bpm_workflow")->setFromOperation(-6)
      ->onField("id_bpm_step_origin")->setFromOperation(-4)
      ->onField("id_bpm_step_destination")->setFromOperation(-3)
      ->onField("ds_tag")->setFixedValue('close')
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
