<?php

namespace Orders\Services;

use SplitPHP\Database\Dao;
use SplitPHP\Service;
use SplitPHP\EventDispatcher;
use Throwable;
use Exception;

class Item extends Service
{
  private const TABLE = "ORD_ORDER_ITEM";

  public function create($data)
  {
    // Capture fields before whitelist strips them (menu-app sends these alternate names)
    $servingId      = $data['id_srv_serving'] ?? null;
    $callerLabel    = $data['ds_product_representation'] ?? null;

    $data = $this->getService('utils/misc')->dataWhiteList(
      $data,
      [
        'id_ord_order',
        'id_srv_serving_variant',
        'ds_serving_representation',
        'qt_quantity',
        'vl_price',
        'tx_notes',
      ]
    );

    $qty     = (float) ($data['qt_quantity'] ?? 1);
    $price   = 0;
    $variant = null;
    $serving = null;

    // Resolve variant: if only a serving ID was provided, find its default variant
    if (empty($data['id_srv_serving_variant']) && !empty($servingId)) {
      $variant = $this->findDefaultVariantByServing((int) $servingId);
      if ($variant) {
        $data['id_srv_serving_variant'] = $variant->id_srv_serving_variant;
      }
    }

    if (!empty($data['id_srv_serving_variant'])) {
      // Variant items: always compute prices server-side from the variant table
      $variant = $variant ?? $this->findVariant((int) $data['id_srv_serving_variant']);
      $price   = (float) ($variant->vl_price ?? 0);
      $serving = $this->findParentServing($variant);
    } else {
      // Combo items (no variant): accept the caller-provided price
      $price = (float) ($data['vl_price'] ?? 0);
    }

    // Auto-build ds_serving_representation when not provided by the caller
    if (empty($data['ds_serving_representation'])) {
      $data['ds_serving_representation'] = $this->buildRepresentationLabel($variant, $serving)
        ?: ($callerLabel ?: '');
    }

    $data['vl_price']            = $price;
    $data['vl_total']            = $qty * $price;
    $data['ds_key']              = 'itm-' . uniqid();
    $data['id_iam_user_created'] = $this->getService('iam/session')->getLoggedUser()?->id_iam_user;

    $record = $this->getDao(self::TABLE)->insert($data);

    // Itens que não requerem preparo iniciam direto em "Aguardando Entrega" (nr_step_order=1)
    $startOrder = (!empty($serving) && ($serving->do_requires_preparation ?? 'N') !== 'Y') ? 1 : null;
    $exec = $this->getService('bpm/wizard')->startWorkflow('order_item', $record->id_ord_order_item, $startOrder);
    $this->upd(
      ['ds_key' => $record->ds_key],
      ['id_bpm_execution' => $exec->id_bpm_execution]
    );
    $record->id_bpm_execution = $exec->id_bpm_execution;

    return $record;
  }

  public function list($params = [])
  {
    return $this->getDao(self::TABLE)
      ->bindParams($params)
      ->find("orders/item/read");
  }

  public function get($params = [])
  {
    return $this->getDao(self::TABLE)
      ->bindParams($params)
      ->first("orders/item/read");
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
        'id_srv_serving_variant',
        'ds_serving_representation',
        'qt_quantity',
        'vl_price',
        'vl_total',
        'id_bpm_execution',
        'tx_notes',
      ]
    );

    $data['id_iam_user_updated'] = $this->getService('iam/session')->getLoggedUser()?->id_iam_user;
    $data['dt_updated'] = date('Y-m-d H:i:s');

    return $this->getDao(self::TABLE)
      ->bindParams($filters)
      ->update($data);
  }

  // ─── Data Access Helpers ────────────────────────────────────

  /**
   * Fetches a serving variant by its primary key.
   */
  private function findVariant(int $variantId): ?object
  {
    return $this->getDao('SRV_SERVING_VARIANT')
      ->filter('id_srv_serving_variant')->equalsTo($variantId)
      ->first() ?: null;
  }

  /**
   * Fetches the parent serving for a given variant (for requires_preparation check).
   */
  private function findParentServing(?object $variant): ?object
  {
    if (empty($variant) || empty($variant->id_srv_serving)) return null;

    return $this->getDao('SRV_SERVING')
      ->filter('id_srv_serving')->equalsTo($variant->id_srv_serving)
      ->first() ?: null;
  }

  /**
   * Finds the default variant for a serving, or the first available one.
   * Used when the caller provides only id_srv_serving (e.g. menu-app).
   */
  private function findDefaultVariantByServing(int $servingId): ?object
  {
    // Try the default variant first
    $variant = $this->getDao('SRV_SERVING_VARIANT')
      ->filter('id_srv_serving')->equalsTo($servingId)
      ->and('do_default')->equalsTo('Y')
      ->first();

    // Fall back to any active variant for this serving
    if (empty($variant)) {
      $variant = $this->getDao('SRV_SERVING_VARIANT')
        ->filter('id_srv_serving')->equalsTo($servingId)
        ->first();
    }

    return $variant ?: null;
  }

  /**
   * Builds a human-readable item label from variant/serving data.
   * E.g. "Hambúrguer — Grande" or just "Hambúrguer" for default/single variants.
   */
  private function buildRepresentationLabel(?object $variant, ?object $serving): string
  {
    if (empty($variant) && empty($serving)) return '';

    $servingName = $serving->ds_name ?? '';
    $variantName = $variant->ds_name ?? '';

    // If the variant is the default ("Padrão") or matches the serving name, use just the serving name
    if (empty($variantName) || $variantName === 'Padrão' || $variantName === $servingName) {
      return $servingName ?: $variantName;
    }

    return $servingName ? "{$servingName} — {$variantName}" : $variantName;
  }

  /**
   * Deducts a canceled item's value from the parent order total.
   * Works within the current transaction without needing to re-query
   * item BPM states.
   */
  private function deductItemFromOrderTotal(object $canceledItem): void
  {
    $order = $this->getService('orders/order')->get([
      'id_ord_order' => $canceledItem->id_ord_order,
    ]);
    if (empty($order)) return;

    $newTotal = max(0, (float) $order->vl_amount - (float) $canceledItem->vl_total);

    $this->getService('orders/order')->upd(
      ['ds_key' => $order->ds_key],
      ['vl_amount' => $newTotal]
    );
  }

  /**
   * Signals the SSE Server that kitchen items have changed.
   * Publishes a 'kitchenItems' event via Redis PUBLISH.
   */
  public function signalKitchen(): void
  {
    try {
      $redis = $this->getService('infrastructure/redis');
      if ($redis->isAvailable()) {
        $redis->publish('kitchenItems');
      }
    } catch (\Throwable $e) {
      error_log('[signalKitchen] ' . $e->getMessage());
    }
  }

  /**
   * Signals the SSE Server that waiter items have changed.
   * Publishes a 'waiterItems' event via Redis PUBLISH.
   */
  public function signalWaiter(): void
  {
    try {
      $redis = $this->getService('infrastructure/redis');
      if ($redis->isAvailable()) {
        $redis->publish('waiterItems');
      }
    } catch (\Throwable $e) {
      error_log('[signalWaiter] ' . $e->getMessage());
    }
  }

  // BPM Step Rules Handler Functions:
  public function preparingInRules($execution = null)
  {
    $this->signalKitchen();

    // Push para a cozinha se o admin configurou alerta de novo item
    try {
      $settings = $this->getService('settings/settings')->contextObject('attendance');
      if (($settings->kitchenAlertNewItem ?? 'N') === 'Y') {
        $item = $this->get(['id_ord_order_item' => $execution->id_reference_entity_id]);
        if ($item) {
          $this->sendPushToKitchen([
            'title' => 'Novo Item em Preparo',
            'body'  => "{$item->ds_serving_representation} — Pedido #{$item->id_ord_order}",
            'link'  => '/kitchen',
          ]);
        }
      }
    } catch (Throwable $e) {
    }

    EventDispatcher::dispatch(
      fn() => true,
      'orders.item.transitioned',
      [(int) $execution->id_reference_entity_id, null, 'preparing']
    );
  }

  public function preparingOutRules($execution = null)
  {
    $this->signalKitchen();
  }

  public function waitDeliveryInRules($execution = null)
  {
    $record = $this->get(['id_ord_order_item' => $execution->id_reference_entity_id]);
    $this->getService('orders/order')->signalManager();
    $this->signalWaiter();

    // Push para garçons se configurado
    try {
      $settings = $this->getService('settings/settings')->contextObject('attendance');
      if (($settings->waiterAlertWaitDelivery ?? 'N') === 'Y') {
        $this->sendPushToWaiters($record);
      }
    } catch (Throwable $e) {
    }

    // Notifica o manager quando item de pedido DELIVERY chega em "aguardando entrega"
    try {
      $order = $this->getService('orders/order')->get(['id_ord_order' => $record->id_ord_order]);
      if (!empty($order) && empty($order->nr_tablenumber)) {
        $key = "order_wait_delivery_notified_{$record->id_ord_order}";
        $alreadySent = false;
        try {
          $redis = $this->getService('infrastructure/redis');
          if ($redis->isAvailable()) {
            $alreadySent = (bool) $redis->cacheGet($key);
          } else {
            throw new \Exception('fallback');
          }
        } catch (\Throwable $e) {
          $stash = $this->getService('multitenancy/stash');
          $alreadySent = (bool) ($stash->get($key) ?? false);
        }
        if (!$alreadySent) {
          $otherItemsFromTheSameOrder = $this->list([
            'id_ord_order' => $record->id_ord_order,
            'id_ord_order_item' => '$difr|' . $record->id_ord_order_item,
          ]);

          foreach ($otherItemsFromTheSameOrder as $itm)
            if ($itm->nr_step_order < 1)
              return;

          // All items are ready — mark as notified and send.
          try {
            $redis = $this->getService('infrastructure/redis');
            if ($redis->isAvailable()) $redis->cacheSet($key, true, 43200);
          } catch (\Throwable $e) {
            $stash = $this->getService('multitenancy/stash');
            $stash->set($key, true, 43200);
          }

          $this->getService('messaging/notification')->addToTeam('managers', [
            'ds_headline' => 'Pedido Delivery Aguardando Entrega',
            'ds_brief'    => "Pedido delivery #{$record->id_ord_order} está pronto e aguardando entrega.",
            'tx_content'  => "Todos os itens do pedido delivery #{$record->id_ord_order} estão prontos e aguardando entrega.",
            'do_important' => 'Y',
            'do_sendpush'  => 'Y',
          ]);
        }
      }
    } catch (Throwable $e) {
    }

    $prevStep = $this->resolvePreviousStep($execution->id_bpm_execution, 1);

    EventDispatcher::dispatch(
      fn() => true,
      'orders.item.transitioned',
      [(int) $execution->id_reference_entity_id, $prevStep?->ds_tag, 'waiting_delivery'],
      true
    );
  }

  public function waitDeliveryOutRules($execution = null)
  {
    $this->signalWaiter();
  }

  /**
   * True quando nenhum OUTRO item do mesmo pedido está numa etapa anterior a "Aguardando Entrega"
   * (nr_step_order < 1) — i.e., todos os itens já saíram do preparo. Usado para o aviso ao freguês
   * (spec 079). O próprio item chamador já está em "Aguardando Entrega".
   */
  public function allSiblingsWaitingOrBeyond(object $item): bool
  {
    $others = $this->list([
      'id_ord_order'      => $item->id_ord_order,
      'id_ord_order_item' => '$difr|' . $item->id_ord_order_item,
    ]);

    foreach ($others as $other) {
      if (($other->nr_step_order ?? 0) < 1) return false;
    }

    return true;
  }

  public function deliveredInRules($execution = null)
  {
    $this->getService('orders/order')->signalManager();

    $item = $this->get(['id_ord_order_item' => $execution->id_reference_entity_id]);
    $orderItems = $this->list([
      'id_ord_order' => $item->id_ord_order,
      'id_ord_order_item' => '$difr|' . $item->id_ord_order_item
    ]);

    foreach ($orderItems as $orderItem)
      if ($orderItem->nr_step_order < 2)
        return;

    $orderExec = $this->getDao('BPM_EXECUTION')
      ->filter('ds_reference_entity_name')->equalsTo('ORD_ORDER')
      ->and('id_reference_entity_id')->equalsTo($item->id_ord_order)
      ->first();

    Dao::flush();

    $this->getService('bpm/wizard')->transitionByTag($orderExec->ds_key, 'deliver');

    EventDispatcher::dispatch(
      fn() => true,
      'orders.item.transitioned',
      [(int) $execution->id_reference_entity_id, 'waiting_delivery', 'delivered']
    );
  }

  public function deliveredOutRules($execution = null)
  {
    return true;
  }

  public function canceledInRules($execution = null)
  {
    $this->getService('orders/order')->signalManager();
    $this->signalKitchen();
    $this->signalWaiter();

    $item = $this->get(['id_ord_order_item' => $execution->id_reference_entity_id]);
    if (empty($item))
      return;

    // Deduct canceled item value from order total.
    $this->deductItemFromOrderTotal($item);

    $otherOrderItems = $this->list([
      'id_ord_order' => $item->id_ord_order,
      'id_ord_order_item' => '$difr|' . $item->id_ord_order_item
    ]);

    foreach ($otherOrderItems as $orderItem)
      if ($orderItem->status_tag != 'canceled')
        return;

    $orderExec = $this->getDao('BPM_EXECUTION')
      ->filter('ds_reference_entity_name')->equalsTo('ORD_ORDER')
      ->and('id_reference_entity_id')->equalsTo($item->id_ord_order)
      ->first();

    Dao::flush();

    $this->getService('bpm/wizard')->transitionByTag($orderExec->ds_key, 'cancel');

    $prevStep = $this->resolvePreviousStep($execution->id_bpm_execution, 1);
    EventDispatcher::dispatch(
      fn() => true,
      'orders.item.transitioned',
      [(int) $execution->id_reference_entity_id, $prevStep?->ds_tag, 'canceled']
    );
  }

  public function canceledOutRules($execution = null)
  {
    return true;
  }

  /**
   * Verifica todos os itens de pedido ativos (preparing / waiting_delivery) e,
   * para cada um que ultrapassou o limite de tempo configurado, cria uma
   * notificação para o manager — deduplicada via stash (12 h).
   * Chamado ao final de cada ciclo de kitchenWatch(), waiterWatch() e managerWatch().
   */
  public function checkAndNotifyLateItems(): void
  {
    try {
      // Flush DAO persistence cache + commit open transaction so the
      // readonly connection sees up-to-date rows in this long-lived SSE process.
      Dao::flush();

      $settings = $this->getService('settings/settings')->contextObject('attendance');
      $preparationLimit   = isset($settings->preparationTimeLimit)   ? (int) $settings->preparationTimeLimit   : 0;
      $deliveryLimit      = isset($settings->deliveryTimeLimit)      ? (int) $settings->deliveryTimeLimit      : 0;
      $extDeliveryLimit   = isset($settings->externalDeliveryTimeLimit) ? (int) $settings->externalDeliveryTimeLimit : 0;

      // Nenhum limite configurado: nada a fazer
      if (!$preparationLimit && !$deliveryLimit && !$extDeliveryLimit) return;

      // Determine cache backend: Redis when available, stash file as fallback
      $useRedisCache = false;
      $redis = null;
      try {
        $redis = $this->getService('infrastructure/redis');
        $useRedisCache = $redis->isAvailable();
      } catch (\Throwable $e) {
      }
      $stash = $useRedisCache ? null : $this->getService('multitenancy/stash');
      $now   = time();

      // Helper closures for deduplication get/set
      $dedupGet = function (string $key) use ($useRedisCache, $redis, $stash) {
        return $useRedisCache ? $redis->cacheGet($key) : $stash->get($key);
      };
      $dedupSet = function (string $key, $value, ?int $ttl = null) use ($useRedisCache, $redis, $stash) {
        $useRedisCache ? $redis->cacheSet($key, $value, $ttl) : $stash->set($key, $value, $ttl);
      };

      // Itens em preparo
      if ($preparationLimit > 0) {
        $preparingItems = $this->list(['status_tag' => 'preparing']);
        $kitchenLateAlert = ($settings->kitchenAlertLateItem ?? 'N') === 'Y';
        foreach ($preparingItems as $item) {
          $ref = $item->dt_step_entered ?? $item->dt_created ?? null;
          if (!$ref) continue;
          $elapsedMin = (int) floor(($now - strtotime($ref)) / 60);
          if ($elapsedMin < $preparationLimit) continue;

          // Notificação ao manager (dedup key inclui a etapa para permitir
          // notificação independente em cada step do workflow)
          $key = "late_alert_preparing_{$item->id_ord_order_item}";
          if (!$dedupGet($key)) {
            $dedupSet($key, true, 43200);
            $mesa = !empty($item->nr_tablenumber) ? "Mesa {$item->nr_tablenumber}" : 'Delivery';
            $this->getService('messaging/notification')->addToTeam('managers', [
              'ds_headline'  => 'Item de pedido em atraso',
              'ds_brief'     => "{$mesa} — {$item->ds_serving_representation} (Pedido #{$item->id_ord_order}) está em preparo há {$elapsedMin} min.",
              'tx_content'   => "{$mesa} — {$item->ds_serving_representation} (Pedido #{$item->id_ord_order}) ultrapassou o limite de preparo ({$preparationLimit} min).",
              'do_important' => 'Y',
            ]);
          }

          // Push para a cozinha (deduplicado separadamente)
          if ($kitchenLateAlert) {
            $kitchenKey = "late_push_kitchen_item_{$item->id_ord_order_item}";
            if (!$dedupGet($kitchenKey)) {
              $dedupSet($kitchenKey, true, 43200);
              $mesa = !empty($item->nr_tablenumber) ? "Mesa {$item->nr_tablenumber}" : 'Delivery';
              $this->sendPushToKitchen([
                'title' => 'Item em Atraso!',
                'body'  => "{$item->ds_serving_representation} — {$mesa} está em preparo há {$elapsedMin} min.",
                'link'  => '/kitchen',
              ]);
            }
          }
        }
      }

      // Itens aguardando entrega
      if ($deliveryLimit > 0 || $extDeliveryLimit > 0) {
        $waitingItems = $this->list(['status_tag' => 'waiting_delivery']);
        foreach ($waitingItems as $item) {
          $isDelivery = empty($item->nr_tablenumber) || $item->nr_tablenumber == 0;
          $limit = $isDelivery ? $extDeliveryLimit : $deliveryLimit;
          if (!$limit) continue;

          $ref = $item->dt_step_entered ?? $item->dt_created ?? null;
          if (!$ref) continue;
          $elapsedMin = (int) floor(($now - strtotime($ref)) / 60);
          if ($elapsedMin < $limit) continue;

          $key = "late_alert_wait_delivery_{$item->id_ord_order_item}";
          if ($dedupGet($key)) continue;
          $dedupSet($key, true, 43200);

          $mesa = $isDelivery ? 'Delivery' : "Mesa {$item->nr_tablenumber}";
          $this->getService('messaging/notification')->addToTeam('managers', [
            'ds_headline'  => 'Item aguardando entrega em atraso',
            'ds_brief'     => "{$mesa} — {$item->ds_serving_representation} (Pedido #{$item->id_ord_order}) aguarda entrega há {$elapsedMin} min.",
            'tx_content'   => "{$mesa} — {$item->ds_serving_representation} (Pedido #{$item->id_ord_order}) ultrapassou o limite de entrega ({$limit} min).",
            'do_important' => 'Y',
          ]);
        }
      }

      // Commit notifications that were inserted during this tick
      Dao::flush();
    } catch (Throwable $e) {
      error_log('[checkAndNotifyLateItems] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    }
  }

  private function sendPushToKitchen(array $msg): void
  {
    try {
      // Busca todos os usuários com perfil 'kitchen'
      $kitchenProfile = $this->getService('iam/accessprofile')->get(['ds_tag' => 'kitchen']);
      if (empty($kitchenProfile)) return;

      $kitchenUserIds = $this->getDao('IAM_ACCESSPROFILE_USER')
        ->filter('id_iam_accessprofile')->equalsTo($kitchenProfile->id_iam_accessprofile)
        ->find("SELECT id_iam_user FROM IAM_ACCESSPROFILE_USER WHERE id_iam_accessprofile = ?id_iam_accessprofile?");

      foreach ($kitchenUserIds as $row) {
        try {
          $devices = $this->getService('iam/device')->getDevicesOfUser(['id_iam_user' => $row->id_iam_user]);
          foreach ($devices as $device) {
            try {
              $this->getService('messaging/push')->sendToDevice(['id_iam_device' => $device->id_iam_device], $msg);
            } catch (Throwable $t) {
            }
          }
        } catch (Throwable $e) {
        }
      }
    } catch (Throwable $e) {
    }
  }

  private function sendPushToWaiters(object $orderItem)
  {
    $order = $this->getService('orders/order')->get(['id_ord_order' => $orderItem->id_ord_order]);
    $waiters = $this->getService('attendance/waiter')->list();

    foreach ($waiters as $wtr) {
      try {
        $usrFilter = [
          'id_iam_user' => $this->getService('iam/user')->get(['id_iam_user' => $wtr->id_iam_user])?->id_iam_user
        ];
        $devices = $this->getService('iam/device')->getDevicesOfUser($usrFilter);

        $msg = [
          'title' => 'Pedido Aguardando Entrega',
          'body' => "{$orderItem->ds_serving_representation} do pedido nº {$order->id_ord_order} para a mesa {$order->nr_tablenumber} está aguardando entrega.",
          'link' => "/waiter",
        ];

        foreach ($devices as $device) {
          try {
            $deviceTarget = [
              'id_iam_device' => $device->id_iam_device
            ];
            $this->getService('messaging/push')->sendToDevice($deviceTarget, $msg);
          } catch (Throwable $t) {
            // Ignorar falhas individuais
          }
        }
      } catch (Throwable $e) {
        continue;
      }
    }
  }

  private function resolvePreviousStep(int $execId, int $numSteps): ?int
  {
    Dao::flush();

    $track = $this->getService('bpm/step')->trackRecord(['id_bpm_execution' => $execId]);
    if (empty($track)) return null;

    $trackLength = count($track);
    $currentStepIndex = $trackLength > 0 ? $trackLength - 1 : 0;
    $previousStepIndex = $currentStepIndex - $numSteps;

    if ($previousStepIndex < 0 || $previousStepIndex >= count($track)) return null;

    return $track[$previousStepIndex]->id_bpm_step;
  }
}
