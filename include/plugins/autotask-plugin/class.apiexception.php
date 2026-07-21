<?php
/**
 * Autotask Integration — API exception type.
 *
 * @package Autotask Integration
 */

namespace Autotask;

if (!defined('INCLUDE_DIR')) {
    die('Access denied');
}

/**
 * Raised for any non-recoverable Autotask API failure. Carries the HTTP status
 * so callers (retry queue) can decide whether to back off or give up.
 */
class ApiException extends \RuntimeException
{
    /** @var int HTTP status code (0 for transport-level failures). */
    private $httpStatus;

    /** @var bool Whether retrying later might succeed. */
    private $retryable;

    public function __construct(string $message, int $httpStatus = 0, bool $retryable = false, ?\Throwable $prev = null)
    {
        parent::__construct($message, $httpStatus, $prev);
        $this->httpStatus = $httpStatus;
        $this->retryable  = $retryable;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }
}
