<?php

namespace escape\exportimport\services;

use Craft;

use escape\exportimport\transformers\ElementTransformer;

/**
 * Class Export
 * @package escape\exportimport\services
 */
class Export extends \craft\base\Component
{

    /**
     * @param int $elementId
     * @param int|null $siteId
     * @return array
     * @throws \craft\errors\SiteNotFoundException
     */
    public function getElementDataById(int $elementId, int $siteId = null)
    {

        if (!$siteId) {
            $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        }

        $element = Craft::$app->getElements()->getElementById($elementId, null, $siteId);

        if (!$element) {
            throw new \Exception('Invalid element ID');
        }

        return (new ElementTransformer())->transform($element);
    }

}
