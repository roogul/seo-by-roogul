<?php

namespace roogul\seo\controllers;

use Craft;
use craft\helpers\Cp;
use craft\models\Site;
use craft\web\Controller;
use roogul\seo\models\GlobalsModel;
use roogul\seo\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class GlobalsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Plugin::PERMISSION_EDIT_GLOBALS);

        return true;
    }

    public function actionEdit(?string $siteHandle = null, ?GlobalsModel $globals = null): Response
    {
        $sites = Craft::$app->getSites();

        if ($siteHandle !== null) {
            $site = $sites->getSiteByHandle($siteHandle);

            if (!$site) {
                throw new NotFoundHttpException('Site not found');
            }
        } else {
            $site = Cp::requestedSite() ?? $sites->getPrimarySite();
        }

        $this->requireEditableSite($site);

        $service = Plugin::getInstance()->getGlobals();
        $globals ??= $service->getForSite($site->id);
        $primary = $sites->getPrimarySite();

        return $this->renderTemplate('seo-by-roogul/_globals', [
            'globals' => $globals,
            'site' => $site,
            'editableSites' => $sites->getEditableSites(),
            'isPrimary' => $site->id === $primary->id,
            'primaryGlobals' => $site->id === $primary->id ? null : $service->resolve($primary->id),
            'defaults' => [
                'siteName' => $site->getName(),
                'titleTemplate' => GlobalsModel::DEFAULT_TITLE_TEMPLATE,
                'robotsTxt' => GlobalsModel::DEFAULT_ROBOTS,
            ],
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $siteId = (int)$this->request->getRequiredBodyParam('siteId');
        $site = Craft::$app->getSites()->getSiteById($siteId);

        if (!$site) {
            throw new NotFoundHttpException('Site not found');
        }

        $this->requireEditableSite($site);

        $service = Plugin::getInstance()->getGlobals();
        $globals = $service->getForSite($site->id);
        $globals->setFromPost((array)$this->request->getBodyParam('globals', []));

        if (!$service->save($globals)) {
            return $this->asModelFailure($globals, Craft::t('seo-by-roogul', 'Couldn’t save SEO globals.'), 'globals');
        }

        Plugin::getInstance()->getSitemap()->invalidate();

        return $this->asModelSuccess($globals, Craft::t('seo-by-roogul', 'SEO globals saved.'), 'globals');
    }

    private function requireEditableSite(Site $site): void
    {
        if (
            Craft::$app->getIsMultiSite() &&
            !Craft::$app->getUser()->checkPermission("editSite:$site->uid")
        ) {
            throw new ForbiddenHttpException('User not authorized to edit this site.');
        }
    }
}
