<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Clientes de la API (una o varias llaves por organización).
 *
 * La llave se entrega UNA sola vez al crearla; en BD solo se guarda
 * el prefijo público (para buscarla) y el hash SHA-256 del secreto.
 */
class CreateApiClients extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'           => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'nombre'       => ['type' => 'VARCHAR', 'constraint' => 100],
            // NULL = cliente global (puede operar sobre cualquier organización).
            'organizacion' => ['type' => 'CHAR', 'constraint' => 4, 'null' => true],
            'key_prefix'   => ['type' => 'CHAR', 'constraint' => 12],
            'key_hash'     => ['type' => 'CHAR', 'constraint' => 64],
            // Lista separada por comas: tokens, notificaciones
            'scopes'       => ['type' => 'VARCHAR', 'constraint' => 255],
            // IPs o rangos CIDR permitidos, separados por comas. NULL = cualquiera.
            'allowed_ips'  => ['type' => 'VARCHAR', 'constraint' => 1000, 'null' => true],
            'rate_limit'   => ['type' => 'INT', 'unsigned' => true, 'default' => 120],
            'activo'       => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'ultimo_uso'   => ['type' => 'DATETIME', 'null' => true],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
            'updated_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('key_prefix');
        $this->forge->addKey('organizacion');
        $this->forge->createTable('api_clients');
    }

    public function down(): void
    {
        $this->forge->dropTable('api_clients', true);
    }
}
