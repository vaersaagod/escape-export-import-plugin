<?php

namespace escape\exportimport\transformers;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\Entry;

use escape\escapedam\EscapeDam;

use League\Fractal\TransformerAbstract;

/**
 * Class ElementTransformer
 * @package escape\exportimport\transformers
 */
class ElementTransformer extends TransformerAbstract
{

    /**
     * @param ElementInterface $element
     * @return array
     */
    public function transform(ElementInterface $element): array
    {
        // Get the serialized custom field values
        /** @var Element $element */
        $fields = $element->getSerializedFieldValues();

        // Get the element attributes that aren't custom fields
        $attributes = $element->toArray(\array_diff($element->attributes(), array_keys($fields)));

        // Add additional info about the site
        $site = Craft::$app->getSites()->getSiteById($attributes['siteId']);
        $siteInfo = [
            'baseUrl' => Craft::parseEnv($site->baseUrl),
            'handle' => \getenv('ESCAPE_SITE_HANDLE') ?: '',
            'siteId' => (int)$site->id,
            'language' => $site->language,
        ];

        // Add additional info about the element
        $elementData = [
            'elementType' => \get_class($element),
        ];

        if ($element instanceof Entry) {
            $elementData['section'] = $element->getSection()->handle;
            $elementData['type'] = Craft::$app->getSections()->getEntryTypeById($attributes['typeId'])->handle;
        } else if ($element instanceof Asset) {
            $volume = $element->getVolume();
            $elementData['volume'] = $volume->handle;
            $folder = $element->getFolder();
            $rootFolder = Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id);
            if ($folder->id !== $rootFolder->id) {
                $folderTree = [[
                    'path' => $folder->path,
                    'name' => $folder->name,
                ]];
                while ($folder->parentId) {
                    $folder = $folder->getParent();
                    if ($folder && $folder->id !== $rootFolder->id) {
                        $folderTree[] = [
                            'path' => $folder->path,
                            'name' => $folder->name,
                        ];
                    }
                }
                $elementData['folders'] = \array_reverse($folderTree);
            } else {
                $elementData['folders'] = [];
            }
            // Was this element originally imported from the Escape DAM?
            if (!!Craft::$app->getPlugins()->getPlugin('escapedam')) {
                $damAsset = EscapeDam::getInstance()->files->getFileForImportedAsset($element);
                $elementData['damId'] = $damAsset['id'];
            }
        }

        return [
            'site' => $siteInfo,
            'element' => $elementData,
            'attributes' => $attributes,
            'fields' => $fields,
        ];
    }

}
