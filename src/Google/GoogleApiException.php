<?php

declare(strict_types=1);

namespace App\Google;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface as HttpStatusExceptionInterface;

final class GoogleApiException extends \RuntimeException
{
    /** Adds Google's own error message (from the JSON error body) to $context. */
    public static function fromHttp(string $context, HttpExceptionInterface $e): self
    {
        $detail = $e->getMessage();

        if ($e instanceof HttpStatusExceptionInterface) {
            try {
                $body = $e->getResponse()->toArray(false);
                $detail = $body['error']['message'] ?? $body['error_description'] ?? $body['error'] ?? $detail;
                if (\is_array($detail)) {
                    $detail = json_encode($detail);
                }
            } catch (\Throwable) {
                // Not JSON: keep the generic message
            }
        }

        return new self(\sprintf('%s: %s', $context, $detail), 0, $e);
    }
}
