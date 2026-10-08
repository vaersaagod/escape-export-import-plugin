<?php

namespace escape\exportimport\controllers;

use Craft;
use craft\base\Element;
use craft\helpers\App;
use craft\helpers\Json;

use escape\exportimport\ExportImport;
use escape\exportimport\transformers\ElementTransformer;

use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Class ExportController
 * @package escape\exportimport\controllers
 */
class ExportController extends \craft\web\Controller
{

    /** @var int The most elements one request can export */
    const MAX_ELEMENTS = 100;

    /**
     * Anonymous, so other sites can fetch exports; they're authorized by the token instead
     *
     * @var string[]
     */
    public array|int|bool $allowAnonymous = ['index'];

    /**
     * Export one or multiple elements to a JSON response or a file
     *
     * @return \craft\web\Response
     * @throws BadRequestHttpException
     * @throws ForbiddenHttpException
     * @throws NotFoundHttpException
     * @throws \craft\errors\SiteNotFoundException
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\web\HttpException
     * @throws \yii\web\RangeNotSatisfiableHttpException
     */
    public function actionIndex(): Response
    {

        $export = ExportImport::getInstance()->export;

        if (!$export->getCanRequestExport($this->request, Craft::$app->getUser()->getIdentity())) {
            throw new ForbiddenHttpException('Not authorized to export');
        }

        $siteId = (int)$this->request->getParam('siteId', Craft::$app->getSites()->getCurrentSite()->id);
        $site = $siteId ? Craft::$app->getSites()->getSiteById($siteId) : null;

        if (!$site) {
            throw new BadRequestHttpException('Invalid site ID');
        }

        // Element IDs come comma-separated in a URL, or as an array from a form
        $elementIds = $this->request->getRequiredParam('elementId');
        if (!\is_array($elementIds)) {
            $elementIds = \explode(',', (string)$elementIds);
        }
        $elementIds = \array_values(\array_unique(\array_filter(\array_map('intval', $elementIds))));

        if (empty($elementIds)) {
            throw new BadRequestHttpException('Invalid element ID');
        }

        if (\count($elementIds) > self::MAX_ELEMENTS) {
            throw new BadRequestHttpException('Too many elements; export at most ' . self::MAX_ELEMENTS . ' at a time');
        }

        $elements = [];
        foreach ($elementIds as $elementId) {
            $element = $export->getExportableElementById($elementId, $siteId);
            if (!$element) {
                throw new NotFoundHttpException("Element ID $elementId not found");
            }
            $elements[] = $element;
        }

        $data = \array_map(static fn($element) => new ElementTransformer()->transform($element), $elements);

        if (!$this->request->getParam('asFile')) {
            return $this->asJson($data);
        }

        $json = Json::encode($data);

        $fileName = ['export', App::parseEnv($site->baseUrl)];
        if (\count($elements) === 1) {
            /** @var Element $element */
            $element = $elements[0];
            if ($element->slug) {
                $fileName[] = $element->slug;
            }
        } else {
            $fileName[] = $this->request->getPathInfo();
        }

        $fileName[] = time();

        $fileName = \implode('-', \array_filter($fileName)) . '.json';

        return $this->response
            ->sendContentAsFile($json, $fileName, [
                'mimeType' => 'text/plain',
            ]);
    }

}
