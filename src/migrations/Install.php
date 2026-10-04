<?php

namespace justinholtweb\twinsies\migrations;

use craft\db\Migration;
use craft\db\Table as CraftTable;
use justinholtweb\twinsies\db\Table;

/**
 * Twinsies install migration.
 */
class Install extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $this->createTables();
        $this->createIndexes();
        $this->addForeignKeys();

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::LOG);
        $this->dropTableIfExists(Table::AUTH);
        $this->dropTableIfExists(Table::ARTICLES);
        $this->dropTableIfExists(Table::CUSTOMERS);
        $this->dropTableIfExists(Table::DOCUMENTS);

        return true;
    }

    private function createTables(): void
    {
        $this->createTable(Table::DOCUMENTS, [
            'id' => $this->primaryKey(),
            'orderId' => $this->integer()->notNull(),
            'storeId' => $this->integer(),
            // 'invoice' or 'creditnote'.
            'kind' => $this->string(16)->notNull()->defaultValue('invoice'),
            // What in Craft caused this document. An order has exactly one 'invoice', and one
            // credit note per refund transaction: 'refund:<commerce transaction hash>'. The
            // unique index on (orderId, sourceKey) is what stops an order posting twice.
            'sourceKey' => $this->string(64)->notNull(),
            // 'salesinvoice' or 'transaction' — the mode in force when this document was built.
            'mode' => $this->string(16)->notNull(),
            'status' => $this->string(16)->notNull()->defaultValue('pending'),
            'office' => $this->string(16),
            // Sales invoice type (FACTUUR) in salesinvoice mode, daybook (VRK) in transaction mode.
            'bookCode' => $this->string(16),
            'invoiceNumber' => $this->string(32),
            // Twinfield's resulting financial transaction, read out of the response. This is the
            // handle reconciliation uses, and salesinvoice mode only returns it once final.
            'transactionCode' => $this->string(16),
            'transactionNumber' => $this->string(32),
            'customerCode' => $this->string(32),
            'currency' => $this->string(8),
            'valueTotal' => $this->decimal(14, 4),
            'openValue' => $this->decimal(14, 4),
            'matchStatus' => $this->string(24),
            // Hash of the XML that was actually sent, so the CP can say "this order changed after
            // it was posted" instead of quietly rebuilding a document that no longer matches.
            'payloadHash' => $this->string(40),
            'datePosted' => $this->dateTime(),
            'datePaid' => $this->dateTime(),
            'dateReconciled' => $this->dateTime(),
            'attempts' => $this->integer()->notNull()->defaultValue(0),
            'lastError' => $this->text(),
            'lastLogId' => $this->integer(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::CUSTOMERS, [
            'id' => $this->primaryKey(),
            'office' => $this->string(16)->notNull(),
            // 'user', 'email' or 'guest' — which of Craft's identities this dimension was keyed on.
            'sourceType' => $this->string(16)->notNull(),
            'sourceKey' => $this->string(255)->notNull(),
            'code' => $this->string(32)->notNull(),
            'name' => $this->string(255),
            'dateSynced' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::ARTICLES, [
            'id' => $this->primaryKey(),
            'office' => $this->string(16)->notNull(),
            // 'purchasable:<id>', 'producttype:<handle>', 'shipping', 'discount' or 'default'.
            // A single string key instead of nullable columns, because MySQL has no partial
            // unique index and NULLs would let duplicates through.
            'mapKey' => $this->string(128)->notNull(),
            'article' => $this->string(32),
            'subarticle' => $this->string(32),
            // Twinfield dim1: the revenue ledger account this line books to.
            'revenueGl' => $this->string(32),
            'vatCode' => $this->string(16),
            'label' => $this->string(255),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // One row, ever. The OAuth grant is obtained at runtime and rotates, so it does not
        // belong in plugin settings: those are project config, and project config is committed.
        $this->createTable(Table::AUTH, [
            'id' => $this->primaryKey(),
            // Hash of the client ID the grant was issued to. Changing client credentials has to
            // invalidate the stored grant rather than silently reuse it against a new app.
            'clientIdHash' => $this->string(64),
            'accessToken' => $this->text(),
            'refreshToken' => $this->text(),
            'expiresAt' => $this->dateTime(),
            'clusterUrl' => $this->string(255),
            'scope' => $this->string(255),
            'twinfieldUser' => $this->string(255),
            'connectedByUserId' => $this->integer(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::LOG, [
            'id' => $this->primaryKey(),
            'action' => $this->string(48)->notNull(),
            'level' => $this->string(16)->notNull()->defaultValue('info'),
            'statusCode' => $this->integer(),
            'durationMs' => $this->integer(),
            'documentId' => $this->integer(),
            'orderId' => $this->integer(),
            'summary' => $this->string(255),
            'message' => $this->text(),
            'request' => $this->mediumText(),
            'response' => $this->mediumText(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
    }

    private function createIndexes(): void
    {
        // The idempotency guarantee: a retried push cannot create a second document.
        $this->createIndex(null, Table::DOCUMENTS, ['orderId', 'sourceKey'], true);
        $this->createIndex(null, Table::DOCUMENTS, ['status'], false);
        $this->createIndex(null, Table::DOCUMENTS, ['office', 'transactionCode', 'transactionNumber'], false);
        $this->createIndex(null, Table::DOCUMENTS, ['dateCreated'], false);

        $this->createIndex(null, Table::CUSTOMERS, ['office', 'sourceType', 'sourceKey'], true);
        $this->createIndex(null, Table::CUSTOMERS, ['office', 'code'], false);

        $this->createIndex(null, Table::ARTICLES, ['office', 'mapKey'], true);

        $this->createIndex(null, Table::LOG, ['action'], false);
        $this->createIndex(null, Table::LOG, ['level'], false);
        $this->createIndex(null, Table::LOG, ['dateCreated'], false);
        $this->createIndex(null, Table::LOG, ['documentId'], false);
        $this->createIndex(null, Table::LOG, ['orderId'], false);
    }

    private function addForeignKeys(): void
    {
        $this->addForeignKey(null, Table::DOCUMENTS, ['orderId'], CraftTable::ELEMENTS, ['id'], 'CASCADE', null);
    }
}
