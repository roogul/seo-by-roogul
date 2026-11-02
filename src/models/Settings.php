<?php

namespace roogul\seo\models;

use Craft;
use craft\base\Model;

/**
 * Plugin settings (project config). Overridable per environment in
 * config/seo-by-roogul.php.
 */
class Settings extends Model
{
    /** Add the tags to {{ head() }} automatically. */
    public bool $autoInject = true;

    /**
     * Comma-separated CRAFT_ENVIRONMENT values that may be indexed. Every
     * other environment gets noindex and a disallow-all robots.txt.
     * If CRAFT_ENVIRONMENT isn't set, indexing is allowed.
     */
    public string $productionEnvironments = 'production';

    public bool $sitemapEnabled = true;
    public bool $robotsEnabled = true;

    /** Sitemap source key => included. Sources not listed are included. */
    public array $sitemapSources = [];

    public int $imageWidth = 1200;
    public int $imageHeight = 630;

    public function init(): void
    {
        parent::init();
        $this->normalize();
    }

    public function beforeValidate(): bool
    {
        $this->normalize();
        return parent::beforeValidate();
    }

    protected function defineRules(): array
    {
        return [
            [['imageWidth', 'imageHeight'], 'integer', 'min' => 200, 'max' => 4000],
        ];
    }

    public function isProduction(): bool
    {
        $env = Craft::$app->env;

        if ($env === null || $env === '') {
            return true;
        }

        $allowed = array_filter(array_map('trim', explode(',', strtolower($this->productionEnvironments))));

        return in_array(strtolower($env), $allowed, true);
    }

    public function isSourceIncluded(string $key): bool
    {
        return $this->sitemapSources[$key] ?? true;
    }

    private function normalize(): void
    {
        $this->sitemapSources = array_map(static fn($v) => (bool)$v, $this->sitemapSources);
    }
}
