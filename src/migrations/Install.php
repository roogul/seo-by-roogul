<?php

namespace roogul\seo\migrations;

use craft\db\Migration;
use craft\db\Table;

class Install extends Migration
{
    public const TABLE = '{{%seobyroogul_globals}}';

    public function safeUp(): bool
    {
        if ($this->db->tableExists(self::TABLE)) {
            return true;
        }

        $this->createTable(self::TABLE, [
            'id' => $this->primaryKey(),
            'siteId' => $this->integer()->notNull(),
            'siteName' => $this->string()->notNull()->defaultValue(''),
            'titleTemplate' => $this->string()->notNull()->defaultValue(''),
            'description' => $this->text(),
            'imageId' => $this->integer(),
            'twitterHandle' => $this->string(15)->notNull()->defaultValue(''),
            'entityType' => $this->string(20)->notNull()->defaultValue('organization'),
            'entityName' => $this->string()->notNull()->defaultValue(''),
            'entityLogoId' => $this->integer(),
            'sameAs' => $this->text(),
            'robotsTxt' => $this->text(),
            'noindex' => $this->boolean()->notNull()->defaultValue(false),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, self::TABLE, ['siteId'], true);
        $this->addForeignKey(null, self::TABLE, ['siteId'], Table::SITES, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, self::TABLE, ['imageId'], Table::ELEMENTS, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, self::TABLE, ['entityLogoId'], Table::ELEMENTS, ['id'], 'SET NULL', null);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(self::TABLE);
        return true;
    }
}
