<?php

namespace roogul\seo\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\helpers\Json;
use craft\web\View;
use roogul\seo\models\SeoData;
use roogul\seo\Plugin;
use roogul\seo\web\assets\field\FieldAsset;
use yii\db\Schema as YiiSchema;

/**
 * Per-element SEO overrides. Anything left blank falls back to the fields
 * listed in this field's settings, then to the globals.
 */
class SeoField extends Field
{
    /** Comma-separated handles, first non-empty wins. "title" = element title. */
    public string $titleSources = 'title';
    public string $descriptionSources = '';
    public string $imageSources = '';

    public static function displayName(): string
    {
        return Craft::t('seo-by-roogul', 'SEO');
    }

    public static function icon(): string
    {
        return 'magnifying-glass';
    }

    public static function phpType(): string
    {
        return SeoData::class;
    }

    public static function dbType(): string
    {
        return YiiSchema::TYPE_JSON;
    }

    /**
     * @return string[]
     */
    public function getSourceHandles(string $kind): array
    {
        $value = match ($kind) {
            'title' => $this->titleSources,
            'description' => $this->descriptionSources,
            'image' => $this->imageSources,
            default => '',
        };

        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    public function normalizeValue(mixed $value, ?ElementInterface $element): mixed
    {
        return SeoData::fromValue($value);
    }

    public function serializeValue(mixed $value, ?ElementInterface $element): mixed
    {
        $data = SeoData::fromValue($value);

        return $data->isEmpty() ? null : $data->toArray();
    }

    public function isValueEmpty(mixed $value, ElementInterface $element): bool
    {
        return SeoData::fromValue($value)->isEmpty();
    }

    protected function searchKeywords(mixed $value, ElementInterface $element): string
    {
        $data = SeoData::fromValue($value);

        return trim($data->title . ' ' . $data->description);
    }

    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('seo-by-roogul/_field/settings', [
            'field' => $this,
        ], View::TEMPLATE_MODE_CP);
    }

    public function useFieldset(): bool
    {
        return true;
    }

    protected function inputHtml(mixed $value, ?ElementInterface $element, bool $inline): string
    {
        $data = SeoData::fromValue($value);
        $view = Craft::$app->getView();
        $view->registerAssetBundle(FieldAsset::class);

        $fallbacks = $element
            ? Plugin::getInstance()->getMeta()->fallbacksFor($element, $this)
            : null;

        $id = $view->namespaceInputId('seo-' . $this->handle);

        $config = [
            'siteName' => $fallbacks['siteName'] ?? '',
            'titleTemplate' => $fallbacks['titleTemplate'] ?? '{title}',
            'fallbackTitle' => $fallbacks['title'] ?? '',
            'fallbackDescription' => $fallbacks['description'] ?? '',
            'url' => $fallbacks['url'] ?? '',
            'isHomepage' => $fallbacks['isHomepage'] ?? false,
        ];

        $view->registerJs('SeoByRoogulField.init(' . Json::encode($id) . ');');

        return $view->renderTemplate('seo-by-roogul/_field/input', [
            'id' => 'seo-' . $this->handle,
            'field' => $this,
            'data' => $data,
            'fallbacks' => $fallbacks,
            'configJson' => Json::encode($config),
        ], View::TEMPLATE_MODE_CP);
    }
}
