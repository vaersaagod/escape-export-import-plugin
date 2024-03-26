<?php


namespace escape\exportimport\controllers;

use Craft;
use craft\elements\Asset;
use craft\helpers\App;
use craft\helpers\FileHelper;

use craft\models\Volume;
use yii\web\BadRequestHttpException;
use yii\web\ServerErrorHttpException;

/**
 * Class DownloadController
 * @package escape\exportimport\controllers
 */
class FilesController extends \craft\web\Controller
{

    /** @var string[] */
    public array|int|bool $allowAnonymous = ['index'];

    /**
     * @return bool
     * @throws BadRequestHttpException
     * @throws ServerErrorHttpException
     * @throws \yii\base\InvalidConfigException
     */
    public function actionIndex()
    {

        $assetId = (int)Craft::$app->getRequest()->getRequiredParam('assetId');

        if (!$assetId || !$asset = Asset::find()->id($assetId)->anyStatus()->one()) {
            throw new BadRequestHttpException('Invalid asset ID');
        }

        $filePath = $this->getFilePath($asset);

        if (!\file_exists($filePath) || !\is_file($filePath)) {
            throw new ServerErrorHttpException("File \"{$filePath}\" does not exist or is not a file");
        }

        $fileSize = \filesize($filePath);
        $mimeType = FileHelper::getMimeType($filePath);

        \header("Content-Type: {$mimeType}");
        \header("Content-Length: {$fileSize}");

        $fp = \fopen($filePath, 'rb');
        \fpassthru($fp);

        return true;
    }

    /**
     * @param Asset $asset
     * @return string
     * @throws \yii\base\InvalidConfigException
     */
    protected function getFilePath(Asset $asset): string
    {
        if (!$asset->getVolume() instanceof Local) {
            return $asset->getCopyOfFile();
        } else {
            /** @var Volume $volume */
            $volume = $asset->getVolume();
            $volumePath = \rtrim($volume->settings['path'], '/') . '/';
            $folderPath = \rtrim($asset->getFolder()->path, '/') . '/';
            return App::parseEnv($this->fixSlashes($volumePath . $folderPath . $asset->filename));
        }
    }

    /**
     * @param string $str
     * @return string
     */
    protected function fixSlashes(string $str): string
    {
        return \preg_replace('~(^|[^:])//+~', '\\1/', $str);
    }

}
