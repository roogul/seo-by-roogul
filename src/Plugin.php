<?php

namespace roogul\seo;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\elements\Category;
use craft\elements\Entry;
use craft\events\PluginEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\ElementHelper;
use craft\services\Elements;
use craft\services\Fields;
use craft\services\Plugins;
use craft\services\ProjectConfig;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use roogul\seo\fields\SeoField;
use roogul\seo\models\Settings;
use roogul\seo\services\Globals;
use roogul\seo\services\Meta;
use roogul\seo\services\Sitemap;
use roogul\seo\variables\SeoVariable;
use yii\base\Event;

/**
 * SEO by ROOGUL
 *
 * @property-read Globals $globals
 * @property-read Meta $meta
 * @property-read Sitemap $sitemap
 * @method static Plugin getInstance()
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const PERMISSION_EDIT_GLOBALS = 'seoByRoogul-editGlobals';

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    public static function config(): array
    {
        return [
            'components' => [
                'globals' => Globals::class,
                'meta' => Meta::class,
                'sitemap' => Sitemap::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerFieldType();
        $this->registerVariable();
        $this->registerPermissions();
        $this->registerUrlRules();
        $this->registerCacheInvalidation();

        Craft::$app->onInit(function() {
            $request = Craft::$app->getRequest();

            if (!$request->getIsConsoleRequest() && $request->getIsSiteRequest()) {
                $this->registerHeadOutput();
            }
        });
    }

    public function getGlobals(): Globals
    {
        return $this->get('globals');
    }

    public function getMeta(): Meta
    {
        return $this->get('meta');
    }

    public function getSitemap(): Sitemap
    {
        return $this->get('sitemap');
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = 'SEO';
        $item['subnav'] = [];

        $user = Craft::$app->getUser();

        if ($user->checkPermission(self::PERMISSION_EDIT_GLOBALS)) {
            $item['subnav']['globals'] = [
                'label' => Craft::t('seo-by-roogul', 'Globals'),
                'url' => 'seo-by-roogul/globals',
            ];
        }

        if ($user->getIsAdmin() && Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            $item['subnav']['settings'] = [
                'label' => Craft::t('seo-by-roogul', 'Settings'),
                'url' => 'settings/plugins/' . $this->handle,
            ];
        }

        return $item;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('seo-by-roogul/_settings', [
            'settings' => $this->getSettings(),
            'sources' => $this->getSitemap()->getAllSources(),
        ]);
    }

    // Registration
    // -------------------------------------------------------------------------

    private function registerFieldType(): void
    {
        Event::on(Fields::class, Fields::EVENT_REGISTER_FIELD_TYPES, static function(RegisterComponentTypesEvent $event) {
            $event->types[] = SeoField::class;
        });
    }

    private function registerVariable(): void
    {
        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, static function(Event $event) {
            /** @var CraftVariable $variable */
            $variable = $event->sender;
            $variable->set('seoByRoogul', SeoVariable::class);
        });
    }

    private function registerPermissions(): void
    {
        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, static function(RegisterUserPermissionsEvent $event) {
            $event->permissions[] = [
                'heading' => 'SEO by ROOGUL',
                'permissions' => [
                    self::PERMISSION_EDIT_GLOBALS => [
                        'label' => Craft::t('seo-by-roogul', 'Edit global SEO settings'),
                    ],
                ],
            ];
        });
    }

    private function registerUrlRules(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, static function(RegisterUrlRulesEvent $event) {
            $event->rules['seo-by-roogul'] = 'seo-by-roogul/globals/edit';
            $event->rules['seo-by-roogul/globals'] = 'seo-by-roogul/globals/edit';
            $event->rules['seo-by-roogul/globals/<siteHandle:{handle}>'] = 'seo-by-roogul/globals/edit';
        });

        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_SITE_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $settings = $this->getSettings();

            if ($settings->sitemapEnabled) {
                $event->rules['sitemap.xml'] = 'seo-by-roogul/sitemap/index';
                $event->rules['sitemap-<type:entries|singles|categories|products>-<handle:\w+>-<page:\d+>.xml'] = 'seo-by-roogul/sitemap/page';
            }

            if ($settings->robotsEnabled) {
                $event->rules['robots.txt'] = 'seo-by-roogul/robots/index';
            }
        });
    }

    private function registerCacheInvalidation(): void
    {
        $handler = function(Event $event) {
            /** @var \craft\events\ElementEvent $event */
            $element = $event->element ?? null;

            if (!$element || ElementHelper::isDraftOrRevision($element)) {
                return;
            }

            if (
                $element instanceof Entry ||
                $element instanceof Category ||
                (class_exists(\craft\commerce\elements\Product::class) && $element instanceof \craft\commerce\elements\Product)
            ) {
                $this->getSitemap()->invalidate();
            }
        };

        Event::on(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT, $handler);
        Event::on(Elements::class, Elements::EVENT_AFTER_DELETE_ELEMENT, $handler);

        // Settings deployed via project config (e.g. `craft up`) don't fire the save event
        Event::on(ProjectConfig::class, ProjectConfig::EVENT_AFTER_APPLY_CHANGES, function() {
            $this->getSitemap()->invalidate();
        });

        Event::on(Plugins::class, Plugins::EVENT_AFTER_SAVE_PLUGIN_SETTINGS, function(PluginEvent $event) {
            if ($event->plugin === $this) {
                $this->getSitemap()->invalidate();
            }
        });
    }

    /**
     * Adds the tags to {{ head() }} once the page template has finished
     * rendering, so craft.seoByRoogul.set() works from anywhere in a template.
     */
    private function registerHeadOutput(): void
    {
        Event::on(View::class, View::EVENT_END_PAGE, function(Event $event) {
            /** @var View $view */
            $view = $event->sender;

            if (
                !$this->getSettings()->autoInject ||
                $view->getTemplateMode() !== View::TEMPLATE_MODE_SITE ||
                $this->getMeta()->rendered
            ) {
                return;
            }

            $this->getMeta()->registerTags($view);
        });
    }
}
