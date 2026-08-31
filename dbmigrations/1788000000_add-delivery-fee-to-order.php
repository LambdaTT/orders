<?php

namespace Orders\Migrations;

use SplitPHP\DbManager\Migration;

/**
 * Taxa de entrega por pedido.
 *
 * vl_delivery_fee guarda a taxa de entrega COBRADA naquele pedido (snapshot no momento da criação,
 * a partir das Definições de Atendimento). Ela JÁ está composta no vl_amount (que é o total final:
 * itens líquidos de desconto + taxa) — esta coluna existe para histórico/auditoria, deixando
 * explícito quanto do vl_amount é frete. Retirada/mesa = 0.
 */
class AddDeliveryFeeToOrder extends Migration
{
  public function apply()
  {
    $this->Table("ORD_ORDER")
      ->float("vl_delivery_fee")->nullable()->setDefaultValue(0)
    ;
  }
}
