<?php

namespace escape\exportimport\services;

use Craft;

use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\helpers\ElementHelper;
use escape\exportimport\ExportImport;
use escape\exportimport\transformers\ElementTransformer;

/**
 * Class Export
 * @package escape\exportimport\services
 */
class Export extends \craft\base\Component
{

    /** @var string[] */
    const EXPORTABLE_SOURCE_TYPES = ['section'];

    /**
     * @param ElementInterface $element
     * @return bool
     * @throws \yii\base\InvalidConfigException
     */
    public function getIsElementExportable(ElementInterface $element): bool
    {

        if (ElementHelper::isDraftOrRevision($element)) {
            return false;
        }

        $settings = ExportImport::getInstance()->getSettings();

        switch (\get_class($element)) {
            case Entry::class:
                /** @var Entry $element */
                $exportableSections = $settings->exportableSections ?? [];
                return \in_array($element->getSection()->handle, $exportableSections);
            case Asset::class:
                return true;
        }

        return false;
    }

    /**
     * @param string $key
     * @return bool
     */
    public function getIsSourceExportable(string $key): bool
    {

        $temp = \explode(':', $key);
        $sourceType = $temp[0] ?? null;
        $sourceUid = $temp[1] ?? null;

        if (!$sourceType || !$sourceUid) {
            return false;
        }

        if (!\in_array($sourceType, self::EXPORTABLE_SOURCE_TYPES)) {
            return false;
        }

        $settings = ExportImport::getInstance()->getSettings();

        switch ($sourceType) {
            case 'section':
                $section = Craft::$app->getSections()->getSectionByUid($sourceUid);
                return \in_array($section->handle, $settings->exportableSections ?? []);
        }

        return false;
    }

    /**
     * @param int $elementId
     * @param int|null $siteId
     * @return array
     * @throws \craft\errors\SiteNotFoundException
     */
    public function getElementDataById(int $elementId, int $siteId = null): array
    {

        if (!$siteId) {
            $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        }

        $element = Craft::$app->getElements()->getElementById($elementId, null, $siteId);

        if (!$element) {
            throw new \Exception('Invalid element ID');
        }

        if (!$this->getIsElementExportable($element)) {
            throw new \Exception('Element is not exportable');
        }

        return (new ElementTransformer())->transform($element);
    }

}
