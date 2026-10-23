<?php

namespace App\Commands;

use App\Models\FcmTokenModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Push;

/**
 * Desactiva tokens que no se han actualizado en N días (por defecto 270,
 * el plazo tras el cual FCM los considera caducados). Programar a diario:
 *   0 3 * * * php /ruta/spark push:purge
 */
class PurgeTokens extends BaseCommand
{
    protected $group       = 'Push';
    protected $name        = 'push:purge';
    protected $description = 'Desactiva tokens FCM caducados.';
    protected $usage       = 'push:purge [--days N]';
    protected $options     = ['--days' => 'Días sin actualización (por defecto push.staleTokenDays).'];

    public function run(array $params)
    {
        $dias = (int) (CLI::getOption('days') ?? config(Push::class)->staleTokenDays);
        $n    = model(FcmTokenModel::class)->purgarCaducados($dias);

        CLI::write("Tokens desactivados: {$n}", 'green');
    }
}
