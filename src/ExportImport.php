<?php

namespace escape\exportimport;

use Craft;

use craft\base\Element;
use craft\base\Plugin;
use craft\elements\Category;
use craft\elements\Entry;
use craft\events\DefineHtmlEvent;
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

use escape\exportimport\controllers\ImportController;
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

    /** @var string The request header sites send the token in */
    const TOKEN_HEADER = 'X-Escape-ExportImport-Token';

    /**
     * @inheritdoc
     */
    public function init()
    {

        parent::init();

        // Define a custom alias named after the namespace
        Craft::setAlias('@exportimport', __DIR__);

        // Set the controllerNamespace based on whether this is a console or web request
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'escape\\exportimport\\console\\controllers';
        } else {
            $this->controllerNamespace = 'escape\\exportimport\\controllers';
        }

        // Register services
        $this->setComponents([
            'export' => Export::class,
            'import' => Import::class,
        ]);

        // Defer most setup tasks until Craft is fully initialized
        Craft::$app->onInit(function () {
            $this->attachEventHandlers();
            // ...
        });

    }

    // Protected Methods
    // =========================================================================

    protected function attachEventHandlers(): void
    {
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

        Event::on(
            Category::class,
            Element::EVENT_REGISTER_ACTIONS,
            function (RegisterElementActionsEvent $event) {
                $source = $event->source;
                if (ExportImport::getInstance()->export->getIsSourceExportable($source)) {
                    $event->actions[] = new CopyExportElementsUrl();
                    //$event->actions[] = new DownloadExportElementsJson();
                }
            }
        );

        Event::on(
            Entry::class,
            Element::EVENT_DEFINE_META_FIELDS_HTML,
            static function (DefineHtmlEvent $event) {
                if ($event->static) {
                    return;
                }
                /** @var Entry $element */
                $entry = $event->sender;
                $event->html .= Craft::$app->getView()->renderTemplate('escape-exportimport/_components/hooks/element-edit-meta', [
                    'entry' => $entry,
                ]);
            }
        );

        Event::on(
            Category::class,
            Element::EVENT_DEFINE_META_FIELDS_HTML,
            static function (DefineHtmlEvent $event) {
                if ($event->static) {
                    return;
                }
                /** @var Category $element */
                $category = $event->sender;
                $event->html .= Craft::$app->getView()->renderTemplate('escape-exportimport/_components/hooks/element-edit-meta', [
                    'entry' => $category,
                ]);
            }
        );

//        Craft::$app->getView()->hook('cp.entries.edit.meta', function (array $context) {
//            return Craft::$app->getView()->renderTemplate('escape-exportimport/_components/hooks/element-edit-meta', $context);
//        });
//        Craft::$app->getView()->hook('cp.categories.edit.details', function (array $context) {
//            return Craft::$app->getView()->renderTemplate('escape-exportimport/_components/hooks/element-edit-meta', $context);
//        });

        // Register CP permissions
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function (RegisterUserPermissionsEvent $event) {
                $event->permissions[] = $this->getCpPermissions();
            }
        );

        // Register CP route and nav item
        $currentUser = Craft::$app->getUser()->getIdentity();
        if (!$currentUser || !$currentUser->can(ImportController::PERMISSION_IMPORT)) {
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

    /**
     * Returns the custom Control Panel user permissions.
     *
     * @return array
     */
    protected function getCpPermissions(): array
    {
        return [
            'heading' => 'Escape Export & Import',
            'permissions' => [
                ImportController::PERMISSION_IMPORT => [
                    'label' => 'Import elements',
                ]
            ],
        ];
    }

    /**
     * @return Settings
     */
    protected function createSettingsModel(): ?\craft\base\Model
    {
        return new Settings();
    }

}
