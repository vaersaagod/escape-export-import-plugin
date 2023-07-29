<?php

namespace escape\exportimport\elements\actions;

use Craft;
use craft\helpers\Json;

/**
 * Class DownloadExportElementsJson
 * @package escape\exportimport\elements\actions
 */
class DownloadExportElementsJson extends \craft\base\ElementAction
{
    /**
     * @inheritdoc
     */
    public function getTriggerLabel(): string
    {
        return "Download file for import";
    }

    /**
     * @inheritdoc
     */
    public function getTriggerHtml(): ?string
    {
        $type = Json::encode(static::class);

        $js = <<<JS
(function()
{
    var trigger = new Craft.ElementActionTrigger({
        type: {$type},
        activate: function(\$selectedItems)
        {
            var \$form = Craft.createForm().appendTo(Garnish.\$bod);
            $(Craft.getCsrfInput()).appendTo(\$form);
            $('<input/>', {
                type: 'hidden',
                name: 'action',
                value: 'escape-exportimport/export'
            }).appendTo(\$form);
            \$selectedItems.each(function() {
                $('<input/>', {
                    type: 'hidden',
                    name: 'elementId[]',
                    value: $(this).data('id')
                }).appendTo(\$form);
            });
            $('<input/>', {
                type: 'submit',
                value: 'Submit',
            }).appendTo(\$form);
            $('<input/>', {
                type: 'hidden',
                name: 'asFile',
                value: '1',
            }).appendTo(\$form);
            \$form.submit();
            \$form.remove();
        }
    });
})();
JS;

        $request = Craft::$app->getRequest();
        $js = str_replace([
            '{csrfName}',
            '{csrfValue}'
        ], [
            $request->csrfParam,
            $request->getCsrfToken()
        ], $js);

        Craft::$app->getView()->registerJs($js);

        return null;
    }
}
