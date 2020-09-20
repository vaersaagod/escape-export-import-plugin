<?php

namespace escape\exportimport\helpers;

use Craft;
use craft\helpers\Assets;

/**
 * Class FileHelper
 * @package escape\exportimport\helpers
 */
class FileHelper
{

    /**
     * @return string
     * @throws \yii\base\Exception
     */
    public static function getTempPath(): string
    {
        return Assets::tempFilePath();
    }

    /**
     * Read an external file and return its contents
     *
     * @param string $fileUrl
     * @return string|null
     */
    public static function readFile(string $fileUrl)
    {
        if (\function_exists('curl_init')) {

            $ch = curl_init();

            curl_setopt($ch, CURLOPT_AUTOREFERER, TRUE);
            curl_setopt($ch, CURLOPT_HEADER, 0);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_URL, $fileUrl);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);

            $data = curl_exec($ch);
            curl_close($ch);

            return $data;

        } elseif (\ini_get('allow_url_fopen')) {

            return \file_get_contents($fileUrl);

        }

        throw new \Exception('Looks like allow_url_fopen is off and cURL is not enabled. To read external files, one of these methods must be enabled.');
    }

    /**
     * Download an external file to the specified file path
     *
     * @param string $fileUrl
     * @param string $filePath
     * @return bool
     * @throws \Exception
     */
    public static function downloadFile(string $fileUrl, string $filePath): bool
    {

        $httpStatus = null;
        $errorMessage = null;

        if (\function_exists('curl_init')) {

            $ch = \curl_init($fileUrl);
            $fp = \fopen($filePath, "wb");

            $options = [
                CURLOPT_FILE => $fp,
                CURLOPT_HEADER => 0,
                CURLOPT_FOLLOWLOCATION => 1,
                CURLOPT_TIMEOUT => 60,
            ];

            \curl_setopt_array($ch, $options);
            \curl_exec($ch);

            if (\curl_errno($ch)) {
                $errorMessage = \curl_error($ch);
            }

            $httpStatus = \intval(\curl_getinfo($ch, CURLINFO_HTTP_CODE));

            \curl_close($ch);
            \fclose($fp);

        } elseif (\ini_get('allow_url_fopen')) {

            \file_put_contents($filePath, \file_get_contents($fileUrl));
            $httpStatus = $http_response_header[0] ?? null;
        } else {

            throw new \Exception('Looks like allow_url_fopen is off and cURL is not enabled. To download external files, one of these methods must be enabled.');
        }

        if ($errorMessage) {
            throw new \Exception(Craft::t('site', 'An error “{errorMessage}” occurred while attempting to download “{fileUrl}”', [
                'fileUrl' => $fileUrl,
                'errorMessage' => $errorMessage
            ]));
        }

        if ($httpStatus !== 200) {
            throw new \Exception(Craft::t('site', 'HTTP status “{httpStatus}” encountered while attempting to download “{fileUrl}”', [
                'fileUrl' => $fileUrl,
                'httpStatus' => $httpStatus
            ]));
        }

        return true;
    }
}
