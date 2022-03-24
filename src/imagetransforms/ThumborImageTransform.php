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

use Craft;
use craft\awss3\Fs as AwsFs;
use craft\elements\Asset;
use craft\helpers\App;
use craft\models\ImageTransform as CraftImageTransformModel;
use nystudio107\imageoptimize\ImageOptimize;
use nystudio107\imageoptimize\imagetransforms\ImageTransform;
use Thumbor\Url\Builder as UrlBuilder;
use yii\base\InvalidConfigException;
use function class_exists;

/**
 * @author    nystudio107
 * @package   ImageOptimize
 * @since     1.3.0
 */
class ThumborImageTransform extends ImageTransform
{
    // Public Properties
    // =========================================================================

    /**
     * @var string
     */
    public string $baseUrl = '';

    /**
     * @var string
     */
    public string $securityKey = '';

    /**
     * @var bool
     */
    public bool $includeBucketPrefix = false;

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('image-optimize', 'Thumbor');
    }

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function getTransformUrl(Asset $asset, CraftImageTransformModel|string|array|null $transform): ?string
    {
        if ($asset->getExtension() === 'svg') {
            return null;
        }

        $this->baseUrl = App::parseEnv($this->baseUrl);
        $this->securityKey = App::parseEnv($this->securityKey);

        return (string)$this->getUrlBuilderForTransform($asset, $transform);
    }

    /**
     * @inheritdoc
     */
    public function getWebPUrl(string $url, Asset $asset, CraftImageTransformModel|string|array|null $transform): ?string
    {
        $builder = $this->getUrlBuilderForTransform($asset, $transform)
            ->addFilter('format', 'webp');

        return (string)$builder;
    }

    /**
     * @inheritdoc
     */
    public function purgeUrl(string $url): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    public function getAssetUri(Asset $asset): ?string
    {
        $uri = parent::getAssetUri($asset);
        try {
            $volumeFs = $asset->getVolume()->getFs();
        } catch (InvalidConfigException $e) {
            Craft::error($e->getMessage(), __METHOD__);
            $volumeFs = null;
        }

        if ($this->includeBucketPrefix && ($volumeFs instanceof AwsFs)) {
            $bucket = App::parseEnv($volumeFs->bucket);
            $uri = $bucket . '/' . $uri;
        }

        return $uri;
    }

    /**
     * @inheritdoc
     */
    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('thumbor-image-transform/settings/image-transforms/thumbor.twig', [
            'imageTransform' => $this,
            'awsS3Installed' => class_exists(AwsFs::class),
        ]);
    }

    /**
     * @inheritdoc
     */
    public function rules(): array
    {
        $rules = parent::rules();

        return array_merge($rules, [
            [['baseUrl', 'securityKey'], 'default', 'value' => ''],
            [['baseUrl', 'securityKey'], 'string'],
        ]);
    }

    // Private Methods
    // =========================================================================

    /**
     * @param Asset $asset
     * @param CraftImageTransformModel|string|array|null $transform
     *
     * @return UrlBuilder
     */
    private function getUrlBuilderForTransform(Asset $asset, CraftImageTransformModel|string|array|null $transform): UrlBuilder
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
            $widthScale = (int)((($transform->width ?? $asset->getWidth()) / $asset->getWidth()) * 100);
            $heightScale = (int)((($transform->height ?? $asset->getHeight()) / $asset->getHeight()) * 100);
            if (($widthScale >= $settings->sharpenScaledImagePercentage) || ($heightScale >= $settings->sharpenScaledImagePercentage)) {
                // https://thumbor.readthedocs.io/en/latest/sharpen.html
                $builder->addFilter('sharpen', .5, .5, 'true');
            }
        }

        return $builder;
    }

    /**
     * @param Asset $asset
     * @return string|null
     */
    private function getFocalPoint(Asset $asset): ?string
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
     * @param CraftImageTransformModel|string|array|null $transform
     *
     * @return ?string
     */
    private function getFormat(CraftImageTransformModel|string|array|null $transform): ?string
    {
        $format = str_replace('jpg', 'jpeg', $transform->format);

        return $format ?: null;
    }

    /**
     * @param CraftImageTransformModel|string|array|null $transform
     *
     * @return int
     */
    private function getQuality(CraftImageTransformModel|string|array|null $transform): int
    {
        return $transform->quality ?? Craft::$app->getConfig()->getGeneral()->defaultImageQuality;
    }
}
