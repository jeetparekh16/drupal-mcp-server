<?php

declare(strict_types=1);

namespace Drupal\mcp_gateway\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\mcp_gateway\AuditLogger;
use Drupal\mcp_gateway\TokenRepository;
use Drupal\user\UserInterface;

/**
 * Hook implementations (Drupal 11 object-oriented hooks).
 */
final class McpGatewayHooks {

  use StringTranslationTrait;

  public function __construct(
    private readonly AuditLogger $auditLogger,
    private readonly TokenRepository $tokens,
  ) {}

  /**
   * Implements hook_cron(): purge audit records past the retention period.
   */
  #[Hook('cron')]
  public function cron(): void {
    $this->auditLogger->purgeExpired();
  }

  /**
   * Implements hook_user_delete(): a deleted user's tokens die with them.
   */
  #[Hook('user_delete')]
  public function userDelete(UserInterface $account): void {
    $this->tokens->revokeAllForUser((int) $account->id());
  }

  /**
   * Implements hook_help().
   */
  #[Hook('help')]
  public function help(string $route_name, RouteMatchInterface $route_match): ?string {
    if ($route_name !== 'help.page.mcp_gateway') {
      return NULL;
    }
    return '<p>' . $this->t('MCP Gateway turns this site into a <a href=":mcp">Model Context Protocol</a> server at <code>/mcp</code>. AI assistants authenticate with a personal token and act as that Drupal user, so roles, node access and field access all apply. Every call is recorded in the <a href=":audit">MCP audit log</a>.', [
      ':mcp' => 'https://modelcontextprotocol.io',
      ':audit' => Url::fromRoute('mcp_gateway.audit_log')->toString(),
    ]) . '</p>';
  }

}
