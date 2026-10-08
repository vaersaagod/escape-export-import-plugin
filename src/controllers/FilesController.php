<?php

namespace escape\exportimport\controllers;

use Craft;
use craft\elements\Asset;
use craft\errors\FsException;

use escape\exportimport\ExportImport;

use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Class FilesController
 * @package escape\exportimport\controllers
 */
class FilesController extends \craft\web\Controller
{

    /**
     * Anonymous, so other sites can download files; they're authorized by the token instead
     *
     * @var string[]
     */
    public array|int|bool $allowAnonymous = ['index'];

    /**
     * Send an asset's file, streamed from its volume
     *
     * @return Response
     * @throws ForbiddenHttpException
     * @throws NotFoundHttpException
     * @throws \craft\errors\SiteNotFoundException
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\web\BadRequestHttpException
     * @throws \yii\web\RangeNotSatisfiableHttpException
     */
    public function actionIndex(): Response
    {

        $export = ExportImport::getInstance()->export;

        if (!$export->getCanRequestExport($this->request, Craft::$app->getUser()->getIdentity())) {
            throw new ForbiddenHttpException('Not authorized to export');
        }

        $assetId = (int)$this->request->getRequiredParam('assetId');
        $asset = $assetId ? $export->getExportableElementById($assetId) : null;

        if (!$asset instanceof Asset) {
            throw new NotFoundHttpException('Asset not found');
        }

        try {
            $stream = $asset->getStream();
        } catch (FsException $e) {
            Craft::error($e->getMessage(), __METHOD__);
            throw new NotFoundHttpException('File not found');
        }

        return $this->response->sendStreamAsFile($stream, $asset->getFilename(), [
            'fileSize' => $asset->size,
            'mimeType' => $asset->getMimeType(),
        ]);
    }

}
