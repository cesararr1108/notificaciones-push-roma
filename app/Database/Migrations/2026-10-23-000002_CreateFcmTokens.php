<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Registro de tokens FCM por usuario/dispositivo.
 */
class CreateFcmTokens extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                  => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'organizacion'        => ['type' => 'CHAR', 'constraint' => 4],
            'oficina'             => ['type' => 'CHAR', 'constraint' => 4],
            'usuario'             => ['type' => 'VARCHAR', 'constraint' => 64],
            'nombre_usuario'      => ['type' => 'VARCHAR', 'constraint' => 150],
            'email'               => ['type' => 'VARCHAR', 'constraint' => 190],
            'rol'                 => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'token'               => ['type' => 'VARCHAR', 'constraint' => 4096],
            // SHA-256 del token: índice único compacto (el token es demasiado largo para indexarlo).
            'token_hash'          => ['type' => 'CHAR', 'constraint' => 64],
            'navegador'           => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'dispositivo'         => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'activo'              => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'motivo_baja'         => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
            'fecha_registro'      => ['type' => 'DATETIME'],
            'fecha_actualizacion' => ['type' => 'DATETIME', 'null' => true],
            'ultimo_envio'        => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('token_hash');
        // Índices pensados para las consultas de envío (siempre filtran por organización + activo).
        $this->forge->addKey(['organizacion', 'usuario', 'activo']);
        $this->forge->addKey(['organizacion', 'oficina', 'activo']);
        $this->forge->addKey(['organizacion', 'rol', 'activo']);
        $this->forge->addKey('fecha_actualizacion');
        $this->forge->createTable('fcm_tokens');
    }

    public function down(): void
    {
        $this->forge->dropTable('fcm_tokens', true);
    }
}
