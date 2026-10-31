<?php

namespace Orders\Events;

use SplitPHP\Event;
use SplitPHP\Helpers;

/**
 * Class OrderTransitioned
 *
 * Triggered when an order transitions to a non-terminal step (!completed or !canceled),
 * i.e. the order is "In Progress". Carries the order id, the old and the new steps so
 * listeners in other bounded contexts can react — e.g. the chatbot closing the
 * conversation linked to the order.
 *
 * @package Orders\Events
 */
class OrderTransitioned extends Event
{
  /**
   * The name of the event.
   */
  public const EVENT_NAME = 'orders.order.transitioned';

  /**
   * @var int $orderId The id_ord_order that transitioned.
   */
  private int $orderId;

  /**
   * @var ?string $oldState
   */
  private ?string $oldState;

  /**
   * @var string $newState
   */
  private string $newState;

  /**
   * OrderTransitioned constructor.
   *
   * @param int    $orderId The id_ord_order that reached a terminal state.
   * @param ?string $oldState  The old state of the order.
   * @param string $newState  The new state of the order.
   */
  public function __construct(int $orderId, ?string $oldState, string $newState)
  {
    $evtName = $this->getName();
    if($this->debugMode) Helpers::Log()->common('event_dispatcher', "Event '{$evtName}' intantiated with orderId: {$orderId}, oldState: {$oldState}, newState: {$newState}");
    
    $this->orderId = $orderId;
    $this->oldState = $oldState;
    $this->newState = $newState;
  }

  /**
   * Returns a string representation of the event.
   *
   * @return string
   */
  public function __toString(): string
  {
    return 'Event: ' . self::EVENT_NAME . ' (order: ' . $this->orderId . ', old state: ' . $this->oldState . ', new state: ' . $this->newState . ')';
  }

  /**
   * Returns the transitioned order's id and the old and new states.
   *
   * @return object { id_ord_order: int, oldState: string, newState: string }
   */
  public function info(): object
  {
    return (object) [
      'id_ord_order' => $this->orderId,
      'oldState'     => $this->oldState,
      'newState'     => $this->newState,
    ];
  }
}
