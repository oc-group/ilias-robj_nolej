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
 * Settings of the plugin, stored in ilSetting (module "plugin_xnlj").
 *
 * The API key and the webhook token are encrypted at rest with AES-256-GCM.
 * The encryption key lives in the private storage of ILIAS, outside the
 * database, so a database dump alone does not reveal the secrets.
 */
final class ilNolejSettings
{
    /** @var string */
    private const MODULE = "plugin_xnlj";

    /** @var string */
    private const SETTING_API_KEY = "api_key";

    /** @var string */
    private const SETTING_INTERVAL = "interval";

    /** @var string */
    private const SETTING_WEBHOOK_TOKEN = "webhook_token";

    /** @var string directory in the private storage */
    private const KEY_DIR = "xnlj";

    /** @var string encryption key file in the private storage */
    private const KEY_FILE = "xnlj/encryption.sec";

    /** @var string */
    private const CIPHER = "aes-256-gcm";

    /** @var string prefix of an encrypted value */
    private const PREFIX = "v1:";

    /** @var int */
    private const IV_LENGTH = 12;

    /** @var int */
    private const TAG_LENGTH = 16;

    /** @var int */
    private const KEY_LENGTH = 32;

    /** @var int seconds between two update checks */
    public const DEFAULT_INTERVAL = 1;

    /** @var ilSetting */
    private ilSetting $setting;

    public function __construct()
    {
        $this->setting = new ilSetting(self::MODULE);
    }

    /**
     * Get the API key, empty if not set (or not readable).
     * @return string
     */
    public function getApiKey(): string
    {
        return $this->readSecret(self::SETTING_API_KEY);
    }

    /**
     * Save the API key (encrypted).
     * @param string $api_key
     * @return void
     */
    public function setApiKey(string $api_key): void
    {
        $this->writeSecret(self::SETTING_API_KEY, $api_key);
    }

    /**
     * Check that the API key has been set.
     * @return bool
     */
    public function hasApiKey(): bool
    {
        return $this->getApiKey() !== "";
    }

    /**
     * Seconds between two checks of the document status.
     * @return int
     */
    public function getInterval(): int
    {
        $interval = (int) $this->setting->get(
            self::SETTING_INTERVAL,
            (string) self::DEFAULT_INTERVAL
        );
        return max(1, $interval);
    }

    /**
     * @param int $interval seconds, at least 1
     * @return void
     */
    public function setInterval(int $interval): void
    {
        $this->setting->set(self::SETTING_INTERVAL, (string) max(1, $interval));
    }

    /**
     * Get the token of this installation for the webhook; created at
     * the first use.
     * @return string
     */
    public function getWebhookToken(): string
    {
        $token = $this->readSecret(self::SETTING_WEBHOOK_TOKEN);
        if ($token === "") {
            $token = bin2hex(random_bytes(16));
            $this->writeSecret(self::SETTING_WEBHOOK_TOKEN, $token);
        }
        return $token;
    }

    /**
     * Check the token received by the webhook.
     * @param string $token
     * @return bool
     */
    public function isValidWebhookToken(string $token): bool
    {
        return $token !== "" && hash_equals($this->getWebhookToken(), $token);
    }

    /**
     * Delete every setting of the plugin.
     * @return void
     */
    public function deleteAll(): void
    {
        $this->setting->deleteAll();
    }

    /**
     * Read a secret setting. A value without the version prefix (copied by
     * the database update) is encrypted at the first read.
     * @param string $keyword
     * @return string empty if not set or not readable
     */
    private function readSecret(string $keyword): string
    {
        $stored = (string) $this->setting->get($keyword, "");
        if ($stored === "") {
            return "";
        }

        if (!str_starts_with($stored, self::PREFIX)) {
            try {
                $this->writeSecret($keyword, $stored);
            } catch (Throwable) {
                // Keep the plain value until the key can be created.
            }
            return $stored;
        }

        return $this->decrypt($stored);
    }

    /**
     * @param string $keyword
     * @param string $value
     * @throws RuntimeException if the value cannot be encrypted
     * @return void
     */
    private function writeSecret(string $keyword, string $value): void
    {
        $this->setting->set($keyword, $this->encrypt($value));
    }

    /**
     * @param string $plain
     * @throws RuntimeException
     * @return string
     */
    private function encrypt(string $plain): string
    {
        $key = $this->getEncryptionKey(true);
        if ($key === null) {
            throw new RuntimeException("Encryption key not available.");
        }

        $iv = random_bytes(self::IV_LENGTH);
        $tag = "";
        $cipher = openssl_encrypt(
            $plain,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            "",
            self::TAG_LENGTH
        );
        if ($cipher === false) {
            throw new RuntimeException("Encryption failed.");
        }

        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    /**
     * @param string $stored
     * @return string empty on failure
     */
    private function decrypt(string $stored): string
    {
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= self::IV_LENGTH + self::TAG_LENGTH) {
            return "";
        }

        $key = $this->getEncryptionKey(false);
        if ($key === null) {
            return "";
        }

        $iv = substr($raw, 0, self::IV_LENGTH);
        $tag = substr($raw, self::IV_LENGTH, self::TAG_LENGTH);
        $cipher = substr($raw, self::IV_LENGTH + self::TAG_LENGTH);

        $plain = openssl_decrypt(
            $cipher,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        return $plain === false ? "" : $plain;
    }

    /**
     * Get the encryption key from the private storage.
     * @param bool $create create the key file if it does not exist
     * @return string|null null if not available
     */
    private function getEncryptionKey(bool $create): ?string
    {
        global $DIC;

        $storage = $DIC->filesystem()->storage();

        if ($storage->has(self::KEY_FILE)) {
            $key = $storage->read(self::KEY_FILE);
            return strlen($key) === self::KEY_LENGTH ? $key : null;
        }

        if (!$create) {
            return null;
        }

        $key = random_bytes(self::KEY_LENGTH);
        if (!$storage->hasDir(self::KEY_DIR)) {
            $storage->createDir(self::KEY_DIR);
        }
        $storage->put(self::KEY_FILE, $key);
        return $key;
    }
}
