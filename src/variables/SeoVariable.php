<?php

namespace roogul\seo\variables;

use Craft;
use craft\helpers\Template;
use craft\helpers\UrlHelper;
use roogul\seo\models\GlobalsModel;
use roogul\seo\Plugin;
use Twig\Markup;

/**
 * craft.seoByRoogul
 */
class SeoVariable
{
    /**
     * Override values for this page:
     * {% do craft.seoByRoogul.set({ title: 'Search', noindex: true }) %}
     *
     * Keys: element, title, rawTitle, description, image (Asset or URL),
     * canonical, noindex, nofollow, type.
     */
    public function set(array $values): void
    {
        Plugin::getInstance()->getMeta()->set($values);
    }

    /**
     * Add a JSON-LD node to the page's @graph:
     * {% do craft.seoByRoogul.schema({ '@type': 'Product', name: product.title }) %}
     */
    public function schema(array $node): void
    {
        Plugin::getInstance()->getMeta()->addSchema($node);
    }

    /**
     * All tags as HTML, for when auto-inject is turned off.
     */
    public function render(): Markup
    {
        return Template::raw(Plugin::getInstance()->getMeta()->render());
    }

    /**
     * The resolved values (title, description, image, canonical, robots…).
     */
    public function meta(): array
    {
        return Plugin::getInstance()->getMeta()->resolve();
    }

    /**
     * The effective globals for a site (defaults to the current site).
     */
    public function globals(?int $siteId = null): GlobalsModel
    {
        $siteId ??= Craft::$app->getSites()->getCurrentSite()->id;

        return Plugin::getInstance()->getGlobals()->resolve($siteId);
    }

    public function sitemapUrl(): string
    {
        return Plugin::getInstance()->getMeta()->absoluteUrl(UrlHelper::siteUrl('sitemap.xml'));
    }
}
