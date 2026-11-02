<?php

namespace roogul\seo\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use roogul\seo\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class RobotsController extends Controller
{
    protected array|int|bool $allowAnonymous = true;

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        if (!$settings->robotsEnabled) {
            throw new NotFoundHttpException();
        }

        if (!$settings->isProduction()) {
            $body = "User-agent: *\nDisallow: /\n";
        } else {
            $site = Craft::$app->getSites()->getCurrentSite();
            $body = $plugin->getGlobals()->resolve($site->id)->robotsTxt;

            if ($settings->sitemapEnabled) {
                $sitemapUrl = $plugin->getMeta()->absoluteUrl(UrlHelper::siteUrl('sitemap.xml'));
                $body = str_replace('{sitemapUrl}', $sitemapUrl, $body);
            } else {
                // Drop any line that references the sitemap
                $body = preg_replace('/^.*\{sitemapUrl\}.*\R?/m', '', $body);
            }

            $body = rtrim($body) . "\n";
        }

        $response = $this->response;
        $response->format = Response::FORMAT_RAW;
        $response->getHeaders()->set('Content-Type', 'text/plain; charset=utf-8');
        $response->data = $body;

        return $response;
    }
}
