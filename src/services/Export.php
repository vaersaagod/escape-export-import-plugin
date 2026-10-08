<?php

namespace escape\exportimport\services;

use Craft;

use craft\base\Component;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\ElementHelper;
use craft\helpers\StringHelper;
use craft\web\Request;
use escape\exportimport\controllers\ImportController;
use escape\exportimport\ExportImport;
use escape\exportimport\models\Settings;
use escape\exportimport\transformers\ElementTransformer;
use yii\base\Exception;

/**
 * Class Export
 * @package escape\exportimport\services
 */
class Export extends Component
{

    /** @var string[] */
    const EXPORTABLE_SOURCE_TYPES = ['section', 'group'];

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

        /** @var Settings $settings */
        $settings = ExportImport::getInstance()->getSettings();

        switch (get_class($element)) {
            case Entry::class:
                /** @var Entry $element */
                $exportableSections = $settings->exportableSections ?? [];
                return in_array($element->getSection()?->handle, $exportableSections);
            case Asset::class:
                return true;
            case Category::class:
                /** @var Category $element */
                $exportableCategoryGroups = $settings->exportableCategoryGroups ?? [];
                return in_array($element->getGroup()->handle, $exportableCategoryGroups);
        }

        return false;
    }

    /**
     * @param string $key
     * @return bool
     */
    public function getIsSourceExportable(string $key): bool
    {

        $temp = explode('/', $key);
        $temp = [0] ?? null;
        if (!$temp) {
            return false;
        }

        $temp = explode(':', $key);
        $sourceType = $temp[0] ?? null;

        if (!$sourceType || !in_array($sourceType, self::EXPORTABLE_SOURCE_TYPES)) {
            return false;
        }

        $sourceUid = $temp[1] ?? null;
        if (!$sourceUid || !StringHelper::isUUID($sourceUid)) {
            return false;
        }

        $settings = ExportImport::getInstance()->getSettings();

        switch ($sourceType) {
            case 'section':
                $section = Craft::$app->getEntries()->getSectionByUid($sourceUid);
                if (!$section) {
                    return false;
                }
                return in_array($section->handle, $settings->exportableSections ?? []);
            case 'group':
                $categoryGroup = Craft::$app->getCategories()->getGroupByUid($sourceUid);
                if (!$categoryGroup) {
                    return false;
                }
                return in_array($categoryGroup->handle, $settings->exportableCategoryGroups ?? []);
        }

        return false;
    }

    /**
     * Returns whether a request may fetch exports: it either sends the token, or comes from a user who can import.
     * The one gate for the export and files endpoints.
     *
     * @param Request $request
     * @param User|null $user
     * @return bool
     */
    public function getCanRequestExport(Request $request, ?User $user): bool
    {
        /** @var Settings $settings */
        $settings = ExportImport::getInstance()->getSettings();
        $token = $settings->getToken();
        $requestToken = $request->getHeaders()->get(ExportImport::TOKEN_HEADER);

        if ($token && is_string($requestToken) && hash_equals($token, $requestToken)) {
            return true;
        }

        return (bool)$user?->can(ImportController::PERMISSION_IMPORT);
    }

    /**
     * Returns an element if it exists and is exportable. Doesn't say which, so the export can't be used to probe for IDs.
     *
     * @param int $elementId
     * @param int|null $siteId
     * @return ElementInterface|null
     * @throws \craft\errors\SiteNotFoundException
     * @throws \yii\base\InvalidConfigException
     */
    public function getExportableElementById(int $elementId, ?int $siteId = null): ?ElementInterface
    {
        if (!$siteId) {
            $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        }

        $element = Craft::$app->getElements()->getElementById($elementId, null, $siteId);

        if (!$element || !$this->getIsElementExportable($element)) {
            return null;
        }

        return $element;
    }

    /**
     * @param int $elementId
     * @param int|null $siteId
     * @return array
     * @throws \craft\errors\SiteNotFoundException
     * @throws \yii\base\InvalidConfigException
     * @throws Exception
     */
    public function getElementDataById(int $elementId, ?int $siteId = null): array
    {
        $element = $this->getExportableElementById($elementId, $siteId);

        if (!$element) {
            throw new \Exception("Element ID $elementId not found");
        }

        return new ElementTransformer()->transform($element);
    }

}
