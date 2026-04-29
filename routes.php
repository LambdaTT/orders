<?php

namespace Cartappio\Routes;

use SplitPHP\Request;
use SplitPHP\WebService;

class Orders extends WebService
{
  public function init()
  {
    $this->setAntiXsrfValidation(false);

    ////////////
    // ORDERS //
    ////////////
    // Pre-checkout: cancels non-delivered items and advances order to 'delivered',
    // so the frontend can then call the normal BPM 'finish' transition.
    $this->addEndpoint('POST', '/v1/order/?executionKey?/pre-checkout', function ($executionKey) {
      $this->getService('orders/order')->preCheckout($executionKey);

      return $this->response->withStatus(204);
    });

    $this->addEndpoint('POST', '/v1/order', function (Request $req) {
      $payload = $req->getBody();
      $record = $this->getService('orders/order')->create($payload);

      // If items passed in payload, create items too (BPM is started inside each service)
      if (!empty($payload['items']) && is_array($payload['items'])) {
        foreach ($payload['items'] as $item) {
          $item['id_ord_order'] = $record->id_ord_order;
          $this->getService('orders/item')->create($item);
        }
      }

      return $this->response
        ->withStatus(201)
        ->withData($record);
    });

    $this->addEndpoint('GET', '/v1/order/with-items', function ($params) {
      $records = $this->getService('orders/order')->listWithItems($params);

      return $this->response
        ->withStatus(200)
        ->withData($records);
    });

    $this->addEndpoint('GET', '/v1/order/?key?', function ($key) {
      $filters = ['ds_key' => $key];
      $record = $this->getService('orders/order')->get($filters);

      if (empty($record))
        return $this->response->withStatus(404);

      return $this->response
        ->withStatus(200)
        ->withData($record);
    });

    $this->addEndpoint('GET', '/v1/order', function ($params) {
      $records = $this->getService('orders/order')->list($params);

      return $this->response
        ->withStatus(200)
        ->withData($records);
    });

    $this->addEndpoint('DELETE', '/v1/order/?key?', function ($key) {
      $filters = ['ds_key' => $key];
      $rowsAffected = $this->getService('orders/order')->remove($filters);

      if ($rowsAffected < 1)
        return $this->response->withStatus(404);

      return $this->response->withStatus(204);
    });

    $this->addEndpoint('PUT', '/v1/order/?key?', function ($key, Request $req) {
      $payload = $req->getBody();
      $filters = ['ds_key' => $key];
      $rowsAffected = $this->getService('orders/order')->upd($filters, $payload);

      if ($rowsAffected < 1)
        return $this->response->withStatus(404);

      return $this->response->withStatus(204);
    });

    /////////////////
    // ORDER ITEMS //
    /////////////////
    $this->addEndpoint('POST', '/v1/item', function ($params) {
      $record = $this->getService('orders/item')->create($params);

      return $this->response
        ->withStatus(201)
        ->withData($record);
    });

    $this->addEndpoint('GET', '/v1/item/?key?', function ($key) {
      $filters = ['ds_key' => $key];
      $record = $this->getService('orders/item')->get($filters);

      if (empty($record))
        return $this->response->withStatus(404);

      return $this->response
        ->withStatus(200)
        ->withData($record);
    });

    $this->addEndpoint('GET', '/v1/item', function ($params) {
      $records = $this->getService('orders/item')->list($params);

      return $this->response
        ->withStatus(200)
        ->withData($records);
    });

    $this->addEndpoint('POST', '/v1/item/late-alert', function (Request $req) {
      if (!$this->getService('iam/session')->authenticate(false)) return $this->response->withStatus(401);

      $data = $req->getBody();
      if (empty($data['id_entity'])) return $this->response->withStatus(400);

      $stash = $this->getService('multitenancy/stash');
      $key = "late_alert_sent_late_item_{$data['id_entity']}";

      if ($stash->get($key)) {
        return $this->response->withStatus(204);
      }

      // Mark as notified for 12 hours (43200 seconds)
      $stash->set($key, true, 43200);

      $notificationData = [
        'ds_headline' => $data['headline'] ?? '',
        'ds_brief' => $data['brief'] ?? '',
        'tx_content' => $data['content'] ?? '',
        'do_important' => 'Y',
      ];

      $this->getService('messaging/notification')->addToTeam('managers', $notificationData);

      return $this->response->withStatus(201);
    });

    $this->addEndpoint('DELETE', '/v1/item/?key?', function ($key) {
      $filters = ['ds_key' => $key];
      $rowsAffected = $this->getService('orders/item')->remove($filters);

      if ($rowsAffected < 1)
        return $this->response->withStatus(404);

      return $this->response->withStatus(204);
    });

    $this->addEndpoint('PUT', '/v1/item/?key?', function ($key, Request $req) {
      $payload = $req->getBody();
      $filters = ['ds_key' => $key];
      $rowsAffected = $this->getService('orders/item')->upd($filters, $payload);

      if ($rowsAffected < 1)
        return $this->response->withStatus(404);

      return $this->response->withStatus(204);
    });
  }
}
