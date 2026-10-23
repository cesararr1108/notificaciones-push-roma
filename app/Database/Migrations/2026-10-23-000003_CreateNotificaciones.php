<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Cada petición de envío. Sirve como cola (estado pendiente/procesando)
 * y como bitácora de auditoría (quién envió qué, a cuántos, resultado).
 */
class CreateNotificaciones extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'              => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'uuid'            => ['type' => 'CHAR', 'constraint' => 36],
            'api_client_id'   => ['type' => 'INT', 'unsigned' => true],
            'organizacion'    => ['type' => 'CHAR', 'constraint' => 4],
            'idempotency_key' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'destinatarios'   => ['type' => 'TEXT'],
            'payload'         => ['type' => 'TEXT'],
            // pendiente | procesando | completado | fallido
            'estado'          => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'pendiente'],
            'total'           => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'exitosos'        => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'fallidos'        => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'invalidos'       => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            // Último fcm_tokens.id procesado: permite reanudar si el worker se cae.
            'cursor_token_id' => ['type' => 'BIGINT', 'unsigned' => true, 'default' => 0],
            'worker'          => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'locked_at'       => ['type' => 'DATETIME', 'null' => true],
            'error'           => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'created_at'      => ['type' => 'DATETIME', 'null' => true],
            'updated_at'      => ['type' => 'DATETIME', 'null' => true],
            'completed_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('uuid');
        $this->forge->addUniqueKey(['api_client_id', 'idempotency_key']);
        $this->forge->addKey(['estado', 'id']);
        $this->forge->addKey(['organizacion', 'created_at']);
        $this->forge->createTable('notificaciones');
    }

    public function down(): void
    {
        $this->forge->dropTable('notificaciones', true);
    }
}
