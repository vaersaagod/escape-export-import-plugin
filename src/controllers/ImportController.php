<?php

namespace escape\exportimport\controllers;

use Craft;
use craft\helpers\Json;
use craft\web\UploadedFile;

use escape\exportimport\ExportImport;
use escape\exportimport\helpers\FileHelper;
use escape\exportimport\models\Settings;
use escape\exportimport\queue\jobs\ImportElementsJob;

use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * Class ImportController
 * @package escape\exportimport\controllers
 */
class ImportController extends \craft\web\Controller
{

    /** @var string */
    const PERMISSION_IMPORT = 'escape-exportimport:import';

    /**
     * @return Response|null
     * @throws BadRequestHttpException
     * @throws \craft\errors\MissingComponentException
     * @throws \yii\web\ForbiddenHttpException
     */
    public function actionIndex()
    {

        $this->requirePostRequest();

        $this->requirePermission(self::PERMISSION_IMPORT);

        $enableElements = !!$this->request->getRequiredBodyParam('enableElements');
        $includeAssets = !!$this->request->getRequiredBodyParam('includeAssets');
        $elementsJsonUrl = $this->request->getBodyParam('elementsJsonUrl', null);
        $targetSectionId = ((int)$this->request->getBodyParam('targetSectionId', 0)) ?: null;

        if ($elementsJsonUrl !== null) {
            /** @var Settings $settings */
            $settings = ExportImport::getInstance()->getSettings();
            if (!$settings->getIsSourceUrlAllowed($elementsJsonUrl)) {
                return $this->_failImport('The URL has to be an HTTPS URL on one of the allowed source hosts', $enableElements, $includeAssets);
            }

            try {
                $elementsJson = FileHelper::readFile($elementsJsonUrl);
            } catch (\Throwable $e) {
                Craft::error($e->getMessage(), __METHOD__);
                return $this->_failImport('Unable to fetch the URL', $enableElements, $includeAssets);
            }

        } else {
            $elementsJsonUpload = UploadedFile::getInstanceByName('elementsJsonFile');

            if (!$elementsJsonUpload) {
                return $this->_failImport('No data to import', $enableElements, $includeAssets);
            }

            $elementsJson = \file_get_contents($elementsJsonUpload->tempName);
        }

        if (!$elementsJson || !Json::isJsonObject($elementsJson)) {
            return $this->_failImport('No valid JSON data found', $enableElements, $includeAssets);
        }

        $elementsData = Json::decode($elementsJson);

        if (!\is_array($elementsData) || empty($elementsData)) {
            return $this->_failImport('Unable to decode JSON data', $enableElements, $includeAssets);
        }

        $elementsToImport = [];
        foreach ($elementsData as $elementToMaybeImport) {
            if (!is_array($elementToMaybeImport) || empty($elementToMaybeImport) || !!\array_diff(['site', 'element', 'attributes', 'fields'], \array_keys($elementToMaybeImport))) {
                return $this->_failImport('Invalid element data found in JSON', $enableElements, $includeAssets);
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

    /**
     * Show an error and send the form back with its settings
     *
     * @param string $message
     * @param bool $enableElements
     * @param bool $includeAssets
     * @return null
     * @throws \craft\errors\MissingComponentException
     */
    private function _failImport(string $message, bool $enableElements, bool $includeAssets)
    {
        Craft::$app->getSession()->setError($message);
        Craft::$app->getUrlManager()->setRouteParams([
            'enableElements' => $enableElements,
            'includeAssets' => $includeAssets,
        ]);
        return null;
    }

}
