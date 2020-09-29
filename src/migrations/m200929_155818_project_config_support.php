<?php

namespace lhs\elasticsearch\migrations;

use Craft;
use craft\db\Migration;
use craft\helpers\ArrayHelper;
use craft\models\EntryType;

/**
 * m200929_155818_project_config_support migration.
 */
class m200929_155818_project_config_support extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp()
    {
        $projectConfig = Craft::$app->getProjectConfig();
        $ids = $projectConfig->get('plugins.elasticsearch.settings.blacklistedEntryTypes');

        $IdToHandleMapping = ArrayHelper::map(
            Craft::$app->sections->getAllEntryTypes(),
            static function (EntryType $entryType) { return $entryType->id; },
            static function (EntryType $entryType) { return $entryType->handle; }
        );

        $handles = array_map(static function ($id) use ($IdToHandleMapping) {
            return $IdToHandleMapping[$id];
        }, $ids);

        $projectConfig->set(
            'plugins.elasticsearch.settings.blacklistedEntryTypes',
            $handles,
            'Elasticsearch plugin: use handles instead of IDs to identify blacklisted sections.'
        );

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown()
    {
        echo "m200929_155818_project_config_support cannot be reverted.\n";
        return false;
    }
}
