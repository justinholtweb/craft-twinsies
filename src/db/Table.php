<?php

namespace justinholtweb\twinsies\db;

/**
 * Twinsies' database tables.
 */
abstract class Table
{
    public const DOCUMENTS = '{{%twinsies_documents}}';
    public const CUSTOMERS = '{{%twinsies_customers}}';
    public const ARTICLES = '{{%twinsies_articles}}';
    public const AUTH = '{{%twinsies_auth}}';
    public const LOG = '{{%twinsies_log}}';
}
