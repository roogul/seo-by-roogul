<?php

namespace roogul\seo\web\assets\field;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class FieldAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [CpAsset::class];
        $this->js = ['field.js'];
        $this->css = ['field.css'];

        parent::init();
    }
}
