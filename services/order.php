<?php

namespace Orders\Services;

use SplitPHP\Exceptions\FailedValidation;
use SplitPHP\Service;
use SplitPHP\Database\Dao;
use SplitPHP\EventDispatcher;

class Order extends Service
{
  private const TABLE = "ORD_ORDER";

  public function create($data)
  {
    $items  = $data['items'] ?? [];
    $silent = !empty($data['do_silent']);

    // Calculate vl_amount from items (qty × variant price)
    $vlAmount = 0;
    foreach ($items as $item) {
      $qty   = (float) ($item['qt_quantity'] ?? 1);
      $price = 0;

      // Prefer the price coming from the payload; fall back to the variant table
      if (!empty($item['vl_price'])) {
        $price = (float) $item['vl_price'];
      } elseif (!empty($item['id_srv_serving_variant'])) {
        $variant = $this->getDao('SRV_SERVING_VARIANT')
          ->filter('id_srv_serving_variant')->equalsTo($item['id_srv_serving_variant'])
          ->first();
        $price = (float) ($variant->vl_price ?? 0);
      }

      $vlAmount += $qty * $price;
    }

    $data = $this->getService('utils/misc')->dataWhiteList(
      $data,
      ['nr_tablenumber', 'tx_delivery_address', 'nr_comanda', 'vl_discount']
    );

    // Taxa de entrega (snapshot): pedido com endereço de entrega recebe a taxa configurada em
    // Definições → Atendimento; retirada/mesa = 0. A taxa COMPÕE o total (vl_amount = itens + taxa)
    // e fica registrada em vl_delivery_fee para histórico/auditoria (o breakdown do vl_amount).
    $deliveryFee = 0.0;
    if (!empty($data['tx_delivery_address'])) {
      $settings    = $this->getService('settings/settings')->contextObject('attendance');
      $deliveryFee = (float) ($settings->deliveryFee ?? 0);
    }

    $data['vl_delivery_fee']     = $deliveryFee;
    $data['vl_amount']           = $vlAmount + $deliveryFee;
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

    // Notify managers of new delivery orders — skip when do_silent is set
    // (manual orders created by the panel user don't need notifications).
    if (!$silent && empty($record->nr_tablenumber)) {
      $this->notifyNewOrder($record);
    }

    return $record;
  }

  /**
   * Sends the "Novo Pedido Delivery" push notification to the 'managers' team.
   */
  public function notifyNewOrder(object $order): void
  {
    try {
      $this->getService('messaging/notification')->addToTeam('managers', [
        'ds_headline' => 'Novo Pedido Delivery',
        'ds_brief' => 'Um novo pedido delivery (#' . $order->id_ord_order . ') acabou de chegar.',
        'tx_content' => 'Um novo pedido delivery (#' . $order->id_ord_order . ') acabou de chegar.',
        'do_sendpush' => 'Y',
        'do_important' => 'Y'
      ]);
    } catch (\Exception $e) {
    }
  }

  public function list($params = [])
  {
    return $this->getDao(self::TABLE)
      ->bindParams($params)
      ->find("orders/order/read");
  }

  public function listWithItems($params = [])
  {
    // The SQL uses a subquery for orders with #orderFilters# placeholder.
    // Filters (WHERE) go into the subquery via placeholder to narrow orders first.
    // Sort/pagination go global so the framework appends LIMIT after <MainQuery>.
    $paginationKeys = ['$page', '$limit', '$limit_multiplier', '$sort_by', '$sort_direction'];
    $paginationParams = [];
    $filterParams = [];

    foreach ($params as $k => $v) {
      if (in_array($k, $paginationKeys)) {
        $paginationParams[$k] = $v;
      } else {
        $filterParams[$k] = $v;
      }
    }

    $dao = $this->getDao(self::TABLE);

    if (!empty($filterParams)) {
      $dao->bindParams($filterParams, 'orderFilters');
    }
    if (!empty($paginationParams)) {
      $dao->bindParams($paginationParams);
    }

    $rows = $dao->find("orders/order/read-with-items");

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
          'id_ord_order_item'          => $row->id_ord_order_item,
          'id_ord_order'               => $row->id_ord_order,
          'ds_key'                     => $row->item_ds_key,
          'dt_created'                 => $row->item_dt_created,
          'dt_updated'                 => $row->item_dt_updated,
          'id_srv_serving_variant'     => $row->item_id_srv_serving_variant,
          'ds_serving_representation'  => $row->item_ds_serving_representation,
          'qt_quantity'                => $row->item_qt_quantity,
          'vl_price'                   => $row->item_vl_price,
          'vl_total'                   => $row->item_vl_total,
          'tx_notes'                   => $row->item_tx_notes,
          'dtCreated'                  => $row->item_dtCreated,
          'unitPrice'                  => $row->item_unitPrice,
          'vlTotal'                    => $row->item_vlTotal,
          'nr_step_order'              => $row->item_nr_step_order,
          'status_tag'                 => $row->item_status_tag,
          'status_title'               => $row->item_status_title,
          'executionKey'               => $row->item_executionKey,
          'dt_step_entered'            => $row->item_dt_step_entered,
          'do_requires_preparation'    => $row->item_do_requires_preparation,
          'nr_tablenumber'             => $row->nr_tablenumber,
          'tx_delivery_address'        => $row->tx_delivery_address,
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

  /**
   * Edits an existing order: updates address, replaces all items.
   *
   * 1. Updates the order's delivery address.
   * 2. Removes all existing items and their BPM executions.
   * 3. Creates new items from the payload.
   * 4. Recalculates vl_amount.
   */
  public function editOrder(string $orderKey, array $data): void
  {
    $order = $this->get(['ds_key' => $orderKey]);
    if (empty($order)) {
      throw new FailedValidation("Pedido não encontrado.");
    }

    if (($order->status_tag ?? '') !== 'in_progress') {
      throw new FailedValidation("Somente pedidos em andamento podem ser editados.");
    }

    $newItems = $data['items'] ?? [];

    // 1. Update order-level fields (address, notes).
    $this->upd(
      ['ds_key' => $orderKey],
      ['tx_delivery_address' => $data['tx_delivery_address'] ?? $order->tx_delivery_address]
    );

    // 2. Remove existing items and their BPM executions.
    $this->removeOrderItems($order->id_ord_order);

    // 3. Create new items (each starts its own BPM workflow).
    foreach ($newItems as $item) {
      $item['id_ord_order'] = $order->id_ord_order;
      $this->getService('orders/item')->create($item);
    }

    // 4. Recalculate total from the newly created items.
    $this->recalculateTotal($order);
  }

  /**
   * Removes all items of an order along with their BPM executions.
   */
  private function removeOrderItems(int $orderId): void
  {
    $items = $this->getService('orders/item')->list([
      'id_ord_order' => $orderId,
    ]);

    foreach ($items as $item) {
      // Remove BPM execution (cascades to BPM_STEP_TRACKING via FK).
      $this->getService('bpm/wizard')->removeExecution([
        'ds_reference_entity_name' => 'ORD_ORDER_ITEM',
        'id_reference_entity_id'   => $item->id_ord_order_item,
      ]);
    }

    // Remove all item rows.
    $this->getService('orders/item')->remove(['id_ord_order' => $orderId]);
  }

  /**
   * Recalculates vl_amount from the current items of an order.
   */
  private function recalculateTotal(object $order): void
  {
    $items = $this->getService('orders/item')->list([
      'id_ord_order' => $order->id_ord_order,
    ]);

    $total = 0;
    foreach ($items as $item) {
      $total += (float) ($item->vl_total ?? 0);
    }

    // Invariante: vl_amount = itens + taxa de entrega. Preserva o snapshot vl_delivery_fee do
    // pedido (não relê o setting numa edição — a taxa cobrada é a do momento da criação).
    $total += (float) ($order->vl_delivery_fee ?? 0);

    $this->upd(['ds_key' => $order->ds_key], ['vl_amount' => $total]);
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
    $this->signalManager();

    $orderId = $execution->id_reference_entity_id;

    // Fetch non-delivered, non-canceled items for this order.
    $items = $this->getService('orders/item')->list([
      'id_ord_order' => $orderId,
      'status_tag'   => '$notin|delivered|canceled',
    ]);

    if (empty($items)) return;

    // Items still in "preparing" (step 0) must NOT be auto-skipped.
    $this->blockIfItemsStillPreparing($items);

    // Auto-deliver items that are in "waiting_delivery" (step 1).
    $this->autoDeliverPendingItems($items);
  }

  /**
   * Throws if any item is still in the "preparing" step (nr_step_order = 0).
   * Those items require explicit kitchen completion before the order can
   * be marked as delivered.
   */
  private function blockIfItemsStillPreparing(array $items): void
  {
    foreach ($items as $item) {
      if (($item->nr_step_order ?? 999) < 1) {
        throw new FailedValidation(
          "Há itens neste pedido que ainda estão em preparo."
        );
      }
    }
  }

  /**
   * Auto-advances every "waiting_delivery" item to "delivered" via BPM
   * transition so the order-level "deliver" transition can proceed.
   */
  private function autoDeliverPendingItems(array $items): void
  {
    $itemWorkflow = $this->getService('bpm/workflow')->get(['ds_tag' => 'order_item']);
    $deliverTransition = $this->getService('bpm/transition')->get([
      'id_bpm_workflow' => $itemWorkflow->id_bpm_workflow,
      'ds_tag'          => 'deliver',
    ]);

    foreach ($items as $item) {
      if (($item->nr_step_order ?? 999) != 1) continue; // only waiting_delivery

      $itemExec = $this->getDao('BPM_EXECUTION')
        ->filter('ds_reference_entity_name')->equalsTo('ORD_ORDER_ITEM')
        ->and('id_reference_entity_id')->equalsTo($item->id_ord_order_item)
        ->first();

      if ($itemExec) {
        $this->getService('bpm/wizard')->transition(
          $itemExec->ds_key,
          $deliverTransition->ds_key
        );
      }
    }

    // Flush so the order-level transition sees up-to-date item states.
    Dao::flush();
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

    // Pedido concluído = pedido fechado. Notifica outros contextos (ex.: o chatbot encerra a
    // conversa vinculada). Direção de dependência: orders emite, quem se importa escuta.
    EventDispatcher::dispatch(
      fn() => true,
      'orders.order.closed',
      [(int) $execution->id_reference_entity_id, 'completed']
    );
  }

  public function canceledInRules($execution = null)
  {
    $this->signalManager();

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

    // Pedido cancelado = pedido fechado (estado terminal). Mesmo evento do concluído, com reason
    // distinto, para quem quiser diferenciar.
    EventDispatcher::dispatch(
      fn() => true,
      'orders.order.closed',
      [(int) $execution->id_reference_entity_id, 'canceled']
    );
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
