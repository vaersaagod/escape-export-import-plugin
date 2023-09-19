<?php

namespace escape\exportimport\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\VolumeInterface;
use craft\db\Query;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\User;
use craft\fields\Assets;
use craft\fields\BaseRelationField;
use craft\fields\Matrix;
use craft\helpers\ElementHelper;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\models\EntryType;
use craft\models\MatrixBlockType;
use craft\models\Volume;
use craft\redactor\Field as RedactorField;
use craft\validators\HandleValidator;

use escape\escapedam\EscapeDam;
use escape\exportimport\helpers\FileHelper;

use verbb\supertable\fields\SuperTableField;
use verbb\supertable\models\SuperTableBlockType;

/**
 * Class Import
 * @package escape\exportimport\services
 */
class Import extends Component
{

    /** @var array */
    protected array $memoizedMatrixBlockTypes = [];

    /** @var array|null */
    protected ?array $sourceSite;

    /** @var User|null */
    protected ?User $author;

    /** @var bool */
    protected bool $includeAssets = true;

    /** @var string|null */
    protected ?string $targetSource = null;

    /**
     * Import an element based on raw data
     *
     * @param array $data
     * @param int $authorId
     * @param bool $enabled
     * @param bool $includeAssets
     * @param string|null $targetSource
     * @return bool
     * @throws \Throwable
     * @throws \craft\errors\ElementNotFoundException
     * @throws \craft\errors\OperationAbortedException
     * @throws \craft\errors\SiteNotFoundException
     * @throws \craft\errors\VolumeException
     * @throws \yii\base\Exception
     * @throws \yii\db\Exception
     */
    public function importElement(array $data, int $authorId, bool $enabled = false, bool $includeAssets = true, ?string $targetSource = null): bool
    {

        $this->sourceSite = $data['site'];
        $this->includeAssets = $includeAssets;

        $this->author = Craft::$app->getUsers()->getUserById($authorId);
        if (!$this->author) {
            throw new \Exception('Invalid author ID');
        }

        $element = null;
        $elementType = $data['element']['elementType'] ?? null;

        if ($elementType === Entry::class) {
            $element = $this->getEntryModel($data, $targetSource);
        } else if ($elementType === Category::class) {
            $element = $this->getCategoryModel($data); // TODO support for target source (category group)
        }

        if (!$element) {
            throw new \Exception("Element type {$elementType} not supported");
        }

        $element->enabled = $enabled;

        // Only get custom fields that exist on the created element
        $customFieldHandles = \array_keys($element->getFieldValues());
        $fieldValues = $data['fields'];
        $fieldValuesToUse = \array_reduce(\array_keys($fieldValues), function (array $carry, string $handle) use ($customFieldHandles, $fieldValues) {
            if (!\in_array($handle, $customFieldHandles)) {
                return $carry;
            }
            $carry[$handle] = $fieldValues[$handle];
            return $carry;
        }, []);

        // Set custom fields and save it again
        $element->setFieldValues($this->normalizeFieldValues($element, $fieldValuesToUse));

        return Craft::$app->getElements()->saveElement($element);

    }

    /**
     * @param ElementInterface $element
     * @param array $fieldValues
     * @param string $context
     * @return array
     * @throws \Throwable
     * @throws \craft\errors\ElementNotFoundException
     * @throws \craft\errors\SiteNotFoundException
     * @throws \craft\errors\VolumeException
     * @throws \yii\base\Exception
     * @throws \yii\db\Exception
     */
    protected function normalizeFieldValues(ElementInterface $element, array $fieldValues, string $context = 'global'): array
    {

        $fields = [];

        foreach ($fieldValues as $fieldHandle => $fieldValue) {

            $field = Craft::$app->getFields()->getFieldByHandle($fieldHandle, $context);
            if (!$field) {
                continue;
            }

            if ($field instanceof Matrix) {

                $fieldValue = $this->normalizeMatrixFieldValue($element, $field, $fieldValue);

            } else if ($field instanceof SuperTableField) {

                $fieldValue = $this->normalizeSuperTableFieldValue($element, $field, $fieldValue);

            } else if ($field instanceof Assets) {

                $fieldValue = $this->normalizeAssetsFieldValue($element, $field, $fieldValue);

            } else if ($field instanceof RedactorField) {

                $fieldValue = $this->normalizeRedactorFieldValue($element, $field, $fieldValue);

            } else if ($field instanceof BaseRelationField) {

                $fieldValue = [];

            }

            $fields[$fieldHandle] = $fieldValue;
        }

        return $fields;
    }

    /**
     * @param ElementInterface $element
     * @param RedactorField $field
     * @param $fieldValue
     * @return mixed
     */
    protected function normalizeRedactorFieldValue(ElementInterface $element, RedactorField $field, $fieldValue)
    {
        if ($fieldValue) {
            $fieldValue = $this->stripRefsFromRedactorFieldValue($field, $fieldValue);
            $fieldValue = $field->normalizeValue($fieldValue);
        }

        return $fieldValue;
    }

    /**
     * @param ElementInterface $element
     * @param Assets $field
     * @param $fieldValue
     * @return array
     * @throws \Throwable
     * @throws \craft\errors\ElementNotFoundException
     * @throws \craft\errors\SiteNotFoundException
     * @throws \craft\errors\VolumeException
     * @throws \yii\base\Exception
     * @throws \yii\db\Exception
     */
    protected function normalizeAssetsFieldValue(ElementInterface $element, Assets $field, $fieldValue): array
    {

        if (!$this->includeAssets) {
            return [];
        }

        $sourceSite = $this->sourceSite ?? [];
        $sourceSiteUrl = $sourceSite['baseUrl'] ?? null;
        if (!$sourceSiteUrl) {
            throw new \Exception("Unable to download asset; no source site URL");
        }

        $sourceSiteUrl = \rtrim($sourceSiteUrl, '/');
        $sourceSiteId = $sourceSite['siteId'] ?? null;
        $assetIds = $fieldValue ?: [];
        $fieldValue = [];

        $damPluginInstalled = !!Craft::$app->getPlugins()->getPlugin('escapedam');

        foreach ($assetIds as $assetId) {

            try {

                $jsonUrl = UrlHelper::urlWithParams("{$sourceSiteUrl}/actions/escape-exportimport/export", [
                    'elementId' => $assetId,
                    'siteId' => $sourceSiteId,
                ]);

                $json = FileHelper::readFile($jsonUrl);
                $data = $json && Json::isJsonObject($json) ? Json::decode($json) : null;

                if (!$data || empty($data)) {
                    continue;
                }

                $data = $data[0];

                if (($data['element']['elementType'] ?? null) !== Asset::class) {
                    continue;
                }

                $asset = null;

                // Is the image from the DAM?
                $damData = $data['element']['damData'] ?? [];
                $damId = (int)($damData['id'] ?? null);

                if ($damPluginInstalled && $damId) {
                    $asset = EscapeDam::getInstance()->files->importFile($damId, $field->id, $element->id, $element->siteId, null, $this->author->id);
                }

                if (!$asset) {

                    // Not from DAM, download and create Asset
                    $volumeHandle = $data['element']['volume'] ?? null;
                    /** @var VolumeInterface $volume */
                    if (!$volumeHandle || !$volume = Craft::$app->getVolumes()->getVolumeByHandle($volumeHandle)) {
                        throw new \Exception("Invalid volume handle \"{$volumeHandle}\"");
                    }

                    // Make sure that the folder exists and that we have a folderId
                    $folderPath = $data['attributes']['folderPath'];

                    if (!$folderPath) {
                        // No folder path – use the root folder
                        $folderId = Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id)?->id;
                    } else {
                        $folderId = Craft::$app->getAssets()->ensureFolderByFullPathAndVolume($folderPath, $volume, false)?->id;
                    }

                    if (empty($folderId)) {
                        throw new \Exception('Invalid folder');
                    }

                    // Check to see if we have this Asset locally already
                    $filename = $data['attributes']['filename'];
                    $kind = $data['attributes']['kind'];

                    $asset = Asset::find()
                        ->volumeId($volume->id)
                        ->folderId($folderId)
                        ->filename($filename)
                        ->kind($kind)
                        ->one();

                    if ($asset) {
                        // Sweet, this Asset already exists! Skip the rest
                        $fieldValue[] = $asset->id;
                        continue;
                    }

                    // Have to download the file
                    $sourceFileUrl = UrlHelper::urlWithParams("{$sourceSiteUrl}/actions/escape-exportimport/files", ['assetId' => $assetId]);
                    $tempPath = FileHelper::getTempPath();
                    FileHelper::downloadFile($sourceFileUrl, $tempPath);

                    if (!\file_exists($tempPath) || !\is_file($tempPath)) {
                        throw new \Exception('Unable to download remote file');
                    }

                    // Create the Asset
                    $asset = $this->getAssetModel($data, $volume);
                    $asset->tempFilePath = $tempPath;
                    $asset->newFolderId = $folderId;

                    // Set custom fields
                    $fields = $this->normalizeFieldValues($element, $data['fields'] ?? []);
                    $asset->setFieldValues($fields);

                    Craft::$app->getElements()->saveElement($asset);

                }

                if (!$asset || !$asset->id) {
                    throw new \Exception('Unable to save Asset');
                }

                $fieldValue[] = $asset->id;

            } catch (\Exception $e) {
                Craft::error($e->getMessage(), __METHOD__);
                if (Craft::$app->getConfig()->getGeneral()->devMode) {
                    throw $e;
                }
            }
        }

        return $fieldValue;
    }

    /**
     * @param ElementInterface $element
     * @param SuperTableField $field
     * @param $fieldValue
     * @return array
     * @throws \Exception
     */
    protected function normalizeSuperTableFieldValue(ElementInterface $element, SuperTableField $field, $fieldValue): array
    {
        return \array_map(function (array $block) use ($element, $field) {
            /** @var SuperTableBlockType $blockType */
            $blockType = $this->getSuperTableBlockTypeForField($field);
            return \array_merge($block, [
                'type' => $blockType->id,
                'fields' => $this->normalizeFieldValues($element, $block['fields'] ?? [], "superTableBlockType:{$blockType->uid}"),
            ]);
        }, \array_values($fieldValue ?? []));
    }

    /**
     * @param ElementInterface $element
     * @param Matrix $field
     * @param $fieldValue
     * @return array
     * @throws \Exception
     */
    protected function normalizeMatrixFieldValue(ElementInterface $element, Matrix $field, $fieldValue): array
    {
        return \array_map(function (array $block) use ($element, $field) {
            $blockType = $this->getMatrixBlockTypeByHandle($block['type'], $field);
            return \array_merge($block, [
                'fields' => $this->normalizeFieldValues($element, $block['fields'] ?? [], "matrixBlockType:{$blockType->uid}"),
            ]);
        }, \array_values($fieldValue ?? []));
    }

    /**
     * Return a MatrixBlockType instance for a field, based on handle
     * I wish this was in core, but it ain't
     *
     * @param string $blockTypeHandle
     * @param Field $field
     * @return MatrixBlockType
     * @throws \Exception
     */
    protected function getMatrixBlockTypeByHandle(string $blockTypeHandle, Field $field): MatrixBlockType
    {
        if (!isset($this->memoizedMatrixBlockTypes[$field->uid])) {
            $blockTypes = Craft::$app->getMatrix()->getBlockTypesByFieldId($field->id);
            $this->memoizedMatrixBlockTypes[$field->uid] = \array_reduce($blockTypes, function (array $carry, MatrixBlockType $blockType) {
                $carry[$blockType->handle] = $blockType;
                return $carry;
            }, []);
        }
        $blockType = $this->memoizedMatrixBlockTypes[$field->uid][$blockTypeHandle] ?? null;
        if (!$blockType) {
            throw new \Exception("Invalid block type handle \"{$blockTypeHandle}\"");
        }
        return $blockType;
    }

    /**
     * @param SuperTableField $field
     * @return SuperTableBlockType
     * @throws \Exception
     */
    protected function getSuperTableBlockTypeForField(SuperTableField $field): SuperTableBlockType
    {
        $blockTypes = $field->getBlockTypes();
        if (empty($blockTypes)) {
            throw new \Exception("Unable to get block type for Super Table field \"{$field->handle}\"");
        }
        /** @var SuperTableBlockType $blockType */
        $blockType = $blockTypes[0];
        return $blockType;
    }

    /**
     * Return a folder's ID based on its volume and folder path
     *
     * @param int $volumeId
     * @param string $folderPath
     * @return int
     */
    protected function getFolderIdByVolumeIdAndFolderPath(int $volumeId, string $folderPath): int
    {
        return (int)(new Query())
            ->select(['volumefolders.id'])
            ->from('{{%volumefolders}} AS volumefolders')
            ->where('volumefolders.volumeId=:volumeId', [':volumeId' => $volumeId])
            ->andWhere('volumefolders.path=:folderPath', [':folderPath' => $folderPath])
            ->scalar();
    }

    /**
     * @param RedactorField $field
     * @param $fieldValue
     * @return array|string|string[]|null
     */
    protected function stripRefsFromRedactorFieldValue(RedactorField $field, $fieldValue)
    {
        if (!StringHelper::contains($fieldValue, '{')) {
            return $fieldValue;
        }

        return \preg_replace_callback('/(href=|src=)([\'"])(\{([\w\\\\]+\:\d+(?:@\d+)?\:(?:transform\:)?' . HandleValidator::$handlePattern . ')(?:\|\|[^\}]+)?\})(?:\?([^\'"#]*))?(#[^\'"#]+)?\2/', function($matches) {
            return '';
        }, $fieldValue);
    }

    /**
     * @param array $data
     * @param string|null $targetSource
     * @return Entry
     * @throws \craft\errors\OperationAbortedException
     */
    protected function getEntryModel(array $data, ?string $targetSource = null): Entry
    {

        if ($targetSource && StringHelper::startsWith($targetSource, 'section:')) {
            // Get section from target source
            $temp = \explode(':', $targetSource);
            $targetSourceUid = $temp[1] ?? '';
            if (!StringHelper::isUUID($targetSourceUid) || !$section = Craft::$app->getSections()->getSectionByUid($targetSourceUid)) {
                throw new \Exception("Invalid target section UUID");
            }
            // Get entry type
            $entryType = ($section->getEntryTypes())[0];
        } else {
            // Get section from imported element
            $sectionHandle = $data['element']['section'] ?? null;
            $section = $sectionHandle ? Craft::$app->getSections()->getSectionByHandle($sectionHandle) : null;
            if (!$section) {
                throw new \Exception("Invalid section handle \"{$sectionHandle}\"");
            }
            // Get entry type
            $entryTypeHandle = $data['element']['type'] ?? null;
            /** @var EntryType $entryType */
            $entryType = null;
            foreach ($section->getEntryTypes() as $sectionEntryType) {
                if ($sectionEntryType->handle === $entryTypeHandle) {
                    $entryType = $sectionEntryType;
                    break;
                }
            }
            if (!$entryType) {
                throw new \Exception("Invalid entry type \"{$entryTypeHandle}\"");
            }
        }

        $entry = new Entry();
        $entry->authorId = $this->author->id;
        $entry->sectionId = $section->id;
        $entry->typeId = $entryType->id;
        $entry->title = $data['attributes']['title'];
        $entry->slug = $data['attributes']['slug'];
        $entry->postDate = new \DateTime(date('Y-m-d H:i:s', strtotime($data['attributes']['postDate'])));

        $sourceSiteUrl = ($this->sourceSite ?? [])['baseUrl'] ?? null;
        if ($sourceSiteUrl) {
            $entry->revisionNotes = "Imported from {$sourceSiteUrl}";
        }

        if ($entry::hasUris()) {
            ElementHelper::setUniqueUri($entry);
        }

        return $entry;

    }

    /**
     * @param array $data
     * @return Category
     * @throws \craft\errors\OperationAbortedException
     */
    protected function getCategoryModel(array $data): Category
    {

        // Get category group
        $categoryGroupHandle = $data['element']['group'] ?? null;
        $categoryGroup = $categoryGroupHandle ? Craft::$app->getCategories()->getGroupByHandle($categoryGroupHandle) : null;
        if (!$categoryGroup) {
            throw new \Exception("Invalid category group handle \"{$categoryGroupHandle}\"");
        }

        $category = new Category();
        $category->groupId = $categoryGroup->id;
        $category->title = $data['attributes']['title'];
        $category->slug = $data['attributes']['slug'];

        if ($category::hasUris()) {
            ElementHelper::setUniqueUri($category);
        }

        return $category;

    }

    /**
     * @param array $data
     * @param Volume $volume
     * @return Asset
     * @throws \Exception
     */
    protected function getAssetModel(array $data, Volume $volume): Asset
    {
        $asset = new Asset();
        $asset->uploaderId = $this->author->id;
        $asset->volumeId = $volume->id;
        $asset->filename = $data['attributes']['filename'];
        $asset->avoidFilenameConflicts = true;
        $asset->focalPoint = $data['attributes']['focalPoint'] ?? ['x' => 0.5, 'y' => 0.5];
        $asset->setScenario(Asset::SCENARIO_CREATE);
        return $asset;
    }

}
