<?php

declare(strict_types=1);

namespace Drupal\mcp_gateway\Exception;

/**
 * An error the AI assistant should see, returned as an MCP tool error.
 *
 * Use this for expected failures the model can react to: a missing node,
 * missing permission or invalid input. Unexpected exceptions are logged and
 * reported to the client only as a generic error, so internals never leak.
 */
class McpToolException extends \RuntimeException {

  public const NOT_FOUND = 'not_found';
  public const ACCESS_DENIED = 'access_denied';
  public const INVALID = 'invalid';

  public function __construct(string $message, public readonly string $reason = self::INVALID) {
    parent::__construct($message);
  }

  /**
   * Creates an exception for missing or hidden content.
   */
  public static function notFound(string $message): static {
    return new static($message, self::NOT_FOUND);
  }

  /**
   * Creates an exception for a permission failure.
   */
  public static function accessDenied(string $message): static {
    return new static($message, self::ACCESS_DENIED);
  }

}
