<?php

namespace roogul\seo\services;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use roogul\seo\migrations\Install;
use roogul\seo\models\GlobalsModel;
use yii\base\Component;

class Globals extends Component
{
    /** @var array<int, GlobalsModel> */
    private array $_stored = [];

    /** @var array<int, GlobalsModel> */
    private array $_resolved = [];

    /**
     * The values saved for a site, without any fallbacks. Used by the CP form.
     */
    public function getForSite(int $siteId): GlobalsModel
    {
        if (isset($this->_stored[$siteId])) {
            return clone $this->_stored[$siteId];
        }

        $row = (new Query())
            ->select(array_merge(['id', 'siteId'], GlobalsModel::ATTRIBUTES))
            ->from(Install::TABLE)
            ->where(['siteId' => $siteId])
            ->one();

        $model = new GlobalsModel(['siteId' => $siteId]);

        if ($row) {
            $model->id = (int)$row['id'];

            foreach (GlobalsModel::ATTRIBUTES as $attr) {
                $value = $row[$attr];
                $model->$attr = match ($attr) {
                    'imageId', 'entityLogoId' => $value ? (int)$value : null,
                    'noindex' => (bool)$value,
                    default => (string)($value ?? ''),
                };
            }
        }

        $this->_stored[$siteId] = $model;

        return clone $model;
    }

    /**
     * The effective values for a site: blank fields fall back to the primary
     * site, then to built-in defaults. noindex is never inherited.
     */
    public function resolve(int $siteId): GlobalsModel
    {
        if (isset($this->_resolved[$siteId])) {
            return $this->_resolved[$siteId];
        }

        $sites = Craft::$app->getSites();
        $site = $sites->getSiteById($siteId, true);
        $model = $this->getForSite($siteId);
        $primaryId = $sites->getPrimarySite()->id;

        if ($primaryId !== $siteId) {
            $primary = $this->getForSite($primaryId);

            foreach (GlobalsModel::ATTRIBUTES as $attr) {
                if ($attr === 'noindex') {
                    continue;
                }

                if ($model->$attr === '' || $model->$attr === null) {
                    $model->$attr = $primary->$attr;
                }
            }
        }

        if ($model->siteName === '') {
            $model->siteName = $site?->getName() ?? Craft::$app->getSystemName();
        }

        if ($model->titleTemplate === '') {
            $model->titleTemplate = GlobalsModel::DEFAULT_TITLE_TEMPLATE;
        }

        if ($model->entityName === '') {
            $model->entityName = $model->siteName;
        }

        if ($model->robotsTxt === '') {
            $model->robotsTxt = GlobalsModel::DEFAULT_ROBOTS;
        }

        return $this->_resolved[$siteId] = $model;
    }

    public function save(GlobalsModel $model, bool $runValidation = true): bool
    {
        if ($runValidation && !$model->validate()) {
            return false;
        }

        $row = $model->toRow();
        $exists = (new Query())
            ->from(Install::TABLE)
            ->where(['siteId' => $model->siteId])
            ->exists();

        if ($exists) {
            Db::update(Install::TABLE, $row, ['siteId' => $model->siteId]);
        } else {
            Db::insert(Install::TABLE, $row + ['siteId' => $model->siteId]);
        }

        $this->_stored = [];
        $this->_resolved = [];

        return true;
    }
}
