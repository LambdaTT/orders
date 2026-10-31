<?php

namespace Orders\Seeds;

use SplitPHP\DbManager\Seed;
use SplitPHP\Database\DbVocab;
use SplitPHP\Database\Database;
use SplitPHP\Database\Sqlobj;

class PopulateDeliveryTypeOrderBpmWorkflow extends Seed
{
  public function apply()
  {
    // Idempotency guard: only seed the "delivery" workflow when one does not already exist.
    // An application may intentionally not use a separate delivery workflow (relying solely on the
    // "order" workflow), or may have removed it — re-seeding would reintroduce an unwanted/duplicate
    // "Fluxo da Entrega". Guarding on the existence of the ds_tag='delivery' workflow keeps this seed
    // safe to re-apply (and a no-op when the seed-tracking row is cleared while the workflow stands).
    $existing = Database::getCnn('main')->runsql(
      new Sqlobj("SELECT id_bpm_workflow FROM `BPM_WORKFLOW` WHERE ds_tag = 'delivery' LIMIT 1", 'BPM_WORKFLOW')
    );
    if (!empty($existing)) return;

    // ORDER WORKFLOW
    $this->SeedTable("BPM_WORKFLOW", batchSize: 1)
      ->onField("ds_key", true)->setByFunction(fn() => "wfl-" . uniqid())
      ->onField("ds_tag")->setFixedValue("delivery")
      ->onField("ds_title")->setFixedValue("Fluxo da Entrega")
      ->onField("ds_reference_entity_name")->setFixedValue("ORD_ORDER")
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
