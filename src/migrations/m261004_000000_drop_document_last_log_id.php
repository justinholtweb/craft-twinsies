<?php

namespace justinholtweb\twinsies\migrations;

use craft\db\Migration;
use justinholtweb\twinsies\db\Table;

/**
 * Drop `twinsies_documents.lastLogId`, which nothing ever wrote. A document's log entries are
 * found by `documentId` on the log row.
 */
class m261004_000000_drop_document_last_log_id extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->columnExists(Table::DOCUMENTS, 'lastLogId')) {
            $this->dropColumn(Table::DOCUMENTS, 'lastLogId');
        }

        return true;
    }

    public function safeDown(): bool
    {
        if (!$this->db->columnExists(Table::DOCUMENTS, 'lastLogId')) {
            $this->addColumn(Table::DOCUMENTS, 'lastLogId', $this->integer()->after('lastError'));
        }

        return true;
    }
}
