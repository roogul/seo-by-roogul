<?php

namespace roogul\seo\controllers;

use Craft;
use craft\web\Controller;
use roogul\seo\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class SitemapController extends Controller
{
    protected array|int|bool $allowAnonymous = true;

    public function actionIndex(): Response
    {
        $this->requireSitemap();
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;

        return $this->xml(Plugin::getInstance()->getSitemap()->getIndexXml($siteId));
    }

    public function actionPage(string $type, string $handle, int $page): Response
    {
        $this->requireSitemap();
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $xml = Plugin::getInstance()->getSitemap()->getPageXml($siteId, $type, $handle, $page);

        if ($xml === null) {
            throw new NotFoundHttpException();
        }

        return $this->xml($xml);
    }

    private function requireSitemap(): void
    {
        if (!Plugin::getInstance()->getSettings()->sitemapEnabled) {
            throw new NotFoundHttpException();
        }
    }

    private function xml(string $xml): Response
    {
        $response = $this->response;
        $response->format = Response::FORMAT_RAW;
        $response->getHeaders()->set('Content-Type', 'application/xml; charset=utf-8');
        $response->getHeaders()->set('X-Robots-Tag', 'noindex');
        $response->data = $xml;

        return $response;
    }
}
