<?php

namespace Orders\Events;

use SplitPHP\Event;
use SplitPHP\Helpers;

/**
 * Class ItemTransitioned
 *
 * Triggered when an item transitions to another step,
 * i.e. the item is "Preparing". Carries the item id, the old and the new steps so
 * listeners in other bounded contexts can react.
 *
 * @package Orders\Events
 */
class ItemTransitioned extends Event
{
  /**
   * The name of the event.
   */
  public const EVENT_NAME = 'orders.item.transitioned';

  /**
   * @var int $itemId The id_ord_order_item that transitioned.
   */
  private int $itemId;

  /**
   * @var ?string $oldState
   */
  private ?string $oldState;

  /**
   * @var string $newState
   */
  private string $newState;

  /**
   * ItemTransitioned constructor.
   *
   * @param int    $itemId The id_ord_order_item that transitioned.
   * @param ?string $oldState  The old state of the item.
   * @param string $newState  The new state of the item.
   */
  public function __construct(int $itemId, ?string $oldState, string $newState)
  {
    $evtName = self::EVENT_NAME;
    if($this->debugMode) Helpers::Log()->common('event_dispatcher', "Event '{$evtName}' intantiated with itemId: {$itemId}, oldState: {$oldState}, newState: {$newState}");

    $this->itemId = $itemId;
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
    return 'Event: ' . self::EVENT_NAME . ' (item: ' . $this->itemId . ', old state: ' . $this->oldState . ', new state: ' . $this->newState . ')';
  }

  /**
   * Returns the transitioned item's id and the old and new states.
   *
   * @return object { id_ord_order_item: int, oldState: string, newState: string }
   */
  public function info(): object
  {
    return (object) [
      'id_ord_order_item' => $this->itemId,
      'oldState'          => $this->oldState,
      'newState'          => $this->newState,
    ];
  }
}
