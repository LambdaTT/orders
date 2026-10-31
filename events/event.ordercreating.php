<?php

namespace Orders\Events;

use SplitPHP\Event;

/**
 * Class OrderCreating
 *
 * Triggered at the very START of Orders\Services\Order::create(), BEFORE any side effect
 * (insert, BPM workflow start, manager notification). Carries the raw order payload and a
 * mutable "gate" object so listeners in other bounded contexts can VETO the creation.
 *
 * Contrato do veto (o módulo fica agnóstico à regra): a persistência é a função despachadora
 * deste evento. Um listener que quer barrar a criação chama `$event->stopPropagation()` (a
 * persistência não roda) e seta `info()->gate->blocked = true` com um `reason`. De volta no
 * `create()`, o domínio lê o gate e lança a exceção — o throw sobe ao kernel (não é capturado),
 * que faz rollback antes do flush e emite UMA resposta. Direção de dependência: módulo emite,
 * aplicação escuta e decide.
 *
 * @package Orders\Events
 */
class OrderCreating extends Event
{
  /**
   * The name of the event.
   */
  public const EVENT_NAME = 'orders.order.creating';

  /**
   * @var array $data The order payload passed to create().
   */
  private array $data;

  /**
   * @var object $gate Objeto mutável de veto: { blocked: bool, reason: ?string }.
   */
  private object $gate;

  /**
   * OrderCreating constructor.
   *
   * @param array  $data The order payload.
   * @param object $gate Shared veto gate the dispatcher reads after the event.
   */
  public function __construct(array $data, object $gate)
  {
    $this->data = $data;
    $this->gate = $gate;
  }

  /**
   * Returns a string representation of the event.
   *
   * @return string
   */
  public function __toString(): string
  {
    return 'Event: ' . self::EVENT_NAME
      . ' (fulfillment: ' . ($this->data['ds_fulfillment_type'] ?? '?') . ')';
  }

  /**
   * Returns the order payload and the mutable veto gate.
   *
   * @return object { data: array, gate: object }
   */
  public function info(): object
  {
    return (object) [
      'data' => $this->data,
      'gate' => $this->gate,
    ];
  }
}
