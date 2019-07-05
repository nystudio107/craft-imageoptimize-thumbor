<?php
/**
 * ImageOptimize plugin for Craft CMS 3.x
 *
 * Automatically optimize images after they've been transformed
 *
 * @link      https://nystudio107.com
 * @copyright Copyright (c) 2017 nystudio107
 */

namespace nystudio107\imageoptimizethumbor\imagetransforms;

use nystudio107\imageoptimize\ImageOptimize;
use nystudio107\imageoptimize\imagetransforms\ImageTransform;

use craft\elements\Asset;
use craft\models\AssetTransform;
use Thumbor\Url\Builder as UrlBuilder;

use Craft;

/**
 * @author    nystudio107
 * @package   ImageOptimize
 * @since     1.3.0
 */
class ThumborImageTransform extends ImageTransform
{
    // Static Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('image-optimize', 'Thumbor');
    }

    // Public Properties
    // =========================================================================

    /**
     * @var string
     */
    public $baseUrl;

    /**
     * @var string
     */
    public $securityKey;

    /**
     * @var bool
     */
    public $includeBucketPrefix = false;

    // Public Methods
    // =========================================================================

    /**
     * @param Asset               $asset
     * @param AssetTransform|null $transform
     *
     * @return string|null
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     */
    public function getTransformUrl(Asset $asset, $transform)
    {
        if ($asset->getExtension() === 'svg') {
            return null;
        }

        if (ImageOptimize::$craft31) {
            $this->baseUrl = Craft::parseEnv($this->baseUrl);
            $this->securityKey = Craft::parseEnv($this->securityKey);
        }

        return (string)$this->getUrlBuilderForTransform($asset, $transform);
    }

    /**
     * @param string              $url
     * @param Asset               $asset
     * @param AssetTransform|null $transform
     *
     * @return string
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     */
    public function getWebPUrl(string $url, Asset $asset, $transform): string
    {
        $builder = $this->getUrlBuilderForTransform($asset, $transform)
            ->addFilter('format', 'webp');

        return (string)$builder;
    }

    /**
     * @param string $url
     *
     * @return bool
     */
    public function purgeUrl(string $url): bool
    {
        return false;
    }

    // Private Methods
    // =========================================================================

    /**
     * @param Asset               $asset
     * @param AssetTransform|null $transform
     *
     * @return UrlBuilder
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     */
    private function getUrlBuilderForTransform(Asset $asset, $transform): UrlBuilder
    {
        $assetUri = $this->getAssetUri($asset);
        $builder = UrlBuilder::construct($this->baseUrl, $this->securityKey, $assetUri);
        $settings = ImageOptimize::$plugin->getSettings();

        if ($transform->mode === 'fit') {
            // https://thumbor.readthedocs.io/en/latest/usage.html#fit-in
            $builder->fitIn($transform->width, $transform->height);
        } elseif ($transform->mode === 'stretch') {
            // https://github.com/thumbor/thumbor/pull/1125
            $builder
                ->resize($transform->width, $transform->height)
                ->addFilter('stretch');
        } else {

            // https://thumbor.readthedocs.io/en/latest/usage.html#image-size
            $builder->resize($transform->width, $transform->height);

            if ($focalPoint = $this->getFocalPoint($asset)) {
                // https://thumbor.readthedocs.io/en/latest/focal.html
                $builder->addFilter('focal', $focalPoint);
            } elseif (preg_match('/(top|center|bottom)-(left|center|right)/', $transform->position, $matches)) {
                $v = str_replace('center', 'middle', $matches[1]);
                $h = $matches[2];

                // https://thumbor.readthedocs.io/en/latest/usage.html#horizontal-align
                $builder->valign($v)->halign($h);
            }
        }

        // https://thumbor.readthedocs.io/en/latest/format.html
        if ($format = $this->getFormat($transform)) {
            $builder->addFilter('format', $format);
        }

        // https://thumbor.readthedocs.io/en/latest/quality.html
        if ($quality = $this->getQuality($transform)) {
            $builder->addFilter('quality', $quality);
        }

        if (property_exists($transform, 'interlace')) {
            Craft::warning('Thumbor enables progressive JPEGs on the server-level, not as a request option. See https://thumbor.readthedocs.io/en/latest/jpegtran.html', __METHOD__);
        }

        if ($settings->autoSharpenScaledImages) {
            // See if the image has been scaled >= 50%
            $widthScale = $asset->getWidth() / ($transform->width ?? $asset->getWidth());
            $heightScale = $asset->getHeight() / ($transform->height ?? $asset->getHeight());
            if (($widthScale >= 2.0) || ($heightScale >= 2.0)) {
                // https://thumbor.readthedocs.io/en/latest/sharpen.html
                $builder->addFilter('sharpen', .5, .5, 'true');
            }
        }

        return $builder;
    }

    /**
     * @return string|null
     */
    private function getFocalPoint(Asset $asset)
    {
        $focalPoint = $asset->getFocalPoint();

        if (!$focalPoint) {
            return null;
        }

        $box = array_map('intval', [
            'top' => $focalPoint['y'] * $asset->height - 1,
            'left' => $focalPoint['x'] * $asset->width - 1,
            'bottom' => $focalPoint['y'] * $asset->height + 1,
            'right' => $focalPoint['x'] * $asset->width + 1,
        ]);

        return implode('', [
            $box['left'],
            'x',
            $box['top'],
            ':',
            $box['right'],
            'x',
            $box['bottom'],
        ]);
    }

    /**
     * @param AssetTransform|null $transform
     *
     * @return string|null
     */
    private function getFormat($transform)
    {
        $format = str_replace('jpg', 'jpeg', $transform->format);

        return $format ?: null;
    }

    /**
     * @param AssetTransform|null $transform
     *
     * @return int
     */
    private function getQuality($transform)
    {
        return $transform->quality ?? Craft::$app->getConfig()->getGeneral()->defaultImageQuality;
    }

    /**
     * @param Asset $asset
     *
     * @return mixed
     * @throws \yii\base\InvalidConfigException
     */
    public function getAssetUri(Asset $asset)
    {
        $uri = parent::getAssetUri($asset);
        $volume = $asset->getVolume();

        if ($this->includeBucketPrefix && ($volume->bucket ?? null)) {
            $bucket = ImageOptimize::$craft31 ? Craft::parseEnv($volume->bucket) : $volume->bucket;
            $uri = $bucket . '/' . $uri;
        }

        return $uri;
    }

    public function getSettingsHtml()
    {
        return Craft::$app->getView()->renderTemplate('thumbor-image-transform/settings/image-transforms/thumbor.twig', [
            'imageTransform' => $this,
            'awsS3Installed'    => \class_exists(\craft\awss3\Volume::class),
        ]);
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        $rules = parent::rules();
        $rules = array_merge($rules, [
            [['baseUrl', 'securityKey'], 'default', 'value' => ''],
            [['baseUrl', 'securityKey'], 'string'],
        ]);

        return $rules;
    }
}
