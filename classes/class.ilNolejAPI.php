<?php

/**
 * This file is part of Nolej Repository Object Plugin for ILIAS,
 * developed by OC Open Consulting to integrate ILIAS with Nolej
 * software by Neuronys.
 *
 * @author Vincenzo Padula <vincenzo@oc-group.eu>
 * @copyright 2024 OC Open Consulting SB Srl
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

/**
 * This class provides common methods to interact with Nolej REST API.
 */
class ilNolejAPI
{
    /** @var string Nolej API endpoint */
    public const API_URL = "https://api-live.nolej.io";

    /** @var string[] Allowed audio formats */
    public const TYPE_AUDIO = ["mp3", "wav", "opus", "ogg", "oga", "m4a", "aiff"];

    /** @var string[] Allowed video formats */
    public const TYPE_VIDEO = ["m4v", "mp4", "webm", "mpeg"];

    /** @var string[] Allowed document formats */
    public const TYPE_DOC = ["pdf", "doc", "docx", "odt"];

    /** @var string[] Allowed text file formats */
    public const TYPE_TEXT = ["txt", "htm", "html"];

    /** @var string[] Allowed formats */
    public const TYPE_SUPPORTED = [
        ...self::TYPE_AUDIO,
        ...self::TYPE_VIDEO,
        ...self::TYPE_DOC,
        ...self::TYPE_TEXT
    ];

    /** @var string[] Supported languages */
    public const LANG_SUPPORTED = ["en", "fr", "it", "de", "pt", "es", "nl"];

    /** @var int Max bytes for uploaded files (500 MB) */
    public const MAX_SIZE = 524288000;

    /** @var int Seconds to connect to a server */
    protected const CONNECT_TIMEOUT = 10;

    /** @var int Seconds for the whole request */
    protected const TIMEOUT = 60;

    /** @var ?string */
    private static ?string $key = null;

    /** @var int HTTP status of the last request, 0 if there was none */
    protected int $last_status = 0;

    /**
     * API class constructor.
     */
    public function __construct()
    {
        self::$key = self::getKey();
    }

    /**
     * Get the saved API key.
     * @return string
     */
    public static function getKey(): string
    {
        if (self::$key == null) {
            self::$key = (new ilNolejSettings())->getApiKey();
        }
        return self::$key;
    }

    /**
     * Check that the API key has been set.
     * @return bool
     */
    public static function hasKey(): bool
    {
        return !empty(self::getKey());
    }

    /**
     * @param string $path
     * @param array<string, mixed> $data
     * @param bool $decode
     * @return object|string
     */
    public function post($path, $data = [], $decode = true): object|string
    {
        return $this->request("POST", $path, (string) json_encode($data), $decode);
    }

    /**
     * Put to Nolej server
     * @param string $path
     * @param mixed $data
     * @param bool $encode input's data
     * @param bool $decode output
     * @return object|string
     */
    public function put($path, $data = [], $encode = false, $decode = true): object|string
    {
        $data_json = $encode ? json_encode($data) : $data;

        return $this->request("PUT", $path, (string) $data_json, $decode);
    }

    /**
     * @param string $path
     * @param mixed $data
     * @param bool $encodeInput
     * @param bool $decodeOutput
     *
     * @return object|string return the result given by Nolej. If
     * $decodeOutput is true, treat the result as json object and decode it.
     * @phpstan-return ($decodeOutput is true ? object|string : string)
     * @psalm-return ($decodeOutput is true ? object|string : string)
     */
    public function get(
        $path,
        $data = "",
        $encodeInput = false,
        $decodeOutput = true
    ): object|string {
        $encodedData = $encodeInput ? json_encode($data) : $data;

        return $this->request("GET", $path, (string) $encodedData, $decodeOutput);
    }

    /**
     * Check if the last request got a 2xx response.
     * @return bool
     */
    public function lastRequestSucceeded(): bool
    {
        return $this->last_status >= 200 && $this->last_status < 300;
    }

    /**
     * Check the name of an activity given by Nolej: it is used as a
     * file name.
     * @param mixed $activity
     * @return bool
     */
    public function isValidActivity($activity): bool
    {
        return is_object($activity)
            && preg_match('/^[a-z0-9_]+$/', $this->getActivityField($activity, "activity_name")) === 1
            && $this->getActivityField($activity, "url") !== "";
    }

    /**
     * Get a string field of an activity given by Nolej.
     * @param mixed $activity
     * @param string $field
     * @return string empty if missing or not a string
     */
    public function getActivityField($activity, string $field): string
    {
        if (!is_object($activity) || !is_string($activity->$field ?? null)) {
            return "";
        }
        return $activity->$field;
    }

    /**
     * Download a file from an https url (no API key is sent).
     * @param string $url
     * @return string the content, empty on failure
     */
    public function downloadUrl(string $url): string
    {
        if (!$this->isHttpsUrl($url)) {
            return "";
        }

        try {
            $response = $this->createClient()->request("GET", $url);
        } catch (GuzzleHttp\Exception\GuzzleException $e) {
            $this->logFailure($e);
            return "";
        }

        return $response->getStatusCode() >= 200 && $response->getStatusCode() < 300
            ? (string) $response->getBody()
            : "";
    }

    /**
     * Download a file from an https url to a path (no API key is sent).
     * @param string $url
     * @param string $path
     * @return bool true if a not empty file has been saved
     */
    public function downloadUrlToFile(string $url, string $path): bool
    {
        if (!$this->isHttpsUrl($url)) {
            return false;
        }

        try {
            $response = $this->createClient()->request("GET", $url, ["sink" => $path]);
        } catch (GuzzleHttp\Exception\GuzzleException $e) {
            $this->logFailure($e);
            return false;
        }

        $ok = $response->getStatusCode() >= 200
            && $response->getStatusCode() < 300
            && is_file($path)
            && filesize($path) > 0;
        if (!$ok && is_file($path)) {
            unlink($path);
        }
        return $ok;
    }

    /**
     * Send a request to the Nolej API.
     * @param string $method
     * @param string $path
     * @param string $body
     * @param bool $decode
     * @return object|string The decoded response if $decode is true and it
     * is a json object, the body otherwise. If Nolej cannot be reached:
     * an object with the property "Error" (empty string if $decode is false).
     */
    protected function request(string $method, string $path, string $body, bool $decode): object|string
    {
        $this->last_status = 0;

        try {
            $response = $this->createClient()->request($method, self::API_URL . $path, [
                "headers" => [
                    "Authorization" => "X-API-KEY " . self::$key,
                    "User-Agent" => "ILIAS Plugin",
                    "Content-Type" => "application/json"
                ],
                "body" => $body
            ]);
        } catch (GuzzleHttp\Exception\GuzzleException $e) {
            $this->logFailure($e);
            if (!$decode) {
                return "";
            }
            $error = new stdClass();
            $error->Error = ilNolejPlugin::getInstance()->txt("err_api_unreachable");
            return $error;
        }

        $this->last_status = $response->getStatusCode();
        $content = (string) $response->getBody();
        if (!$decode) {
            return $content;
        }

        $object = json_decode($content);
        return is_object($object) || is_string($object) ? $object : $content;
    }

    /**
     * @return GuzzleHttp\Client
     */
    protected function createClient(): GuzzleHttp\Client
    {
        return new GuzzleHttp\Client([
            "http_errors" => false,
            "connect_timeout" => self::CONNECT_TIMEOUT,
            "timeout" => self::TIMEOUT,
        ]);
    }

    /**
     * @param string $url
     * @return bool
     */
    protected function isHttpsUrl(string $url): bool
    {
        return str_starts_with(strtolower($url), "https://");
    }

    /**
     * Log a failed request: only the class of the exception, never the
     * message (it may contain the url or the key).
     * @param Throwable $e
     * @return void
     */
    protected function logFailure(Throwable $e): void
    {
        ilNolejPlugin::getInstance()->log(
            "Request to a remote server failed: " . get_class($e),
            ilLogLevel::ERROR
        );
    }
}
