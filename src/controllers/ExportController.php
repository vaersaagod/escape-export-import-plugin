<?php

namespace escape\exportimport\controllers;

use Craft;
use craft\helpers\Json;

use escape\exportimport\ExportImport;

use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * Class ExportController
 * @package escape\exportimport\controllers
 */
class ExportController extends \craft\web\Controller
{

    /** @var string[] */
    public $allowAnonymous = ['index'];

    /**
     * Export one or multiple elements to a JSON response or a file
     *
     * @return \craft\web\Response
     * @throws BadRequestHttpException
     * @throws \craft\errors\SiteNotFoundException
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\web\HttpException
     * @throws \yii\web\RangeNotSatisfiableHttpException
     */
    public function actionIndex(): Response
    {

        $siteId = Craft::$app->getRequest()->getParam('siteId', Craft::$app->getSites()->getCurrentSite()->id);
        $site = $siteId ? Craft::$app->getSites()->getSiteById($siteId) : null;

        if (!$site) {
            throw new BadRequestHttpException('Invalid site ID');
        }

        $elementIds = \explode(',', $this->request->getRequiredParam('elementId'));

        $data = [];

        foreach ($elementIds as $elementId) {
            $data[] = ExportImport::getInstance()->export->getElementDataById($elementId, $siteId);
        }

        if (!Craft::$app->getRequest()->getParam('asFile')) {
            return $this->asJson($data);
        }

        $json = Json::encode($data);

        $fileName = ['export', Craft::parseEnv($site->baseUrl)];
        if (\count($elementIds) === 1) {
            /** @var Element $element */
            $element = Craft::$app->getElements()->getElementById($elementIds[0], null, $siteId);
            if ($element && $element->slug) {
                $fileName[] = $element->slug;
            }
        } else {
            $fileName[] = Craft::$app->getRequest()->getPathInfo();
        }

        $fileName[] = time();

        $fileName = \implode('-', \array_filter($fileName)) . '.json';

        return $this->response
            ->sendContentAsFile($json, $fileName, [
                'mimeType' => 'text/plain',
            ]);
    }

}
