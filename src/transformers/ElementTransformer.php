<?php

namespace escape\exportimport\transformers;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;

use craft\helpers\App;
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
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
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
            'baseUrl' => App::parseEnv($site->baseUrl),
            'handle' => App::env('ESCAPE_SITE_HANDLE') ?? '',
            'siteId' => (int)$site->id,
            'language' => $site->language,
        ];

        // Add additional info about the element
        $elementData = [
            'elementType' => get_class($element),
        ];

        if ($element instanceof Entry) {
            $elementData['section'] = $element->getSection()->handle;
            $elementData['type'] = Craft::$app->getSections()->getEntryTypeById($attributes['typeId'])->handle;
        } else if ($element instanceof Asset) {
            $volume = $element->getVolume();
            $elementData['volume'] = $volume->handle;
            // Was this element originally imported from the Escape DAM?
            if (!!Craft::$app->getPlugins()->getPlugin('escapedam')) {
                $damAsset = EscapeDam::getInstance()->files->getFileForImportedAsset($element);
                $elementData['damData'] = $damAsset;
            }
        } else if ($element instanceof Category) {
            $categoryGroup = $element->getGroup();
            $elementData['group'] = $categoryGroup->handle;
        }

        return [
            'site' => $siteInfo,
            'element' => $elementData,
            'attributes' => $attributes,
            'fields' => $fields,
        ];
    }

}
