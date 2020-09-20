<?php

namespace escape\exportimport\elements\actions;

use Craft;
use craft\helpers\Json;

/**
 * Class CopyExportElementsUrl
 * @package escape\exportimport\elements\actions
 */
class CopyExportElementsUrl extends \craft\base\ElementAction
{

    /**
     * @inheritdoc
     */
    public function getTriggerLabel(): string
    {
        return "Copy URL for import";
    }

    /**
     * @inheritdoc
     */
    public function getTriggerHtml()
    {
        $type = Json::encode(static::class);

        $js = <<<JS
(function()
{
    var trigger = new Craft.ElementActionTrigger({
        type: {$type},
        batch: true,
        activate: function(\$selectedItems)
        {
            var siteId = \$selectedItems.find('[data-site-id]').data('site-id');
            var elementIds = [];
            \$selectedItems.each(function () {
                elementIds.push($(this).data('id'));
            });
            var url = Craft.getActionUrl('escape-exportimport/export', {
                siteId: siteId,
                elementId: elementIds
            });
            Craft.ui.createCopyTextPrompt({
                label: Craft.t('app', 'Copy the URL'),
                value: url
            });
        }
    });
})();
JS;

        Craft::$app->getView()->registerJs($js);
    }

}
