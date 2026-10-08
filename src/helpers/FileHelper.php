<?php

namespace escape\exportimport\helpers;

use Craft;
use craft\helpers\Assets;
use escape\exportimport\ExportImport;
use escape\exportimport\models\Settings;
use GuzzleHttp\Client;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

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
     * Read a file on one of the source hosts and return its contents
     *
     * @param string $fileUrl
     * @return string
     * @throws \Exception if the URL isn't on one of the source hosts
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public static function readFile(string $fileUrl): string
    {
        return (string)self::_createClient($fileUrl)->get($fileUrl)->getBody();
    }

    /**
     * Download a file on one of the source hosts to the specified file path
     *
     * @param string $fileUrl
     * @param string $filePath
     * @return bool
     * @throws \Exception if the URL isn't on one of the source hosts
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public static function downloadFile(string $fileUrl, string $filePath): bool
    {
        self::_createClient($fileUrl)->get($fileUrl, ['sink' => $filePath]);
        return true;
    }

    /**
     * Returns a client for fetching from a source host, which sends the token.
     * Refuses URLs, and redirects, that aren't HTTPS on one of the source hosts, so the token never goes anywhere else.
     *
     * @param string $url
     * @return Client
     * @throws \Exception if the URL isn't on one of the source hosts
     */
    private static function _createClient(string $url): Client
    {
        /** @var Settings $settings */
        $settings = ExportImport::getInstance()->getSettings();

        if (!$settings->getIsSourceUrlAllowed($url)) {
            throw new \Exception("\"$url\" isn't an HTTPS URL on one of the allowed source hosts");
        }

        $headers = [];
        if ($token = $settings->getToken()) {
            $headers[ExportImport::TOKEN_HEADER] = $token;
        }

        return Craft::createGuzzleClient([
            'headers' => $headers,
            'connect_timeout' => 10,
            'timeout' => 120,
            'allow_redirects' => [
                'max' => 5,
                'protocols' => ['https'],
                'on_redirect' => static function (RequestInterface $request, ResponseInterface $response, UriInterface $uri) use ($settings) {
                    if (!$settings->getIsSourceUrlAllowed($uri)) {
                        throw new \Exception("Refused a redirect to \"$uri\", which isn't on one of the allowed source hosts");
                    }
                },
            ],
        ]);
    }
}
