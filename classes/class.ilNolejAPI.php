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

    /** @var ?string */
    private static ?string $key = null;

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
        if (empty(self::$key)) {
            self::$key = ilNolejPlugin::getConfig("api_key", "");
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
     * @phpstan-return ($decode is true ? object|string : string)
     * @psalm-return ($decode is true ? object|string : string)
     */
    public function post(string $path, array $data = [], bool $decode = true): object|string
    {
        return $this->request("POST", $path, json_encode($data), $decode);
    }

    /**
     * Put to Nolej server
     * @param string $path
     * @param mixed $data
     * @param bool $encode input's data
     * @param bool $decode output
     * @return object|string
     * @phpstan-return ($decode is true ? object|string : string)
     * @psalm-return ($decode is true ? object|string : string)
     */
    public function put(string $path, $data = [], bool $encode = false, bool $decode = true): object|string
    {
        return $this->request("PUT", $path, $encode ? json_encode($data) : $data, $decode);
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
        string $path,
        $data = "",
        bool $encodeInput = false,
        bool $decodeOutput = true
    ): object|string {
        return $this->request("GET", $path, $encodeInput ? json_encode($data) : $data, $decodeOutput);
    }

    /**
     * Send a request to the Nolej API.
     * @param string $method
     * @param string $path
     * @param mixed $body
     * @param bool $decode
     * @return object|string the decoded json object, or the raw body if not decoded or not a json.
     * @phpstan-return ($decode is true ? object|string : string)
     * @psalm-return ($decode is true ? object|string : string)
     */
    protected function request(string $method, string $path, $body, bool $decode): object|string
    {
        $client = new GuzzleHttp\Client(["http_errors" => false]);
        $response = $client->request($method, self::API_URL . $path, [
            "headers" => [
                "Authorization" => "X-API-KEY " . self::$key,
                "User-Agent" => "ILIAS Plugin",
                "Content-Type" => "application/json"
            ],
            "body" => $body
        ]);

        $content = (string) $response->getBody();
        if (!$decode) {
            return $content;
        }

        $object = json_decode($content);
        return $object !== null ? $object : $content;
    }
}
