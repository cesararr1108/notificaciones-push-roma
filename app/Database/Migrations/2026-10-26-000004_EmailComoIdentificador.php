<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * El usuario se identifica por email: "usuario" pasa a ser opcional y se
 * indexa el email para enviar por correo.
 */
class EmailComoIdentificador extends Migration
{
    public function up(): void
    {
        $this->forge->modifyColumn('fcm_tokens', [
            'usuario' => ['name' => 'usuario', 'type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
        ]);

        $this->forge->addKey(['organizacion', 'email', 'activo'], false, false, 'fcm_tokens_org_email');
        $this->forge->processIndexes('fcm_tokens');
    }

    public function down(): void
    {
        // "usuario" se deja como opcional: volverlo obligatorio fallaría (o perdería
        // datos) si ya hay tokens registrados solo con email.
        $this->forge->dropKey('fcm_tokens', 'fcm_tokens_org_email', false);
    }
}
