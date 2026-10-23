<?php

namespace App\Commands;

use App\Models\ApiClientModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 *   php spark apikey:create "Sistema ROMA" --org ROMA
 *   php spark apikey:create "Front web" --org ROMA --scopes tokens --ips 10.0.0.0/8
 *   php spark apikey:create "Backoffice global" --global
 */
class ApiKeyCreate extends BaseCommand
{
    protected $group       = 'Push';
    protected $name        = 'apikey:create';
    protected $description = 'Crea una API key para una organización (se muestra una sola vez).';
    protected $usage       = 'apikey:create <nombre> (--org XXXX | --global) [--scopes a,b] [--ips ip,cidr] [--rate N]';
    protected $arguments   = ['nombre' => 'Nombre descriptivo del cliente.'];
    protected $options     = [
        '--org'    => 'Organización (4 caracteres) a la que queda atada la llave.',
        '--global' => 'Llave sin organización fija (debe enviar "organizacion" en cada petición).',
        '--scopes' => 'tokens,notificaciones (por defecto ambos).',
        '--ips'    => 'IPs o rangos CIDR permitidos, separados por coma.',
        '--rate'   => 'Peticiones por minuto (por defecto 120).',
    ];

    public function run(array $params)
    {
        $nombre = trim($params[0] ?? '');
        $org    = CLI::getOption('org');
        $global = CLI::getOption('global') !== null;

        if ($nombre === '') {
            CLI::error('Indique el nombre del cliente.');

            return EXIT_USER_INPUT;
        }
        if ($global === (is_string($org) && $org !== '')) {
            CLI::error('Use exactamente una de: --org XXXX o --global.');

            return EXIT_USER_INPUT;
        }

        $org = $global ? null : strtoupper((string) $org);
        if ($org !== null && ! preg_match('/^[A-Z0-9]{4}$/', $org)) {
            CLI::error('La organización debe tener 4 caracteres alfanuméricos.');

            return EXIT_USER_INPUT;
        }

        $validos = [ApiClientModel::SCOPE_TOKENS, ApiClientModel::SCOPE_SEND];
        $scopes  = array_filter(array_map('trim', explode(',', (string) (CLI::getOption('scopes') ?? implode(',', $validos)))));
        if (array_diff($scopes, $validos) !== []) {
            CLI::error('Scopes válidos: ' . implode(', ', $validos));

            return EXIT_USER_INPUT;
        }

        $ips = CLI::getOption('ips');

        [$row, $key] = model(ApiClientModel::class)->createClient(
            $nombre,
            $org,
            array_values($scopes),
            is_string($ips) ? $ips : null,
            (int) (CLI::getOption('rate') ?? 120),
        );

        CLI::write('Cliente creado (id ' . $row['id'] . ', organización ' . ($org ?? 'GLOBAL') . ').', 'green');
        CLI::write('API key (guárdela ahora, no se volverá a mostrar):', 'yellow');
        CLI::write($key);

        return EXIT_SUCCESS;
    }
}
