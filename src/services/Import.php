<?php

namespace escape\exportimport\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
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
use craft\htmlfield\HtmlField;
use craft\models\CategoryGroup;
use craft\models\EntryType;
use craft\models\Volume;
use craft\validators\HandleValidator;

use escape\escapedam\EscapeDam;
use escape\exportimport\helpers\FileHelper;

use Illuminate\Support\Collection;

/**
 * Class Import
 * @package escape\exportimport\services
 */
class Import extends Component
{

    /** @var array|null */
    protected ?array $sourceSite;

    /** @var User|null */
    protected ?User $author;

    /** @var bool */
    protected bool $includeAssets = true;

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
     * @throws \yii\base\InvalidConfigException
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

        $elementType = $data['element']['elementType'] ?? null;

        if ($elementType === Entry::class) {
            $element = $this->getEntryModel($data, $targetSource);
            $fieldLayoutProvider = $element->getType();
        } else if ($elementType === Category::class) {
            $element = $this->getCategoryModel($data); // TODO support for target source (category group)
            $fieldLayoutProvider = $element->getGroup();
        } else {
            throw new \Exception("Element type $elementType not supported");
        }

        $element->enabled = $enabled;

        // Only get custom fields that exist on the created element
        $customFieldHandles = array_keys($element->getFieldValues());
        $fieldValues = $data['fields'];
        $fieldValuesToUse = array_reduce(array_keys($fieldValues), function (array $carry, string $handle) use ($customFieldHandles, $fieldValues) {
            if (!in_array($handle, $customFieldHandles)) {
                return $carry;
            }
            $carry[$handle] = $fieldValues[$handle];
            return $carry;
        }, []);

        // Set custom fields and save it again
        $element->setFieldValues($this->normalizeFieldValues($fieldLayoutProvider, $fieldValuesToUse, $element));

        return Craft::$app->getElements()->saveElement($element);
    }

    /**
     * @param EntryType|CategoryGroup|Volume $fieldLayoutProvider
     * @param array $fieldValues
     * @param ElementInterface|null $element
     * @return array
     * @throws \Throwable
     * @throws \craft\errors\ElementNotFoundException
     * @throws \craft\errors\SiteNotFoundException
     * @throws \craft\errors\VolumeException
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\db\Exception
     */
    protected function normalizeFieldValues(EntryType|CategoryGroup|Volume $fieldLayoutProvider, array $fieldValues, ?ElementInterface $element = null): array
    {
        $fields = [];
        foreach ($fieldValues as $fieldHandle => $fieldValue) {
            $field = $fieldLayoutProvider->getFieldLayout()->getFieldByHandle($fieldHandle);
            if (empty($field)) {
                continue;
            }
            if ($field instanceof Matrix) {
                $fieldValue = $this->normalizeMatrixFieldValue($field, $fieldValue, $element);
            } else if ($field instanceof Assets) {
                $fieldValue = $this->normalizeAssetsFieldValue($field, $fieldValue, $element);
            } else if ($field instanceof HtmlField) {
                $fieldValue = $this->normalizeHtmlFieldValue($field, $fieldValue, $element);
            } else if ($field instanceof BaseRelationField) {
                $fieldValue = [];
            }

            $fields[$fieldHandle] = $fieldValue;
        }

        return $fields;
    }

    /**
     * @param HtmlField $field
     * @param mixed $fieldValue
     * @param ElementInterface|null $element
     * @return mixed
     */
    protected function normalizeHtmlFieldValue(HtmlField $field, mixed $fieldValue, ?ElementInterface $element = null): mixed
    {
        if ($fieldValue) {
            $fieldValue = $this->stripRefsFromHtmlFieldValue($fieldValue);
            $fieldValue = $field->normalizeValue($fieldValue, $element);
        }

        return $fieldValue;
    }

    /**
     * @param Assets $field
     * @param $fieldValue
     * @param ElementInterface|null $element
     * @return array
     * @throws \Throwable
     * @throws \craft\errors\ElementNotFoundException
     * @throws \craft\errors\SiteNotFoundException
     * @throws \craft\errors\VolumeException
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\db\Exception
     */
    protected function normalizeAssetsFieldValue(Assets $field, $fieldValue, ?ElementInterface $element = null): array
    {
        if (!$this->includeAssets) {
            return [];
        }

        $sourceSite = $this->sourceSite ?? [];
        $sourceSiteUrl = $sourceSite['baseUrl'] ?? null;
        if (!$sourceSiteUrl) {
            throw new \Exception("Unable to download asset; no source site URL");
        }

        $sourceSiteUrl = rtrim($sourceSiteUrl, '/');
        $sourceSiteId = $sourceSite['siteId'] ?? null;
        $assetIds = $fieldValue ?: [];
        $fieldValue = [];

        $damPluginInstalled = !!Craft::$app->getPlugins()->getPlugin('escapedam');

        foreach ($assetIds as $assetId) {
            try {
                $jsonUrl = UrlHelper::urlWithParams("$sourceSiteUrl/actions/escape-exportimport/export", [
                    'elementId' => $assetId,
                    'siteId' => $sourceSiteId,
                ]);

                $json = FileHelper::readFile($jsonUrl);
                $data = $json && Json::isJsonObject($json) ? Json::decode($json) : null;

                if (empty($data)) {
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
                    $asset = EscapeDam::getInstance()->files->importFile(
                        fileId: $damId,
                        fieldId: $field->id,
                        uploaderId: $this->author->id
                    );
                }

                if (empty($asset)) {
                    // Not from DAM, download and create Asset
                    $volumeHandle = $data['element']['volume'] ?? null;
                    /** @var Volume $volume */
                    if (!$volumeHandle || !$volume = Craft::$app->getVolumes()->getVolumeByHandle($volumeHandle)) {
                        throw new \Exception("Invalid volume handle \"$volumeHandle\"");
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
                    $sourceFileUrl = UrlHelper::urlWithParams("$sourceSiteUrl/actions/escape-exportimport/files", ['assetId' => $assetId]);
                    $tempPath = FileHelper::getTempPath();
                    FileHelper::downloadFile($sourceFileUrl, $tempPath);

                    if (!file_exists($tempPath) || !is_file($tempPath)) {
                        throw new \Exception('Unable to download remote file');
                    }

                    // Create the Asset
                    $asset = $this->getAssetModel($data, $volume);
                    $asset->tempFilePath = $tempPath;
                    $asset->newFolderId = $folderId;

                    // Set custom fields
                    $fields = $this->normalizeFieldValues($asset->getVolume(), $data['fields'] ?? []);
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
     * @param Matrix $field
     * @param $fieldValue
     * @param ElementInterface|null $element
     * @return array
     * @throws \Throwable
     * @throws \craft\errors\ElementNotFoundException
     * @throws \craft\errors\SiteNotFoundException
     * @throws \craft\errors\VolumeException
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\db\Exception
     */
    protected function normalizeMatrixFieldValue(Matrix $field, $fieldValue, ?ElementInterface $element = null): array
    {
        return array_map(function (array $nestedEntryData) use ($field, $element) {
            $entryTypeHandle = $nestedEntryData['type'] ?? null;
            $entryType = Collection::make($field->getEntryTypes())
                ->firstWhere('handle', $entryTypeHandle);
            return [
                ...$nestedEntryData,
                'fields' => $this->normalizeFieldValues($entryType, $nestedEntryData['fields'] ?? [], $element),
            ];
        }, array_values($fieldValue ?? []));
    }

    /**
     * @param $fieldValue
     * @return array|string|string[]|null
     */
    protected function stripRefsFromHtmlFieldValue($fieldValue): array|string|null
    {
        if (!StringHelper::contains($fieldValue, '{')) {
            return $fieldValue;
        }

        return preg_replace_callback('/(href=|src=)([\'"])(\{([\w\\\\]+\:\d+(?:@\d+)?\:(?:transform\:)?' . HandleValidator::$handlePattern . ')(?:\|\|[^\}]+)?\})(?:\?([^\'"#]*))?(#[^\'"#]+)?\2/', function($matches) {
            return '';
        }, $fieldValue);
    }

    /**
     * @param array $data
     * @param string|null $targetSource
     * @return Entry
     * @throws \DateMalformedStringException
     * @throws \craft\errors\OperationAbortedException
     */
    protected function getEntryModel(array $data, ?string $targetSource = null): Entry
    {
        if ($targetSource && StringHelper::startsWith($targetSource, 'section:')) {
            // Get section from target source
            $temp = explode(':', $targetSource);
            $targetSourceUid = $temp[1] ?? '';
            if (!StringHelper::isUUID($targetSourceUid) || !$section = Craft::$app->getEntries()->getSectionByUid($targetSourceUid)) {
                throw new \Exception("Invalid target section UUID");
            }
        } else if ($sectionHandle = $data['element']['section'] ?? null) {
            $section = Craft::$app->getEntries()->getSectionByHandle($sectionHandle);
        }

        if (!$section) {
            throw new \Exception('Target section not found');
        }

        // Get entry type
        $entryTypeHandle = $data['element']['type'];
        $entryType = Collection::make($section->getEntryTypes())
            ->firstWhere('handle', $entryTypeHandle) ?? $section->getEntryTypes()[0];

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
