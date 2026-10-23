<?php

namespace App\Commands;

use App\Models\NotificacionModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Push;

/**
 * Procesa la cola de notificaciones grandes.
 *
 *   php spark push:worker            # bucle continuo (supervisor / systemd)
 *   php spark push:worker --once     # procesa lo pendiente y termina (cron)
 *
 * Se pueden levantar varios workers en paralelo (en uno o varios servidores):
 * cada notificación es reclamada por un solo worker.
 */
class PushWorker extends BaseCommand
{
    protected $group       = 'Push';
    protected $name        = 'push:worker';
    protected $description = 'Procesa las notificaciones push encoladas.';
    protected $usage       = 'push:worker [--once] [--max-jobs N]';
    protected $options     = [
        '--once'     => 'Procesa lo pendiente y termina.',
        '--max-jobs' => 'Termina tras N notificaciones (evita fugas de memoria en procesos largos).',
    ];

    public function run(array $params)
    {
        $once    = CLI::getOption('once') !== null || array_key_exists('once', $params);
        $maxJobs = (int) (CLI::getOption('max-jobs') ?? 500);
        $worker  = gethostname() . ':' . getmypid();
        $config  = config(Push::class);
        $model   = model(NotificacionModel::class);
        $hechos  = 0;

        $parar = false;
        if (function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            $handler = static function () use (&$parar): void { $parar = true; };
            pcntl_signal(SIGTERM, $handler);
            pcntl_signal(SIGINT, $handler);
        }

        CLI::write("Worker {$worker} iniciado.", 'green');

        while (! $parar && $hechos < $maxJobs) {
            $job = $model->reclamarSiguiente($worker, $config->staleJobSeconds);

            if ($job === null) {
                if ($once) {
                    break;
                }
                sleep(2);

                continue;
            }

            CLI::write("Procesando {$job['uuid']} ({$job['total']} tokens)...");
            service('dispatcher')->procesar($job);
            $final = $model->find($job['id']);
            CLI::write("  {$final['estado']}: ok={$final['exitosos']} fallidos={$final['fallidos']} invalidos={$final['invalidos']}");
            $hechos++;
        }

        CLI::write("Worker {$worker} detenido tras {$hechos} notificaciones.", 'yellow');
    }
}
