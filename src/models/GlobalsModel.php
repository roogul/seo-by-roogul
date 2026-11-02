<?php

namespace roogul\seo\models;

use Craft;
use craft\base\Model;
use craft\elements\Asset;

/**
 * Site-wide SEO defaults for one site. Stored in the database (not project
 * config) so editors can change them on production.
 */
class GlobalsModel extends Model
{
    public const ENTITY_ORGANIZATION = 'organization';
    public const ENTITY_PERSON = 'person';

    public const DEFAULT_TITLE_TEMPLATE = '{title} | {siteName}';
    public const DEFAULT_ROBOTS = "User-agent: *\nDisallow:\n\nSitemap: {sitemapUrl}";

    /** Attributes editable in the CP and stored in the table. */
    public const ATTRIBUTES = [
        'siteName',
        'titleTemplate',
        'description',
        'imageId',
        'twitterHandle',
        'entityType',
        'entityName',
        'entityLogoId',
        'sameAs',
        'robotsTxt',
        'noindex',
    ];

    public ?int $id = null;
    public ?int $siteId = null;

    public string $siteName = '';
    public string $titleTemplate = '';
    public string $description = '';
    public ?int $imageId = null;
    public string $twitterHandle = '';
    public string $entityType = self::ENTITY_ORGANIZATION;
    public string $entityName = '';
    public ?int $entityLogoId = null;
    /** Social profile URLs, one per line. */
    public string $sameAs = '';
    public string $robotsTxt = '';
    public bool $noindex = false;

    private ?Asset $_image = null;
    private ?Asset $_logo = null;

    /**
     * Sets attributes from a CP form post.
     */
    public function setFromPost(array $data): void
    {
        foreach (self::ATTRIBUTES as $attr) {
            if (!array_key_exists($attr, $data)) {
                continue;
            }

            $value = $data[$attr];

            switch ($attr) {
                case 'imageId':
                case 'entityLogoId':
                    // element selects post an array of IDs
                    $value = is_array($value) ? reset($value) : $value;
                    $this->$attr = $value ? (int)$value : null;
                    break;
                case 'noindex':
                    $this->$attr = (bool)$value;
                    break;
                default:
                    $this->$attr = trim((string)$value);
            }
        }

        $this->twitterHandle = ltrim($this->twitterHandle, '@');
        $this->_image = null;
        $this->_logo = null;
    }

    protected function defineRules(): array
    {
        return [
            [['twitterHandle'], 'match', 'pattern' => '/^\w{1,15}$/', 'message' => Craft::t('seo-by-roogul', 'Enter a valid handle, e.g. @roogul.')],
            [['entityType'], 'in', 'range' => [self::ENTITY_ORGANIZATION, self::ENTITY_PERSON]],
            [['sameAs'], 'validateSameAs'],
            [['siteName', 'titleTemplate', 'entityName'], 'string', 'max' => 255],
        ];
    }

    public function validateSameAs(string $attribute): void
    {
        foreach ($this->getSameAsUrls() as $url) {
            if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
                $this->addError($attribute, Craft::t('seo-by-roogul', '“{url}” isn’t a valid URL.', ['url' => $url]));
            }
        }
    }

    /**
     * @return string[]
     */
    public function getSameAsUrls(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', $this->sameAs))));
    }

    public function getImage(): ?Asset
    {
        if ($this->imageId && !$this->_image) {
            $this->_image = Asset::find()->id($this->imageId)->status(null)->one();
        }

        return $this->_image;
    }

    public function getEntityLogo(): ?Asset
    {
        if ($this->entityLogoId && !$this->_logo) {
            $this->_logo = Asset::find()->id($this->entityLogoId)->status(null)->one();
        }

        return $this->_logo;
    }

    /**
     * Row data for the database.
     */
    public function toRow(): array
    {
        $row = [];

        foreach (self::ATTRIBUTES as $attr) {
            $row[$attr] = $this->$attr;
        }

        return $row;
    }
}
