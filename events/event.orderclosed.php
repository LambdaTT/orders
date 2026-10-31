<?php

namespace Orders\Events;

use SplitPHP\Event;
use SplitPHP\Helpers;

/**
 * Class OrderClosed
 *
 * Triggered when an order reaches a terminal state (completed or canceled),
 * i.e. the order is "closed". Carries the order id and the terminal reason so
 * listeners in other bounded contexts can react — e.g. the chatbot closing the
 * conversation linked to the order.
 *
 * @package Orders\Events
 */
class OrderClosed extends Event
{
  /**
   * The name of the event.
   */
  public const EVENT_NAME = 'orders.order.closed';

  /**
   * @var int $orderId The id_ord_order that reached a terminal state.
   */
  private int $orderId;

  /**
   * @var string $reason Terminal reason: 'completed' | 'canceled'.
   */
  private string $reason;

  /**
   * OrderClosed constructor.
   *
   * @param int    $orderId The id_ord_order that reached a terminal state.
   * @param string $reason  Terminal reason: 'completed' | 'canceled'.
   */
  public function __construct(int $orderId, string $reason)
  {
    $evtName = $this->getName();
    if($this->debugMode) Helpers::Log()->common('event_dispatcher', "Event '{$evtName}' intantiated with orderId: {$orderId}, reason: {$reason}");
    
    $this->orderId = $orderId;
    $this->reason  = $reason;
  }

  /**
   * Returns a string representation of the event.
   *
   * @return string
   */
  public function __toString(): string
  {
    return 'Event: ' . self::EVENT_NAME . ' (order: ' . $this->orderId . ', reason: ' . $this->reason . ')';
  }

  /**
   * Returns the closed order's id and terminal reason.
   *
   * @return object { id_ord_order: int, reason: string }
   */
  public function info(): object
  {
    return (object) [
      'id_ord_order' => $this->orderId,
      'reason'       => $this->reason,
    ];
  }
}
