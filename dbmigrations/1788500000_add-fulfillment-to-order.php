<?php

namespace Orders\Migrations;

use SplitPHP\DbManager\Migration;

class AddFulfillmentToOrder extends Migration
{
  public function apply()
  {
    // Modalidade explícita do pedido (spec 078): espelha CTP_DRAFT_ORDER.ds_fulfillment_type.
    // 'delivery' | 'pickup' | 'table'. Nullable p/ pedidos legados — a classificação desses
    // (retirada vinda do chatbot, sem endereço nem mesa) é feita no read-path do painel e na
    // derivação de orders/order->create (backfill de dados não é suportado pela DSL declarativa
    // de migrations/seeds do framework; ver PLAN.md — Divergência técnica).
    $this->Table("ORD_ORDER")
      ->string("ds_fulfillment_type", 10)->nullable()->setDefaultValue(null)
    ;
  }
}
