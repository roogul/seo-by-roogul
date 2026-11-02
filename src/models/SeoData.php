<?php

namespace roogul\seo\models;

use craft\base\Model;
use craft\elements\Asset;

/**
 * The value of an SEO field. Blank values fall back (see Meta service).
 */
class SeoData extends Model
{
    public string $title = '';
    /** Use the title without the global title template. */
    public bool $rawTitle = false;
    public string $description = '';
    public ?int $imageId = null;
    public string $canonical = '';
    public bool $noindex = false;
    public bool $nofollow = false;

    private ?Asset $_image = null;

    public static function fromValue(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $value = json_decode($value, true);
        }

        $data = new self();

        if (!is_array($value)) {
            return $data;
        }

        $data->title = trim((string)($value['title'] ?? ''));
        $data->rawTitle = (bool)($value['rawTitle'] ?? false);
        $data->description = trim((string)($value['description'] ?? ''));
        $data->canonical = trim((string)($value['canonical'] ?? ''));
        $data->noindex = (bool)($value['noindex'] ?? false);
        $data->nofollow = (bool)($value['nofollow'] ?? false);

        $imageId = $value['imageId'] ?? null;
        $imageId = is_array($imageId) ? reset($imageId) : $imageId;
        $data->imageId = $imageId ? (int)$imageId : null;

        return $data;
    }

    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        return [
            'title' => $this->title,
            'rawTitle' => $this->rawTitle,
            'description' => $this->description,
            'imageId' => $this->imageId,
            'canonical' => $this->canonical,
            'noindex' => $this->noindex,
            'nofollow' => $this->nofollow,
        ];
    }

    public function isEmpty(): bool
    {
        return $this->title === '' &&
            $this->description === '' &&
            $this->imageId === null &&
            $this->canonical === '' &&
            !$this->rawTitle &&
            !$this->noindex &&
            !$this->nofollow;
    }

    public function getImage(): ?Asset
    {
        if ($this->imageId && !$this->_image) {
            $this->_image = Asset::find()->id($this->imageId)->status(null)->one();
        }

        return $this->_image;
    }

    public function __toString(): string
    {
        return $this->title;
    }
}
