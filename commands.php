<?php

namespace Orders\Commands;

use SplitPHP\Cli;
use SplitPHP\Utils;
use SplitPHP\Database\Dao;

class Commands extends Cli
{
  public function init(): void
  {
    // Avança uma execução de workflow (pedido ou item) para a próxima etapa pela tag da transição.
    // Uso operacional/suporte: empurrar pelo console um pedido travado. Roda por-tenant (loop do
    // command.before da multitenancy) — mire um tenant com --tenant-key=<slug>. No-op onde a execução
    // não existe.
    //
    //   php console orders:advance --exec=<executionKey> --transition=<tag> --tenant-key=<slug>
    $this->addCommand('advance', function (array $args) {
      $execKey = $args['--exec'] ?? null;
      $transitionTag = $args['--transition'] ?? null;

      if (empty($execKey) || empty($transitionTag)) {
        Utils::printLn('  >> Uso: orders:advance --exec=<executionKey> --transition=<tag>');
        return;
      }

      $exec = $this->getDao('BPM_EXECUTION')
        ->filter('ds_key')->equalsTo($execKey)
        ->first();

      // Execução inexistente neste tenant: no-op silencioso (o loop por-tenant chama em todos).
      if (empty($exec)) return;

      $this->getService('bpm/wizard')->transitionByTag($execKey, $transitionTag);
      Dao::flush();

      Utils::printLn("  >> Execução '{$execKey}' avançada por '{$transitionTag}'.");
    });
  }
}
