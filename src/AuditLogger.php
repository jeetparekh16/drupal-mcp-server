<?php

declare(strict_types=1);

namespace Drupal\mcp_gateway;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Records every MCP tool call: who, which tool, what arguments, what outcome.
 */
class AuditLogger {

  public const SUCCESS = 'success';
  public const DENIED = 'denied';
  public const INVALID = 'invalid';
  public const ERROR = 'error';

  private const TABLE = 'mcp_gateway_audit';

  /**
   * Long argument values (e.g. a full body) are shortened in the log.
   */
  private const MAX_VALUE_LENGTH = 300;

  public function __construct(
    private readonly Connection $database,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * Records one tool call.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user the assistant acted as.
   * @param string $tool
   *   Tool name.
   * @param array $arguments
   *   Arguments sent by the client.
   * @param string $outcome
   *   One of the class constants: success, denied, invalid or error.
   * @param string $message
   *   Error or denial message; empty on success.
   * @param int $started
   *   The hrtime(TRUE) value captured when the call started.
   */
  public function log(AccountInterface $account, string $tool, array $arguments, string $outcome, string $message, int $started): void {
    if (!$this->configFactory->get('mcp_gateway.settings')->get('audit.enabled')) {
      return;
    }
    $this->database->insert(self::TABLE)
      ->fields([
        'uid' => (int) $account->id(),
        'tool' => mb_substr($tool, 0, 64),
        'arguments' => json_encode($this->shorten($arguments), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'outcome' => $outcome,
        'message' => mb_substr($message, 0, 255),
        'duration_ms' => (int) round((hrtime(TRUE) - $started) / 1e6),
        'ip' => (string) $this->requestStack->getCurrentRequest()?->getClientIp(),
        'created' => $this->time->getRequestTime(),
      ])
      ->execute();
  }

  /**
   * Deletes records older than the configured retention period.
   */
  public function purgeExpired(): int {
    $days = (int) $this->configFactory->get('mcp_gateway.settings')->get('audit.retention_days');
    if ($days <= 0) {
      return 0;
    }
    return (int) $this->database->delete(self::TABLE)
      ->condition('created', $this->time->getRequestTime() - $days * 86400, '<')
      ->execute();
  }

  /**
   * Shortens long string values for the log.
   */
  private function shorten(array $arguments): array {
    array_walk_recursive($arguments, function (&$value): void {
      if (is_string($value) && mb_strlen($value) > self::MAX_VALUE_LENGTH) {
        $value = mb_substr($value, 0, self::MAX_VALUE_LENGTH) . '… [' . mb_strlen($value) . ' chars]';
      }
    });
    return $arguments;
  }

}
