<?php

namespace escape\exportimport\services;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\VolumeInterface;
use craft\db\Query;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\fields\Assets;
use craft\fields\BaseRelationField;
use craft\fields\Matrix;
use craft\helpers\Db;
use craft\helpers\ElementHelper;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\models\MatrixBlockType;
use craft\models\VolumeFolder;
use craft\redactor\Field as RedactorField;
use escape\exportimport\helpers\FileHelper;
use verbb\supertable\fields\SuperTableField;
use verbb\supertable\models\SuperTableBlockTypeModel;

/**
 * Class Import
 * @package escape\exportimport\services
 */
class Import extends \craft\base\Component
{

    /** @var array */
    protected $memoizedMatrixBlockTypes = [];

    /** @var array|null */
    protected $sourceSite;

    /** @var bool */
    protected $includeAssets = true;

    /**
     * Import an element based on raw data
     *
     * @param array $data
     * @param bool $enabled
     * @param bool $includeAssets
     * @return bool
     * @throws \Throwable
     * @throws \craft\errors\ElementNotFoundException
     * @throws \yii\base\Exception
     */
    public function importElement(array $data, bool $enabled = false, bool $includeAssets = true): bool
    {

        $this->sourceSite = $data['site'];
        $this->includeAssets = $includeAssets;

        $elementType = $data['element']['elementType'] ?? null;

        if ($elementType !== Entry::class) {
            // Only entries supported, for now
            throw new \Exception("Element type {$elementType} not supported");
        }

        $element = null;

        if ($elementType === Entry::class) {
            $element = $this->getEntryModel($data);
        }

        if (!$element) {
            throw new \Exception("Element type {$elementType} not supported");
        }

        $element->enabled = $enabled;

        // Set custom fields and save it again
        $fields = $this->normalizeFieldValues($element, $data['fields'] ?? []);
        $element->setFieldValues($fields);

        return Craft::$app->getElements()->saveElement($element);

    }

    protected function normalizeFieldValues(ElementInterface $element, array $fieldValues, string $context = 'global'): array
    {

        $fields = [];

        foreach ($fieldValues as $fieldHandle => $fieldValue) {

            $field = Craft::$app->getFields()->getFieldByHandle($fieldHandle, $context);
            if (!$field) {
                throw new \Exception("Field \"{$fieldHandle}\" not found for context \"{$context}\"");
            }

            if ($field instanceof Matrix) {

                $fieldValue = $this->normalizeMatrixFieldValue($element, $field, $fieldValue);

            } else if ($field instanceof SuperTableField) {

                $fieldValue = $this->normalizeSuperTableFieldValue($element, $field, $fieldValue);

            } else if ($field instanceof Assets) {

                if (!$this->includeAssets) {
                    $fieldValue = [];
                } else {
                    $fieldValue = $this->normalizeAssetsFieldValue($element, $field, $fieldValue);
                }

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
        return $fieldValue;
    }


    protected function normalizeAssetsFieldValue(ElementInterface $element, Assets $field, $fieldValue): array
    {

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

                // Identify the volume
                $volumeHandle = $data['element']['volume'] ?? null;
                if (!$volumeHandle || !$volume = Craft::$app->getVolumes()->getVolumeByHandle($volumeHandle)) {
                    throw new \Exception("Invalid volume handle \"{$volumeHandle}\"");
                }

                // Check to see if we have this Asset locally already
                $filename = $data['attributes']['filename'];
                $folderPath = $data['attributes']['folderPath'];
                $folderId = $volume->folderExists($folderPath) ? $this->getFolderIdByVolumeIdAndFolderPath($volume->id, $folderPath) : null;

                if ($folderId) {
                    $kind = $data['attributes']['kind'];
                    $localAsset = Asset::find()
                        ->volumeId($volume->id)
                        ->folderId($folderId)
                        ->filename($filename)
                        ->kind($kind)
                        ->one();
                    if ($localAsset) {
                        // Found a local asset. Skip the rest
                        $fieldValue[] = $localAsset->id;
                        continue;
                    }
                }
                
                // Have to download the file!
//                if ($damPluginInstalled && $damId = ($data['element']['damId'] ?? null)) {
//
//                    // Get the metadata from the DAM, with correct language
//                    throw new \Exception($damId);
//
//                }

                // Download the file to a temp path
                $sourceFileUrl = UrlHelper::urlWithParams("{$sourceSiteUrl}/actions/escape-exportimport/files", ['assetId' => $assetId]);
                $tempPath = FileHelper::getTempPath();
                FileHelper::downloadFile($sourceFileUrl, $tempPath);

                if (!\file_exists($tempPath) || !\is_file($tempPath)) {
                    continue;
                }

                // Make sure we have a folder
                if (!$folderId) {
                    $rootFolder = Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id);
                    $folderTree = $data['element']['folders'] ?? [];
                    if (empty($folderTree)) {
                        $folderId = $rootFolder->id;
                    } else {
                        $folderTree = \array_reverse($folderTree);
                        $parentFolder = $rootFolder;
                        foreach ($folderTree as $folderData) {
                            $folderPath = $folderData['path'];
                            $folderName = $folderData['name'];
                            $folder = new VolumeFolder();
                            $folder->volumeId = $volume->id;
                            $folder->parentId = $parentFolder->id;
                            $folder->path = $folderPath;
                            $folder->name = $folderName;
                            Craft::$app->getAssets()->createFolder($folder);
                            $folderId = $folder->id;
                            $parentFolder = $folder;
                        }
                    }
                }

                // Create the Asset
                $asset = $this->getAssetModel($data, $volume);
                $asset->tempFilePath = $tempPath;
                $asset->newFolderId = $folderId;

                // Set custom fields
                $fields = $this->normalizeFieldValues($element, $data['fields'] ?? []);
                $asset->setFieldValues($fields);

                if (Craft::$app->getElements()->saveElement($asset)) {
                    $fieldValue[] = $asset->id;
                }

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
            /** @var SuperTableBlockTypeModel $blockType */
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
     * @return SuperTableBlockTypeModel
     * @throws \Exception
     */
    protected function getSuperTableBlockTypeForField(SuperTableField $field): SuperTableBlockTypeModel
    {
        $blockTypes = $field->getBlockTypes();
        if (empty($blockTypes)) {
            throw new \Exception("Unable to get block type for Super Table field \"{$field->handle}\"");
        }
        /** @var SuperTableBlockTypeModel $blockType */
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
     * @param array $data
     * @return Entry
     * @throws \craft\errors\OperationAbortedException
     */
    protected function getEntryModel(array $data): Entry
    {

        // Get section
        $sectionHandle = $data['element']['section'] ?? null;
        $section = $sectionHandle ? Craft::$app->getSections()->getSectionByHandle($sectionHandle) : null;
        if (!$section) {
            throw new \Exception("Invalid section handle \"{$sectionHandle}\"");
        }

        // Get entry type
        $entryTypeHandle = $data['element']['type'] ?? null;
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

        // Ensure user has neccessary permission
        $user = Craft::$app->getUser()->getIdentity();
        if (!$user->can("createEntries:{$section->uid}")) {
            throw new \Exception("User doesn\'t have the neccessary permissions to create entries in the \"{$sectionHandle}\" section");
        }

        $entry = new Entry();
        $entry->sectionId = $section->id;
        $entry->typeId = $entryType->id;
        $entry->authorId = $user->id;
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
     * @param VolumeInterface $volume
     * @return Asset
     * @throws \Exception
     */
    protected function getAssetModel(array $data, VolumeInterface $volume): Asset
    {

        // Ensure user has neccessary permission
        $user = Craft::$app->getUser()->getIdentity();
        if (!$user->can("saveAssetInVolume:{$volume->uid}")) {
            throw new \Exception("User doesn\'t have the neccessary permissions to upload assets in the \"{$volumeHandle}\" volume");
        }

        $asset = new Asset();
        $asset->volumeId = $volume->id;
        $asset->filename = $data['attributes']['filename'];
        $asset->avoidFilenameConflicts = true;
        $asset->setScenario(Asset::SCENARIO_CREATE);

        return $asset;

    }

}
