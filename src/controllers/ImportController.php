<?php

namespace escape\exportimport\controllers;

use Craft;
use craft\helpers\Json;
use craft\web\UploadedFile;

use escape\exportimport\helpers\FileHelper;
use escape\exportimport\queue\jobs\ImportElementsJob;

use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * Class ImportController
 * @package escape\exportimport\controllers
 */
class ImportController extends \craft\web\Controller
{

    /**
     * @return Response|null
     * @throws BadRequestHttpException
     * @throws \craft\errors\MissingComponentException
     * @throws \yii\web\ForbiddenHttpException
     */
    public function actionIndex()
    {

        $this->requirePostRequest();

        $this->requirePermission('escape-exportimport:import');

        $enableElements = !!$this->request->getRequiredBodyParam('enableElements');
        $includeAssets = !!$this->request->getRequiredBodyParam('includeAssets');
        $elementsJsonUrl = $this->request->getBodyParam('elementsJsonUrl', null);
        $targetSectionId = ((int)$this->request->getBodyParam('targetSectionId', 0)) ?: null;

        if ($elementsJsonUrl !== null) {
            if (!\filter_var($elementsJsonUrl, FILTER_VALIDATE_URL)) {
                Craft::$app->getSession()->setError('Invalid URL');
                Craft::$app->getUrlManager()->setRouteParams([
                    'enableElements' => $enableElements,
                    'includeAssets' => $includeAssets,
                ]);
                return null;
            }

            $elementsJson = FileHelper::readFile($elementsJsonUrl);

        } else {
            $elementsJsonUpload = UploadedFile::getInstanceByName('elementsJsonFile');

            if (!$elementsJsonUpload) {
                Craft::$app->getSession()->setError('No data to import');
                Craft::$app->getUrlManager()->setRouteParams([
                    'enableElements' => $enableElements,
                    'includeAssets' => $includeAssets,
                ]);
                return null;
            }

            $elementsJson = FileHelper::readFile($elementsJsonUpload->tempName);
        }

        if (!$elementsJson || !Json::isJsonObject($elementsJson)) {
            Craft::$app->getSession()->setError('No valid JSON data found');
            Craft::$app->getUrlManager()->setRouteParams([
                'enableElements' => $enableElements,
                'includeAssets' => $includeAssets,
            ]);
            return null;
        }

        $elementsData = Json::decode($elementsJson);

        if (!\is_array($elementsData) || empty($elementsData)) {
            Craft::$app->getSession()->setError('Unable to decode JSON data');
            Craft::$app->getUrlManager()->setRouteParams([
                'enableElements' => $enableElements,
                'includeAssets' => $includeAssets,
            ]);
            return null;
        }

        $elementsToImport = [];
        foreach ($elementsData as $elementToMaybeImport) {
            if (!is_array($elementToMaybeImport) || empty($elementToMaybeImport) || !!\array_diff(['site', 'element', 'attributes', 'fields'], \array_keys($elementToMaybeImport))) {
                Craft::$app->getSession()->setError('Invalid element data found in JSON');
                Craft::$app->getUrlManager()->setRouteParams([
                    'enableElements' => $enableElements,
                    'includeAssets' => $includeAssets,
                ]);
                return null;
            }
            $elementsToImport[] = $elementToMaybeImport;
        }

        $authorId = (int)Craft::$app->getUser()->getIdentity()->id;

        Craft::$app->getQueue()->push(new ImportElementsJob([
            'authorId' => $authorId,
            'enableElements' => $enableElements,
            'data' => $elementsToImport,
            'includeAssets' => $includeAssets,
            'targetSectionId' => $targetSectionId,
        ]));

        $numElementsToImport = \count($elementsToImport);
        Craft::$app->getSession()->setNotice("Importing {$numElementsToImport} elements...");

        return $this->redirectToPostedUrl();
    }

}
