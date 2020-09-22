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
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Plugins;
use craft\services\UserPermissions;
use craft\web\twig\variables\Cp;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;

//use escape\exportimport\elements\actions\DownloadExportElementsJson;
use escape\exportimport\elements\actions\CopyExportElementsUrl;
use escape\exportimport\models\Settings;
use escape\exportimport\services\Export;
use escape\exportimport\services\Import;

use escape\exportimport\web\twig\variables\ExportImportVariable;
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

        parent::init();

        // Define a custom alias named after the namespace
        Craft::setAlias('@modules', dirname(__DIR__));
        Craft::setAlias('@exportimport', __DIR__);

        // Set the controllerNamespace based on whether this is a console or web request
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'escape\\exportimport\\console\\controllers';
        } else {
            $this->controllerNamespace = 'escape\\exportimport\\controllers';
        }

        Craft::$app->getView()->hook('cp.entries.edit.meta', function (array $context) {
            return Craft::$app->getView()->renderTemplate('escape-exportimport/_components/hooks/element-edit-meta', $context);
        });

        // Register services
        $this->setComponents([
            'export' => Export::class,
            'import' => Import::class,
        ]);

        // Register template variables
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function (Event $event) {
                $variable = $event->sender;
                $variable->set('escapeExportImport', ExportImportVariable::class);
            }
        );

        // Register element actions
        Event::on(
            Entry::class,
            Element::EVENT_REGISTER_ACTIONS,
            function (RegisterElementActionsEvent $event) {
                $source = $event->source;
                if (ExportImport::getInstance()->export->getIsSourceExportable($source)) {
                    $event->actions[] = new CopyExportElementsUrl();
                    //$event->actions[] = new DownloadExportElementsJson();
                }
            }
        );

        // Register CP permissions
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function (RegisterUserPermissionsEvent $event) {
                $event->permissions['Escape Export & Import'] = $this->getCpPermissions();
            }
        );

        // Register CP route and nav item
        // We wrap this in a Plugins::EVENT_AFTER_LOAD_PLUGINS event handler because we need to check the user's permissions for this
        Event::on(
            Plugins::class,
            Plugins::EVENT_AFTER_LOAD_PLUGINS,
            function () {
                $currentUser = Craft::$app->getUser()->getIdentity();
                if (!$currentUser || !$currentUser->can('escape-exportimport:import')) {
                    return;
                }
                Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function (RegisterUrlRulesEvent $event) {
                    $event->rules['import/<actionSlug:{slug}>'] = ['template' => 'escape-exportimport/index'];
                });

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
            }
        );

    }

    // Protected Methods
    // =========================================================================
    /**
     * Returns the custom Control Panel user permissions.
     *
     * @return array
     */
    protected function getCpPermissions(): array
    {
        return [
            'escape-exportimport:import' => [
                'label' => 'Import elements',
            ],
        ];
    }

    /**
     * @return Settings
     */
    protected function createSettingsModel()
    {
        return new Settings();
    }

}
