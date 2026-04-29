<?php

namespace Cartappio\Services;

use SplitPHP\Exceptions\FailedValidation;
use SplitPHP\Service;
use SplitPHP\Database\Dao;

class Order extends Service
{
  private const TABLE = "ORD_ORDER";

  public function create($data)
  {
    $items = $data['items'] ?? [];

    // Calculate vl_amount from items (qty × product price)
    $vlAmount = 0;
    foreach ($items as $item) {
      $qty   = (float) ($item['qt_quantity'] ?? 1);
      $price = 0;

      // Prefer the price coming from the payload; fall back to the product table
      if (!empty($item['vl_price'])) {
        $price = (float) $item['vl_price'];
      } elseif (!empty($item['id_prd_product'])) {
        $product = $this->getDao('PRD_PRODUCT')
          ->filter('id_prd_product')->equalsTo($item['id_prd_product'])
          ->first();
        $price = (float) ($product->vl_price ?? 0);
      }

      $vlAmount += $qty * $price;
    }

    $data = $this->getService('utils/misc')->dataWhiteList(
      $data,
      ['nr_tablenumber', 'tx_delivery_address', 'nr_comanda']
    );

    $data['vl_amount']           = $vlAmount;
    $data['ds_key']              = 'ord-' . uniqid();
    $data['id_iam_user_created'] = $this->getService('iam/session')->getLoggedUser()?->id_iam_user;

    $record = $this->getDao(self::TABLE)->insert($data);

    // Start the BPM workflow for this order
    $exec = $this->getService('bpm/wizard')->startWorkflow('order', $record->id_ord_order);
    $this->upd(
      ['ds_key' => $record->ds_key],
      ['id_bpm_execution' => $exec->id_bpm_execution]
    );
    $record->id_bpm_execution = $exec->id_bpm_execution;

    if (empty($record->nr_tablenumber)) {
      try {
        $this->getService('messaging/notification')->addToTeam('managers', [
          'ds_headline' => 'Novo Pedido Delivery',
          'ds_brief' => 'Um novo pedido delivery (#' . $record->id_ord_order . ') acabou de chegar.',
          'tx_content' => 'Um novo pedido delivery (#' . $record->id_ord_order . ') acabou de chegar.',
          'do_sendpush' => 'Y',
          'do_important' => 'Y'
        ]);
      } catch (\Exception $e) {
      }
    }

    return $record;
  }

  public function list($params = [])
  {
    return $this->getDao(self::TABLE)
      ->bindParams($params)
      ->find("orders/order/read");
  }

  public function listWithItems($params = [])
  {
    // Single query: read-with-items.sql JOINs orders with items inline.
    // The <MainQuery> tags ensure LIMIT/OFFSET wrap the entire joined set,
    // and item columns are aliased with `item_` prefix.
    $rows = $this->getDao(self::TABLE)
      ->bindParams($params)
      ->find("orders/order/read-with-items");

    if (empty($rows)) return [];

    // Build the order→items tree from the flat joined rows.
    $grouped = [];
    foreach ($rows as $row) {
      $orderId = $row->id_ord_order;

      if (!isset($grouped[$orderId])) {
        $grouped[$orderId] = (object)[
          'id_ord_order'         => $row->id_ord_order,
          'ds_key'               => $row->ds_key,
          'dt_created'           => $row->dt_created,
          'dt_updated'           => $row->dt_updated,
          'nr_tablenumber'       => $row->nr_tablenumber,
          'nr_comanda'           => $row->nr_comanda,
          'id_bpm_execution'     => $row->id_bpm_execution,
          'vl_amount'            => $row->vl_amount,
          'vlAmount'             => $row->vlAmount,
          'dtCreated'            => $row->dtCreated,
          'tableNumber'          => $row->tableNumber,
          'comandaLabel'         => $row->comandaLabel ?? null,
          'status_tag'           => $row->status_tag,
          'status_title'         => $row->status_title,
          'executionKey'         => $row->executionKey,
          'tx_delivery_address'  => $row->tx_delivery_address,
          'items'                => [],
        ];
      }

      // Append item if the row actually has one (LEFT JOIN may yield NULLs).
      if (!empty($row->id_ord_order_item)) {
        $grouped[$orderId]->items[] = (object)[
          'id_ord_order_item'        => $row->id_ord_order_item,
          'id_ord_order'             => $row->id_ord_order,
          'ds_key'                   => $row->item_ds_key,
          'dt_created'               => $row->item_dt_created,
          'dt_updated'               => $row->item_dt_updated,
          'id_prd_product'           => $row->item_id_prd_product,
          'ds_product_representation' => $row->item_ds_product_representation,
          'qt_quantity'              => $row->item_qt_quantity,
          'vl_price'                 => $row->item_vl_price,
          'vl_total'                 => $row->item_vl_total,
          'dtCreated'                => $row->item_dtCreated,
          'unitPrice'                => $row->item_unitPrice,
          'vlTotal'                  => $row->item_vlTotal,
          'nr_step_order'            => $row->item_nr_step_order,
          'status_tag'               => $row->item_status_tag,
          'status_title'             => $row->item_status_title,
          'executionKey'             => $row->item_executionKey,
          'dt_step_entered'          => $row->item_dt_step_entered,
          'do_requires_preparation'  => $row->item_do_requires_preparation,
          'nr_tablenumber'           => $row->nr_tablenumber,
          'tx_delivery_address'      => $row->tx_delivery_address,
        ];
      }
    }

    return array_values($grouped);
  }


  public function get($params = [])
  {
    return $this->getDao(self::TABLE)
      ->bindParams($params)
      ->first("orders/order/read");
  }

  public function remove($params = [])
  {
    return $this->getDao(self::TABLE)
      ->bindParams($params)
      ->delete();
  }

  public function upd($filters = [], $data = [])
  {
    $data = $this->getService('utils/misc')->dataWhiteList(
      $data,
      [
        'nr_tablenumber',
        'vl_amount',
        'vl_discount',
        'id_bpm_execution',
        'tx_delivery_address',
        'nr_comanda',
      ]
    );

    $data['id_iam_user_updated'] = $this->getService('iam/session')->getLoggedUser()?->id_iam_user;
    $data['dt_updated'] = date('Y-m-d H:i:s');

    return $this->getDao(self::TABLE)
      ->bindParams($filters)
      ->update($data);
  }

  /**
   * Signals the SSE Server that manager orders have changed.
   * Publishes a 'managerOrders' event via Redis PUBLISH.
   */
  public function signalManager(): void
  {
    try {
      $redis = $this->getService('infrastructure/redis');
      if ($redis->isAvailable()) {
        $redis->publish('managerOrders');
      }
    } catch (\Throwable $e) {
      error_log('[signalManager] ' . $e->getMessage());
    }
  }

  public function inProgressInRules($execution = null)
  {
    $this->signalManager();
  }

  public function inProgressOutRules($execution = null)
  {
    return true;
  }

  public function deliveredInRules($execution = null)
  {
    // Check directly from DB (no DAO cache) whether any item is still pending.
    $orderId = $execution->id_reference_entity_id;
    $sql = "
      SELECT COUNT(*) AS cnt
      FROM ORD_ORDER_ITEM oi
      JOIN BPM_EXECUTION oiex ON oiex.id_reference_entity_id = oi.id_ord_order_item
        AND oiex.ds_reference_entity_name = 'ORD_ORDER_ITEM'
      JOIN BPM_STEP ois ON ois.id_bpm_step = oiex.id_bpm_step_current
      WHERE oi.id_ord_order = {$orderId}
        AND ois.nr_step_order < 2
    ";
    $result = $this->getDao(self::TABLE)->find($sql);
    $pendingCount = (int) ($result[0]->cnt ?? 0);
    if ($pendingCount > 0) {
      throw new FailedValidation("Há itens neste pedido que ainda não foram entregues.");
    }
  }

  public function deliveredOutRules($execution = null)
  {
    return true;
  }

  public function completedInRules($execution = null)
  {
    $this->signalManager();

    // Signal customer app to clean up localStorage for this comanda
    try {
      $order = $this->get(['id_ord_order' => $execution->id_reference_entity_id]);
      if (!empty($order) && !empty($order->nr_comanda)) {
        $redis = $this->getService('infrastructure/redis');
        if ($redis->isAvailable()) {
          $redis->publish('customerComandaClosed', [
            'nr_comanda' => $order->nr_comanda,
          ]);
        }
      }
    } catch (\Throwable $e) {
      error_log('[completedInRules] signalCustomer: ' . $e->getMessage());
    }
  }

  public function canceledInRules($execution = null)
  {
    $itemWkflow = $this->getService('bpm/workflow')->get(['ds_tag' => 'order_item']);
    $itemCancelTransition = $this->getService('bpm/transition')->get([
      'id_bpm_workflow' => $itemWkflow->id_bpm_workflow,
      'ds_tag' => 'cancel'
    ]);

    $items = $this->getService('orders/item')->list([
      'id_ord_order' => $execution->id_reference_entity_id,
      'status_tag' => '$difr|canceled'
    ]);

    foreach ($items as $item) {
      $itemExecution = $this->getDao('BPM_EXECUTION')
        ->filter('ds_reference_entity_name')->equalsTo('ORD_ORDER_ITEM')
        ->and('id_reference_entity_id')->equalsTo($item->id_ord_order_item)
        ->first();

      $this->getService('bpm/wizard')->transition($itemExecution->ds_key, $itemCancelTransition->ds_key);
    }
  }

  /**
   * Pre-checkout: prepares an order for finalization.
   *
   * 1. Cancels every item that has not yet reached a terminal step
   *    (nr_step_order < 2, i.e. not 'delivered' and not 'canceled').
   * 2. Advances the order from 'in_progress' to 'delivered' using the
   *    existing 'deliver' BPM transition, so that the normal 'close'
   *    transition (delivered → completed) can be called afterwards.
   *
   * @param string $executionKey The BPM execution key of the order.
   */
  public function preCheckout(string $executionKey): void
  {
    // Resolve the order entity from its BPM execution key
    $orderExec = $this->getDao('BPM_EXECUTION')
      ->filter('ds_key')->equalsTo($executionKey)
      ->first();

    if (empty($orderExec))
      throw new \Exception("Execução de pedido não encontrada: {$executionKey}");

    // Fetch all items of this order
    $items = $this->getService('orders/item')->list([
      'id_ord_order' => $orderExec->id_reference_entity_id,
    ]);

    // Resolve the 'cancel' transition for the order_item workflow once
    $itemWorkflow = $this->getService('bpm/workflow')->get(['ds_tag' => 'order_item']);
    $cancelTransition = $this->getService('bpm/transition')->get([
      'id_bpm_workflow' => $itemWorkflow->id_bpm_workflow,
      'ds_tag'          => 'cancel',
    ]);

    // Cancel every item that has not yet reached a terminal step
    foreach ($items as $item) {
      if ($item->nr_step_order >= 2) continue; // already delivered or canceled — skip

      $itemExec = $this->getDao('BPM_EXECUTION')
        ->filter('ds_reference_entity_name')->equalsTo('ORD_ORDER_ITEM')
        ->and('id_reference_entity_id')->equalsTo($item->id_ord_order_item)
        ->first();

      if ($itemExec) {
        $this->getService('bpm/wizard')->transition(
          $itemExec->ds_key,
          $cancelTransition->ds_key
        );
      }
    }

    // Commit item cancellations and clear DAO persistence cache so that
    // the deliveredInRules guard sees the updated item steps when queried.
    Dao::flush();

    // After canceling pending items, advance the order BPM.
    // Try deliver transition; skip if already in a terminal step.
    $transitions = $this->getService('bpm/wizard')->availableTransitions($executionKey);
    $tagMap = [];
    foreach ($transitions as $tr) {
      $tagMap[$tr->ds_tag] = $tr;
    }

    // in_progress → delivered (only if available; skip if already delivered/canceled)
    if (isset($tagMap['deliver'])) {
      $this->getService('bpm/wizard')->transitionByTag($executionKey, 'deliver');
    }
  }
}
