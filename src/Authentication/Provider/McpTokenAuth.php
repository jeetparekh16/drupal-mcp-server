<?php

declare(strict_types=1);

namespace Drupal\mcp_gateway\Authentication\Provider;

use Drupal\Core\Authentication\AuthenticationProviderInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\mcp_gateway\TokenRepository;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Authenticates "Authorization: Bearer mcp_..." requests as a Drupal user.
 *
 * Plugged into Drupal's authentication system, so the AI assistant acts as a
 * real user and every access check (roles, node grants, field access) applies
 * exactly as it would in the browser.
 */
final class McpTokenAuth implements AuthenticationProviderInterface {

  public function __construct(
    private readonly TokenRepository $tokens,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function applies(Request $request): bool {
    return self::bearerToken($request) !== NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function authenticate(Request $request): ?AccountInterface {
    $token = self::bearerToken($request);
    $uid = $token !== NULL ? $this->tokens->verify($token) : NULL;
    if ($uid === NULL) {
      return NULL;
    }
    $user = $this->entityTypeManager->getStorage('user')->load($uid);
    // Blocked users lose MCP access immediately, even with a valid token.
    return ($user instanceof UserInterface && $user->isActive()) ? $user : NULL;
  }

  /**
   * Extracts an MCP token from the Authorization header.
   */
  private static function bearerToken(Request $request): ?string {
    $header = (string) $request->headers->get('Authorization', '');
    if (preg_match('/^Bearer\s+(' . preg_quote(TokenRepository::PREFIX, '/') . '\S+)$/i', $header, $m)) {
      return $m[1];
    }
    return NULL;
  }

}
