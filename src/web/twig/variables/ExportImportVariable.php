<?php

namespace escape\exportimport\web\twig\variables;

use craft\base\ElementInterface;
use escape\exportimport\ExportImport;

/**
 * Class ExportImportVariable
 * @package escape\exportimport\web\twig\variables
 */
class ExportImportVariable
{

    /**
     * @param ElementInterface $element
     * @return bool
     * @throws \yii\base\InvalidConfigException
     */
    public function isElementExportable(ElementInterface $element): bool
    {
        return ExportImport::getInstance()->export->getIsElementExportable($element);
    }
    
}
