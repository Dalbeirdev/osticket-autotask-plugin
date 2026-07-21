<?php
/**
 * Autotask Integration — Instance model (one client Autotask tenant).
 *
 * An Instance represents one client's Autotask PSA: its API credentials,
 * data-center zone, the osTicket department chosen at registration, and its
 * per-client sync options (stored as JSON, read through typed accessors).
 *
 * Secrets are encrypted at rest with osTicket's Crypto (SECRET_SALT). If the
 * Crypto backend is unavailable the value is stored base64-marked so the
 * system keeps working; a real cipher is always preferred when present.
 *
 * @package Autotask Integration
 */

namespace Autotask;

if (!defined('INCLUDE_DIR')) {
    die('Access denied');
}

/**
 * Immutable view over one `autotask_instance` row.
 */
class Instance
{
    /** Subkey used with osTicket Crypto so instance secrets get their own keyspace. */
    private const CRYPTO_SUBKEY = 'autotask.instance';

    /** Marker prefix for the base64 fallback when Crypto is unavailable. */
    private const B64_MARKER = 'b64!';

    /** @var array<string,mixed> Raw database row. */
    private $row;

    /**
     * @param array<string,mixed> $row Row from `autotask_instance`.
     */
    public function __construct(array $row)
    {
        $this->row = $row;
    }

    /* ----- Identity ------------------------------------------------------ */

    public function id(): int
    {
        return (int) $this->row['id'];
    }

    /** Display name, e.g. "Satellite Networks". */
    public function name(): string
    {
        return (string) $this->row['name'];
    }

    /** Short unique badge code, e.g. "SAT". */
    public function code(): string
    {
        return (string) $this->row['code'];
    }

    public function enabled(): bool
    {
        return (bool) $this->row['enabled'];
    }

    /** osTicket department chosen at client registration (0 = not set yet). */
    public function departmentId(): int
    {
        return (int) $this->row['department_id'];
    }

    /* ----- API access ----------------------------------------------------- */

    /**
     * Credential array in the exact shape AutotaskApi's constructor expects.
     *
     * @return array{username:string,secret:string,integration_code:string,zone_url:string}
     */
    public function credentials(): array
    {
        return array(
            'username'         => (string) $this->row['api_username'],
            'secret'           => self::decryptSecret((string) $this->row['api_secret']),
            'integration_code' => (string) $this->row['api_integration_code'],
            'zone_url'         => (string) $this->row['zone_url'],
        );
    }

    /** Cached Autotask web-UI base for deep links ('' until first resolved). */
    public function webBase(): string
    {
        return (string) $this->row['web_base'];
    }

    /* ----- Per-client options (config_json) -------------------------------- */

    /**
     * Read one option from the per-instance JSON config.
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public function config(string $key, $default = null)
    {
        $all = $this->configAll();
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    /**
     * @return array<string,mixed> Whole decoded per-instance option map.
     */
    public function configAll(): array
    {
        $decoded = json_decode((string) ($this->row['config_json'] ?? ''), true);
        return is_array($decoded) ? $decoded : array();
    }

    /* ----- Health --------------------------------------------------------- */

    public function lastSyncAt(): ?string
    {
        return $this->row['last_sync_at'] ?? null;
    }

    /** True/false from the last connection or sync attempt; null = never ran. */
    public function lastOk(): ?bool
    {
        return $this->row['last_ok'] === null ? null : (bool) $this->row['last_ok'];
    }

    /** @return array<string,mixed> Raw row (for admin templates). */
    public function raw(): array
    {
        return $this->row;
    }

    /* ----- Secret encryption (static, shared with the repository) --------- */

    /**
     * Encrypt an API secret for storage. Prefers osTicket's Crypto; falls back
     * to marked base64 only if no crypto backend exists on this install.
     *
     * @param string $plain
     * @return string Storable ciphertext.
     */
    public static function encryptSecret(string $plain): string
    {
        if ($plain === '') {
            return '';
        }
        if (class_exists('Crypto') && defined('SECRET_SALT')) {
            $enc = \Crypto::encrypt($plain, SECRET_SALT, self::CRYPTO_SUBKEY);
            if (is_string($enc) && $enc !== '') {
                return $enc; // osTicket ciphertexts start with '$'
            }
        }
        return self::B64_MARKER . base64_encode($plain);
    }

    /**
     * Decrypt a stored API secret. Accepts all historic storage forms:
     * Crypto ciphertext ('$…'), marked base64, or legacy plaintext.
     *
     * @param string $stored
     * @return string Plaintext secret ('' if undecryptable).
     */
    public static function decryptSecret(string $stored): string
    {
        if ($stored === '') {
            return '';
        }
        if ($stored[0] === '$' && class_exists('Crypto') && defined('SECRET_SALT')) {
            $dec = \Crypto::decrypt($stored, SECRET_SALT, self::CRYPTO_SUBKEY);
            return is_string($dec) ? $dec : '';
        }
        if (strpos($stored, self::B64_MARKER) === 0) {
            $dec = base64_decode(substr($stored, strlen(self::B64_MARKER)), true);
            return $dec === false ? '' : $dec;
        }
        return $stored; // legacy plaintext (pre-encryption rows)
    }
}
