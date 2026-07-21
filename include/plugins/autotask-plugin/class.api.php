<?php
/**
 * Autotask Integration — REST API client (native cURL, no dependencies).
 *
 * Implements the subset of the Autotask PSA REST API v1.0 needed for ticket
 * synchronization: zone detection, tickets, contacts, companies, ticket notes,
 * and entity field metadata (picklists).
 *
 * Authentication uses the three Autotask headers:
 *   - ApiIntegrationcode
 *   - UserName
 *   - Secret
 *
 * @see https://ww1.autotask.net/help/developerhelp/Content/APIs/REST/REST_API_Home.htm
 * @package Autotask Integration
 */

namespace Autotask;

if (!defined('INCLUDE_DIR')) {
    die('Access denied');
}

/**
 * Thin, defensive wrapper over the Autotask REST endpoints.
 */
class AutotaskApi
{
    /** Endpoint used to discover the tenant's data-center zone. */
    private const ZONE_DISCOVERY = 'https://webservices.autotask.net/atservicesrest/v1.0/zoneInformation';

    /** API version path segment appended to the zone base. */
    private const API_VERSION = 'v1.0';

    /** @var string */ private $username;
    /** @var string */ private $secret;
    /** @var string */ private $integrationCode;
    /** @var string */ private $zoneUrl;
    /** @var Logger|null */ private $logger;
    /** @var int Default request timeout (seconds). */
    private $timeout = 30;
    /** @var resource|\CurlHandle|null Reused cURL handle (keep-alive). */
    private $ch = null;
    /** @var string|null Web UI base captured from zone discovery (e.g. https://ww16.autotask.net). */
    private $webUrl = null;

    /**
     * @param array       $creds  username, secret, integration_code, zone_url
     * @param Logger|null $logger
     */
    public function __construct(array $creds, ?Logger $logger = null)
    {
        $this->username        = trim($creds['username'] ?? '');
        $this->secret          = (string) ($creds['secret'] ?? '');
        $this->integrationCode = trim($creds['integration_code'] ?? '');
        $this->zoneUrl         = rtrim((string) ($creds['zone_url'] ?? ''), '/');
        $this->logger          = $logger;
    }

    /* ------------------------------------------------------------------ */
    /* Connection / zone                                                  */
    /* ------------------------------------------------------------------ */

    /**
     * Validate credentials by resolving the zone and issuing a trivial query.
     *
     * @return array{ok:bool,message:string,zone_url:string}
     */
    public function testConnection(): array
    {
        try {
            $zone = $this->resolveZone();
            // A cheap, bounded query proves the credentials are accepted.
            $this->request('GET', 'Companies/query?search='
                . rawurlencode(json_encode(array(
                    'filter' => array(array('op' => 'gte', 'field' => 'id', 'value' => 0)),
                    'MaxRecords' => 1,
                ))));
            return array('ok' => true, 'message' => 'Connection successful.', 'zone_url' => $zone);
        } catch (ApiException $e) {
            return array('ok' => false, 'message' => $e->getMessage(), 'zone_url' => $this->zoneUrl);
        } catch (\Throwable $e) {
            return array('ok' => false, 'message' => $e->getMessage(), 'zone_url' => $this->zoneUrl);
        }
    }

    /**
     * Resolve and cache the zone base URL. Uses the configured value if present,
     * otherwise queries the zone-discovery endpoint with the API username.
     *
     * @return string Zone base including version, e.g.
     *                https://webservices2.autotask.net/atservicesrest/v1.0/
     */
    public function resolveZone(): string
    {
        if ($this->zoneUrl) {
            return $this->apiBase();
        }
        if ($this->username === '') {
            throw new ApiException('API username is required to detect the zone.');
        }

        $url = self::ZONE_DISCOVERY . '?user=' . rawurlencode($this->username);
        $resp = $this->rawCurl('GET', $url, null, false);

        if ($resp['status'] !== 200 || empty($resp['json']['url'])) {
            throw new ApiException(
                'Zone detection failed (HTTP ' . $resp['status'] . '). '
                . 'Verify the API username.', $resp['status']
            );
        }
        // zoneInformation already returns the full versioned base, e.g.
        // https://webservices14.autotask.net/ATServicesRest/V1.0/
        // Store it verbatim (minus trailing slash); apiBase() normalises it.
        $this->zoneUrl = rtrim($resp['json']['url'], '/');
        if (!empty($resp['json']['webUrl'])) {
            $this->webUrl = rtrim((string) $resp['json']['webUrl'], '/');
        }
        return $this->apiBase();
    }

    /**
     * Build the full versioned API base (with trailing slash) from whatever the
     * admin entered or zone-discovery returned. Handles all common shapes:
     *   - .../ATServicesRest/V1.0   (already versioned — any case)
     *   - .../atservicesrest        (path, no version)
     *   - https://webservicesN.autotask.net   (host only)
     *
     * @return string
     */
    private function apiBase(): string
    {
        $base = rtrim($this->zoneUrl, '/');

        // Already ends with a version segment (/v1.0, /V1.0, /v1.6, …): use as-is.
        if (preg_match('#/v\d+\.\d+$#i', $base)) {
            return $base . '/';
        }
        // Has the REST service path but no version: append the version.
        if (stripos($base, 'atservicesrest') !== false) {
            return $base . '/' . self::API_VERSION . '/';
        }
        // Bare host: append the full REST path + version.
        return $base . '/atservicesrest/' . self::API_VERSION . '/';
    }

    /**
     * Base URL of the Autotask WEB UI for this tenant's zone (for deep links).
     * Prefers the webUrl returned by zone discovery; otherwise derives it from
     * the API host using Autotask's documented pairing (webservicesN -> wwN).
     *
     * @return string|null e.g. "https://ww16.autotask.net", or null if unknown.
     */
    public function webBase(): ?string
    {
        if ($this->webUrl) {
            return $this->webUrl;
        }
        if (!$this->zoneUrl && $this->username !== '') {
            try {
                $this->resolveZone(); // may also populate $this->webUrl
            } catch (\Throwable $e) {
                return null;
            }
        }
        if ($this->webUrl) {
            return $this->webUrl;
        }
        $host = parse_url((string) $this->zoneUrl, PHP_URL_HOST);
        if ($host && preg_match('/^webservices(\d+)\./i', $host, $m)) {
            return 'https://ww' . $m[1] . '.autotask.net';
        }
        return null;
    }

    /* ------------------------------------------------------------------ */
    /* Tickets                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * @param array $fields  Autotask Ticket entity fields.
     * @return int  New Autotask ticket id.
     */
    public function createTicket(array $fields): int
    {
        $resp = $this->request('POST', 'Tickets', $fields);
        if (empty($resp['itemId'])) {
            throw new ApiException('Ticket create returned no itemId.');
        }
        return (int) $resp['itemId'];
    }

    /**
     * Patch an existing Autotask ticket. Autotask uses PATCH for partial update.
     *
     * @param int   $id
     * @param array $fields
     */
    public function updateTicket(int $id, array $fields): void
    {
        $fields['id'] = $id;
        $this->request('PATCH', 'Tickets', $fields);
    }

    /**
     * @param int $id
     * @return array|null Ticket entity, or null if not found.
     */
    public function getTicket(int $id): ?array
    {
        $resp = $this->request('GET', "Tickets/$id");
        return $resp['item'] ?? null;
    }

    /**
     * Query tickets modified since a given UTC timestamp (for inbound sync).
     *
     * @param string $sinceUtc  e.g. "2026-06-30T00:00:00Z"
     * @param int    $maxRecords
     * @return array<int,array<string,mixed>>
     */
    public function getTicketsModifiedSince(string $sinceUtc, int $maxRecords = 50): array
    {
        $search = json_encode(array(
            'filter' => array(
                array('op' => 'gte', 'field' => 'lastActivityDate', 'value' => $sinceUtc),
            ),
            'MaxRecords' => max(1, min(500, $maxRecords)),
        ));
        $resp = $this->request('GET', 'Tickets/query?search=' . rawurlencode($search));
        return $resp['items'] ?? array();
    }

    /**
     * Fetch OPEN/active Autotask tickets (status != Complete) for inbound import.
     *
     * @param int    $maxRecords
     * @param int    $completeStatus  Autotask "Complete" picklist value (default 5).
     * @return array<int,array<string,mixed>>
     */
    public function getOpenTickets(int $maxRecords = 50, int $completeStatus = 5): array
    {
        $search = json_encode(array(
            'filter' => array(
                array('op' => 'noteq', 'field' => 'status', 'value' => $completeStatus),
            ),
            'MaxRecords' => max(1, min(500, $maxRecords)),
        ));
        $resp = $this->request('GET', 'Tickets/query?search=' . rawurlencode($search));
        return $resp['items'] ?? array();
    }

    /**
     * Query tickets for import using an admin-defined filter spec.
     *
     * @param array $spec  From Settings::importFilterSpec().
     * @param int   $max
     * @return array<int,array<string,mixed>>
     */
    public function queryTicketsForImport(array $spec, int $max = 50): array
    {
        $filter = array();
        if (!empty($spec['status_op'])) {
            $filter[] = array('op' => $spec['status_op'], 'field' => 'status', 'value' => $spec['status_value']);
        }
        if (!empty($spec['company_ids'])) {
            $filter[] = array('op' => 'in', 'field' => 'companyID', 'value' => array_values($spec['company_ids']));
        }
        // Queue + resource scoping. When BOTH are set they combine as OR:
        // "in our queue OR assigned to one of our resources" — the co-managed
        // pattern where the queue is the contract and direct assignment is the
        // safety net. A single filter applies as a plain AND condition.
        if (!empty($spec['queue_ids']) && !empty($spec['resource_ids'])) {
            $filter[] = array('op' => 'or', 'items' => array(
                array('op' => 'in', 'field' => 'queueID', 'value' => array_values($spec['queue_ids'])),
                array('op' => 'in', 'field' => 'assignedResourceID', 'value' => array_values($spec['resource_ids'])),
            ));
        } elseif (!empty($spec['queue_ids'])) {
            $filter[] = array('op' => 'in', 'field' => 'queueID', 'value' => array_values($spec['queue_ids']));
        } elseif (!empty($spec['resource_ids'])) {
            $filter[] = array('op' => 'in', 'field' => 'assignedResourceID', 'value' => array_values($spec['resource_ids']));
        }
        if (!empty($spec['since_utc'])) {
            $filter[] = array('op' => 'gte', 'field' => 'lastActivityDate', 'value' => $spec['since_utc']);
        }
        if (!$filter) {
            $filter[] = array('op' => 'gte', 'field' => 'id', 'value' => 0);
        }
        $search = json_encode(array('filter' => $filter, 'MaxRecords' => max(1, min(500, $max))));
        $resp = $this->request('GET', 'Tickets/query?search=' . rawurlencode($search));
        return $resp['items'] ?? array();
    }

    /* ------------------------------------------------------------------ */
    /* Ticket notes (child collection of Tickets)                         */
    /* ------------------------------------------------------------------ */

    /**
     * @param int    $ticketId
     * @param string $title
     * @param string $description
     * @param int    $noteType   Autotask NoteType picklist (default 1 = Task Detail).
     * @param int    $publish    1 = All Autotask Users, 2 = Internal only.
     * @return int   New note id.
     */
    public function createTicketNote(int $ticketId, string $title, string $description, int $noteType = 1, int $publish = 1): int
    {
        $body = array(
            'ticketID'    => $ticketId,
            'title'       => mb_substr($title !== '' ? $title : 'osTicket update', 0, 250),
            'description' => $description,
            'noteType'    => $noteType,
            'publish'     => $publish,
        );
        $resp = $this->request('POST', "Tickets/$ticketId/Notes", $body);
        return (int) ($resp['itemId'] ?? 0);
    }

    /**
     * @param int    $ticketId
     * @param string $sinceUtc
     * @return array<int,array<string,mixed>>
     */
    public function getTicketNotesSince(int $ticketId, string $sinceUtc): array
    {
        // Ticket notes are queried via the TOP-LEVEL TicketNotes entity filtered
        // by ticketID. The child path /Tickets/{id}/Notes/query returns 404 on
        // this API; /TicketNotes/query is the correct endpoint.
        $resp = $this->request('POST', 'TicketNotes/query', array(
            'filter' => array(
                array('op' => 'eq',  'field' => 'ticketID', 'value' => $ticketId),
                array('op' => 'gte', 'field' => 'lastActivityDate', 'value' => $sinceUtc),
            ),
        ));
        return $resp['items'] ?? array();
    }

    /* ------------------------------------------------------------------ */
    /* Contacts / companies                                               */
    /* ------------------------------------------------------------------ */

    /**
     * @param string $email
     * @return array|null First matching active contact.
     */
    public function findContactByEmail(string $email): ?array
    {
        if ($email === '') {
            return null;
        }
        $search = json_encode(array(
            'filter' => array(
                array('op' => 'eq', 'field' => 'emailAddress', 'value' => $email),
            ),
            'MaxRecords' => 1,
        ));
        $resp = $this->request('GET', 'Contacts/query?search=' . rawurlencode($search));
        return $resp['items'][0] ?? null;
    }

    /**
     * @param string $name
     * @return array|null First matching company.
     */
    public function findCompanyByName(string $name): ?array
    {
        if ($name === '') {
            return null;
        }
        $search = json_encode(array(
            'filter' => array(
                array('op' => 'eq', 'field' => 'companyName', 'value' => $name),
            ),
            'MaxRecords' => 1,
        ));
        $resp = $this->request('GET', 'Companies/query?search=' . rawurlencode($search));
        return $resp['items'][0] ?? null;
    }

    /* ------------------------------------------------------------------ */
    /* Time entries                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * Create an Autotask time entry.
     *
     * @param array $fields TimeEntry entity fields.
     * @return int New time entry id.
     */
    public function createTimeEntry(array $fields): int
    {
        $resp = $this->request('POST', 'TimeEntries', $fields);
        if (empty($resp['itemId'])) {
            throw new ApiException('Time entry create returned no itemId.');
        }
        return (int) $resp['itemId'];
    }

    /**
     * @param int $ticketId
     * @return array<int,array<string,mixed>> Time entries for a ticket.
     */
    public function getTimeEntries(int $ticketId): array
    {
        $search = json_encode(array(
            'filter' => array(
                array('op' => 'eq', 'field' => 'ticketID', 'value' => $ticketId),
            ),
            'MaxRecords' => 100,
        ));
        $resp = $this->request('GET', 'TimeEntries/query?search=' . rawurlencode($search));
        return $resp['items'] ?? array();
    }

    /**
     * @return array<int,array<string,mixed>> Active billing codes (work types).
     */
    public function getBillingCodes(): array
    {
        return $this->queryActive('BillingCodes');
    }

    /**
     * @return array<int,array<string,mixed>> Active roles.
     */
    public function getRoles(): array
    {
        return $this->queryActive('Roles');
    }

    /**
     * @return array<int,array<string,mixed>> Active resources (technicians).
     */
    public function getResources(): array
    {
        return $this->queryActive('Resources');
    }

    /**
     * Find an Autotask resource by email address (for agent -> resource mapping).
     *
     * @param string $email
     * @return array|null Resource entity.
     */
    public function getResourceByEmail(string $email): ?array
    {
        if ($email === '') {
            return null;
        }
        $search = json_encode(array(
            'filter' => array(
                array('op' => 'eq', 'field' => 'email', 'value' => $email),
            ),
            'MaxRecords' => 1,
        ));
        $resp = $this->request('GET', 'Resources/query?search=' . rawurlencode($search));
        return $resp['items'][0] ?? null;
    }

    /**
     * Generic "active records" query helper used for picklist sources.
     *
     * @param string $entity
     * @param int    $max
     * @return array<int,array<string,mixed>>
     */
    private function queryActive(string $entity, int $max = 500): array
    {
        $search = json_encode(array(
            'filter' => array(
                array('op' => 'eq', 'field' => 'isActive', 'value' => true),
            ),
            'MaxRecords' => max(1, min(500, $max)),
        ));
        $resp = $this->request('GET', "$entity/query?search=" . rawurlencode($search));
        return $resp['items'] ?? array();
    }

    /* ------------------------------------------------------------------ */
    /* Contacts / companies                                               */
    /* ------------------------------------------------------------------ */

    /**
     * @param int $id Autotask contact id.
     * @return array|null Contact entity.
     */
    public function getContact(int $id): ?array
    {
        $resp = $this->request('GET', "Contacts/$id");
        return $resp['item'] ?? null;
    }

    /**
     * @param int $id Autotask company id.
     * @return array|null Company entity.
     */
    public function getCompany(int $id): ?array
    {
        $resp = $this->request('GET', "Companies/$id");
        return $resp['item'] ?? null;
    }

    /**
     * Fetch picklist/field metadata for an entity (used by the dashboard to help
     * admins look up status/priority/queue IDs).
     *
     * @param string $entity e.g. "Tickets"
     * @return array
     */
    public function getFieldInfo(string $entity): array
    {
        $resp = $this->request('GET', "$entity/entityInformation/fields");
        return $resp['fields'] ?? array();
    }

    /* ------------------------------------------------------------------ */
    /* Core request handling                                              */
    /* ------------------------------------------------------------------ */

    /**
     * Issue an authenticated API request, decoding JSON and translating errors
     * into ApiException. Performs a single inline retry on HTTP 429/503 honoring
     * Retry-After (bounded), leaving longer backoff to the retry queue.
     *
     * @param string     $method  GET|POST|PATCH|PUT|DELETE
     * @param string     $path    Path relative to the API base.
     * @param array|null $body
     * @return array  Decoded JSON response (associative).
     */
    public function request(string $method, string $path, ?array $body = null): array
    {
        $this->resolveZone();
        $url = $this->apiBase() . ltrim($path, '/');

        $attempt = 0;
        do {
            $attempt++;
            $resp = $this->rawCurl($method, $url, $body, true);

            // Transient: one short inline retry before deferring to the queue.
            if (in_array($resp['status'], array(429, 503), true) && $attempt === 1) {
                $wait = (int) ($resp['retry_after'] ?? 2);
                $wait = max(1, min(5, $wait)); // never block the web request long
                usleep($wait * 1000000);
                continue;
            }
            break;
        } while ($attempt < 2);

        return $this->handleResponse($method, $url, $resp);
    }

    /**
     * @param string $method
     * @param string $url
     * @param array  $resp  Result of rawCurl().
     * @return array
     */
    private function handleResponse(string $method, string $url, array $resp): array
    {
        $status = $resp['status'];
        $json   = $resp['json'];

        if ($this->logger) {
            $this->logger->debug("API $method $url -> $status", array(
                'category' => 'api',
                'request'  => $resp['sent_body'] ?? '',
                'response' => $resp['raw'] ?? '',
            ));
        }

        if ($status >= 200 && $status < 300) {
            return is_array($json) ? $json : array();
        }

        // Build a meaningful message from Autotask's error envelope.
        $message = $this->extractError($json, $resp['raw'] ?? '', $status);

        $retryable = in_array($status, array(0, 408, 429, 500, 502, 503, 504), true);

        if ($status === 401 || $status === 403) {
            if ($this->logger) {
                $this->logger->error("Authentication failure (HTTP $status): $message",
                    array('category' => 'auth'));
            }
            throw new ApiException("Authentication failed: $message", $status, false);
        }

        throw new ApiException($message, $status, $retryable);
    }

    /**
     * Pull a human message out of Autotask's error response shapes.
     *
     * @param mixed  $json
     * @param string $raw
     * @param int    $status
     */
    private function extractError($json, string $raw, int $status): string
    {
        if (is_array($json)) {
            if (!empty($json['errors']) && is_array($json['errors'])) {
                return implode('; ', array_map('strval', $json['errors']));
            }
            if (!empty($json['message'])) {
                return (string) $json['message'];
            }
        }
        $snippet = trim(substr(strip_tags((string) $raw), 0, 300));
        return "HTTP $status" . ($snippet !== '' ? ": $snippet" : '');
    }

    /**
     * Low-level cURL execution.
     *
     * @param string     $method
     * @param string     $url
     * @param array|null $body
     * @param bool       $auth  Whether to attach authentication headers.
     * @return array{status:int,json:mixed,raw:string,retry_after:?int,sent_body:?string}
     */
    private function rawCurl(string $method, string $url, ?array $body, bool $auth): array
    {
        if (!function_exists('curl_init')) {
            throw new ApiException('PHP cURL extension is required but not installed.');
        }

        // Reuse one handle across calls so the TLS connection is kept alive
        // (big win for batch runs that make many requests to the same host).
        if ($this->ch === null) {
            $this->ch = curl_init();
        } else {
            curl_reset($this->ch);
        }
        $ch = $this->ch;
        $headers = array('Content-Type: application/json', 'Accept: application/json');
        if ($auth) {
            $headers[] = 'ApiIntegrationcode: ' . $this->integrationCode;
            $headers[] = 'UserName: ' . $this->username;
            $headers[] = 'Secret: ' . $this->secret;
        }

        $sentBody = null;
        curl_setopt_array($ch, array(
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_USERAGENT      => 'osTicket-Autotask/1.0',
        ));

        if ($body !== null && in_array($method, array('POST', 'PATCH', 'PUT'), true)) {
            $sentBody = json_encode($body);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $sentBody);
        }

        $response   = curl_exec($ch);
        $errno      = curl_errno($ch);
        $error      = curl_error($ch);
        $status     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        // NB: handle intentionally NOT closed here — reused (see __destruct).

        if ($errno) {
            throw new ApiException("Network error: $error", 0, true);
        }

        $rawHeaders = substr((string) $response, 0, $headerSize);
        $rawBody    = substr((string) $response, $headerSize);
        $retryAfter = null;
        if (preg_match('/^Retry-After:\s*(\d+)/mi', $rawHeaders, $m)) {
            $retryAfter = (int) $m[1];
        }

        return array(
            'status'      => $status,
            'json'        => json_decode($rawBody, true),
            'raw'         => $rawBody,
            'retry_after' => $retryAfter,
            'sent_body'   => $sentBody,
        );
    }

    /**
     * Close the reused cURL handle when the client is destroyed.
     */
    public function __destruct()
    {
        if ($this->ch !== null) {
            @curl_close($this->ch);
            $this->ch = null;
        }
    }
}
