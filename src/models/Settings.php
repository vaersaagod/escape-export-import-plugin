<?php

namespace escape\exportimport\models;

use Craft;
use craft\base\Model;
use craft\helpers\App;
use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\UriInterface;

/**
 * Class Settings
 * @package escape\exportimport\models
 */
class Settings extends Model
{

    /** @var int Tokens shorter than this are treated as not set */
    const MIN_TOKEN_LENGTH = 32;

    /** @var string[]|null */
    public $exportableSections;

    /** @var string[]|null */
    public $exportableCategoryGroups;

    /**
     * The secret that sites send each other when fetching exports. Every site that exports or imports
     * must use the same token. Supports environment variables.
     *
     * @var string|null
     */
    public ?string $token = '$ESCAPE_EXPORTIMPORT_TOKEN';

    /**
     * The hosts that elements can be imported from, e.g. `['www.example.com']`. The token is only ever sent to these.
     *
     * @var string[]
     */
    public array $sourceHosts = [];

    /**
     * Returns the parsed token, or null if it isn't set or is too short to be safe
     *
     * @return string|null
     */
    public function getToken(): ?string
    {
        $token = App::parseEnv($this->token);

        if (!is_string($token) || $token === '') {
            return null;
        }

        if (strlen($token) < self::MIN_TOKEN_LENGTH) {
            Craft::warning('The Escape Export & Import token is shorter than ' . self::MIN_TOKEN_LENGTH . ' characters, and is ignored', __METHOD__);
            return null;
        }

        return $token;
    }

    /**
     * Returns whether elements can be imported from a URL: it has to use HTTPS, on one of the source hosts.
     * Parsed with Guzzle's URI class, so the host checked is the host Guzzle requests.
     *
     * @param string|UriInterface $url
     * @return bool
     */
    public function getIsSourceUrlAllowed(string|UriInterface $url): bool
    {
        try {
            $uri = $url instanceof UriInterface ? $url : new Uri($url);
        } catch (\InvalidArgumentException) {
            return false;
        }

        if ($uri->getScheme() !== 'https' || $uri->getUserInfo() !== '' || $uri->getHost() === '') {
            return false;
        }

        $sourceHosts = array_map(static fn(string $sourceHost) => strtolower(trim($sourceHost)), $this->sourceHosts);

        return in_array(strtolower($uri->getHost()), $sourceHosts, true);
    }
}
