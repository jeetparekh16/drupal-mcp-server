<?php

declare(strict_types=1);

namespace Drupal\mcp_gateway;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * Stores and verifies personal MCP access tokens.
 *
 * Tokens are 192-bit random values prefixed with "mcp_". Only a SHA-256 hash
 * is stored, so a database leak does not expose usable tokens; the plain
 * token is shown to the user once, when it is generated.
 */
class TokenRepository {

  public const PREFIX = 'mcp_';
  private const TABLE = 'mcp_gateway_token';

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Creates a token and returns the plain value (shown to the user once).
   */
  public function generate(int $uid, string $label, int $expires = 0): string {
    $token = self::PREFIX . bin2hex(random_bytes(24));
    $this->database->insert(self::TABLE)
      ->fields([
        'uid' => $uid,
        'label' => mb_substr($label, 0, 128),
        'token_hash' => self::hash($token),
        'token_hint' => substr($token, -6),
        'created' => $this->time->getRequestTime(),
        'expires' => $expires,
      ])
      ->execute();
    return $token;
  }

  /**
   * Returns the user ID that owns a valid token, or NULL.
   */
  public function verify(string $token): ?int {
    if (!str_starts_with($token, self::PREFIX) || strlen($token) !== strlen(self::PREFIX) + 48) {
      return NULL;
    }
    $row = $this->database->select(self::TABLE, 't')
      ->fields('t', ['id', 'uid', 'expires', 'last_used'])
      ->condition('token_hash', self::hash($token))
      ->execute()
      ->fetchAssoc();
    if (!$row) {
      return NULL;
    }
    $now = $this->time->getRequestTime();
    if ((int) $row['expires'] !== 0 && (int) $row['expires'] < $now) {
      return NULL;
    }
    // Write at most once a minute to keep the hot path cheap.
    if ($now - (int) $row['last_used'] > 60) {
      $this->database->update(self::TABLE)->fields(['last_used' => $now])->condition('id', $row['id'])->execute();
    }
    return (int) $row['uid'];
  }

  /**
   * Lists a user's tokens (never the token values themselves).
   *
   * @return list<array<string, mixed>>
   *   Token metadata rows.
   */
  public function listForUser(int $uid): array {
    $rows = $this->database->select(self::TABLE, 't')
      ->fields('t', ['id', 'label', 'token_hint', 'created', 'last_used', 'expires'])
      ->condition('uid', $uid)
      ->orderBy('created', 'DESC')
      ->orderBy('id', 'DESC')
      ->execute()
      ->fetchAll();
    return array_map(fn(object $row): array => (array) $row, $rows);
  }

  /**
   * Revokes one token owned by a user.
   */
  public function revoke(int $id, int $uid): bool {
    return (bool) $this->database->delete(self::TABLE)->condition('id', $id)->condition('uid', $uid)->execute();
  }

  /**
   * Revokes all tokens of a user.
   */
  public function revokeAllForUser(int $uid): int {
    return (int) $this->database->delete(self::TABLE)->condition('uid', $uid)->execute();
  }

  /**
   * Hashes a token for storage.
   */
  private static function hash(string $token): string {
    return hash('sha256', $token);
  }

}
