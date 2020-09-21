<?php

namespace escape\exportimport;

use Craft;

use craft\base\Element;
use craft\base\Plugin;
use craft\elements\Entry;
use craft\events\RegisterCpNavItemsEvent;
use craft\events\RegisterElementActionsEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\web\twig\variables\Cp;
use craft\web\UrlManager;
use craft\web\View;

use escape\exportimport\elements\actions\DownloadExportElementsJson;
use escape\exportimport\elements\actions\CopyExportElementsUrl;
use escape\exportimport\services\Export;
use escape\exportimport\services\Import;

use yii\base\Event;
use yii\base\Module;

/**
 * Class ExportImportModule
 * @package escape\exportimport
 * @property Export $export
 * @property Import $import
 */
class ExportImport extends Plugin
{

    /**
     * @inheritdoc
     */
    public function init()
    {

        // Define a custom alias named after the namespace
        Craft::setAlias('@modules', dirname(__DIR__));
        Craft::setAlias('@exportimport', __DIR__);

        // Set the controllerNamespace based on whether this is a console or web request
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'escape\\exportimport\\console\\controllers';
        } else {
            $this->controllerNamespace = 'escape\\exportimport\\controllers';
        }

        parent::init();

        Event::on(
            Cp::class,
            Cp::EVENT_REGISTER_CP_NAV_ITEMS,
            function(RegisterCpNavItemsEvent $event) {
                $event->navItems[] = [
                    'url' => 'import/url',
                    'label' => Craft::t('site', 'Import'),
                    'icon' => '@exportimport/icon.svg',
                ];
            }
        );

        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function (RegisterUrlRulesEvent $event) {
            $event->rules['import/<actionSlug:{slug}>'] = ['template' => 'escape-exportimport/index'];
        });

        Craft::$app->getView()->hook('cp.entries.edit.meta', function (array $context) {
            return Craft::$app->getView()->renderTemplate('escape-exportimport/_components/hooks/element-edit-meta', $context);
        });

        // Register services
        $this->setComponents([
            'export' => Export::class,
            'import' => Import::class,
        ]);

        Event::on(
            Entry::class,
            Element::EVENT_REGISTER_ACTIONS,
            function (RegisterElementActionsEvent $event) {
                $event->actions[] = new CopyExportElementsUrl();
                $event->actions[] = new DownloadExportElementsJson();
            }
        );
    }

}
