<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/** Retire the old activity log and views after saving their exact definitions/data. */
class RetireLegacyDatabaseObjects extends Migration
{
    public function up()
    {
        $objects = $this->db->query(
            "SELECT TABLE_NAME AS name, TABLE_TYPE AS type FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('activity', 'recent_files_view', 'view_duplicates')"
        )->getResultArray();
        if ($objects === []) {
            return;
        }

        $sql = "-- Legacy object recovery backup; import into the original database only.\nSET NAMES utf8mb4;\n";
        foreach ($objects as $object) {
            $name = $object['name'];
            $isView = $object['type'] === 'VIEW';
            $definition = $this->db->query('SHOW CREATE ' . ($isView ? 'VIEW' : 'TABLE') . ' `' . $name . '`')->getRowArray();
            $sql .= $definition[$isView ? 'Create View' : 'Create Table'] . ";\n";
            if (! $isView) {
                foreach ($this->db->table($name)->get()->getResultArray() as $row) {
                    $columns = implode(', ', array_map(static fn (string $column): string => '`' . $column . '`', array_keys($row)));
                    $values = implode(', ', array_map(static fn ($value): string => $value === null ? 'NULL' : "X'" . bin2hex((string) $value) . "'", array_values($row)));
                    $sql .= 'INSERT INTO `' . $name . '` (' . $columns . ') VALUES (' . $values . ");\n";
                }
            }
        }

        $directory = WRITEPATH . 'backups';
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Cannot create the legacy database backup directory.');
        }
        $deny = "<IfModule authz_core_module>\nRequire all denied\n</IfModule>\n<IfModule !authz_core_module>\nDeny from all\n</IfModule>\n";
        if (file_put_contents($directory . '/.htaccess', $deny) !== strlen($deny)) {
            throw new RuntimeException('Cannot protect the legacy backup directory.');
        }
        $database = preg_replace('/[^a-zA-Z0-9_-]/', '_', $this->db->getDatabase());
        $backup = $directory . '/legacy-objects-' . $database . '-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.sql';
        $previousMask = umask(0077);
        try {
            $written = file_put_contents($backup, $sql, LOCK_EX);
        } finally {
            umask($previousMask);
        }
        if ($written !== strlen($sql) || hash_file('sha256', $backup) !== hash('sha256', $sql)) {
            throw new RuntimeException('Legacy database backup verification failed; no objects were removed.');
        }

        foreach (['recent_files_view', 'view_duplicates'] as $view) {
            $this->db->query('DROP VIEW IF EXISTS `' . $view . '`');
        }
        $this->forge->dropTable('activity', true);
        $this->db->resetDataCache();
        log_message('notice', 'Legacy database objects retired. Recovery backup: {backup}', ['backup' => $backup]);
    }

    public function down()
    {
        throw new RuntimeException('Restore the retired objects from the verified legacy-objects SQL backup in writable/backups before changing migration history.');
    }
}
