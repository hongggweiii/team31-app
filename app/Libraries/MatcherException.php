<?php

namespace App\Libraries;

use RuntimeException;

/**
 * Why matching a gig failed, so the matches page can say something useful
 * instead of failing. The exception code is matcher/'s HTTP status (0 when it
 * never answered).
 */
final class MatcherException extends RuntimeException
{
    /** matcher/ or embedding-service is down, slow or erroring: worth retrying. */
    public const UNAVAILABLE = 'unavailable';

    /** matcher/ refused the request (422): retrying the same gig won't help. */
    public const REJECTED = 'rejected';

    /** matcher/ answered 200 with something that isn't a ranked list. */
    public const BAD_RESPONSE = 'bad_response';

    /**
     * @param string $reason  UNAVAILABLE, REJECTED or BAD_RESPONSE
     * @param string $message For the log (never shown to users)
     * @param int    $status  matcher/'s HTTP status; 0 when it never answered
     */
    public function __construct(private readonly string $reason, string $message, int $status = 0)
    {
        parent::__construct($message, $status);
    }

    /**
     * @return string One of the constants above
     */
    public function reason(): string
    {
        return $this->reason;
    }

    /**
     * @return bool Whether the page should offer "Try again" (everything but REJECTED)
     */
    public function retryable(): bool
    {
        return $this->reason !== self::REJECTED;
    }
}
