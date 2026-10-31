<?php

declare(strict_types=1);

namespace Drupal\mcp_gateway\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\mcp_gateway\Server\McpServer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * MCP Streamable HTTP endpoint (POST /mcp).
 *
 * Each POST carries one JSON-RPC message. Requests get an application/json
 * reply; notifications get "202 Accepted". The server does not open SSE
 * streams, which the transport spec allows.
 */
final class McpController implements ContainerInjectionInterface {

  use AutowireTrait;

  public function __construct(
    private readonly McpServer $server,
    private readonly AccountProxyInterface $currentUser,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Handles one MCP request.
   */
  public function handle(Request $request): Response {
    if (!$this->originAllowed($request)) {
      return $this->json(McpServer::error(NULL, McpServer::INVALID_REQUEST, 'Origin not allowed.'), 403);
    }

    if ($this->currentUser->isAnonymous()) {
      $response = $this->json(McpServer::error(NULL, McpServer::INVALID_REQUEST, 'Authentication required. Send "Authorization: Bearer <token>" with a token created at /user/{uid}/mcp-tokens.'), 401);
      $response->headers->set('WWW-Authenticate', 'Bearer realm="mcp"');
      return $response;
    }
    if (!$this->currentUser->hasPermission('use mcp gateway')) {
      return $this->json(McpServer::error(NULL, McpServer::INVALID_REQUEST, 'Your Drupal account is not allowed to use MCP.'), 403);
    }

    try {
      $message = json_decode((string) $request->getContent(), TRUE, 64, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return $this->json(McpServer::error(NULL, McpServer::PARSE_ERROR, 'Request body is not valid JSON.'), 400);
    }

    $reply = $this->server->handle($message, $this->currentUser->getAccount());
    if ($reply === NULL) {
      return new Response('', 202);
    }
    return $this->json($reply);
  }

  /**
   * Checks the Origin header to guard against DNS rebinding.
   *
   * Browser requests must come from this site or an origin the admin allowed.
   * Non-browser clients (Claude Desktop, Claude Code, scripts) send no Origin.
   */
  private function originAllowed(Request $request): bool {
    $origin = $request->headers->get('Origin');
    if ($origin === NULL || $origin === $request->getSchemeAndHttpHost()) {
      return TRUE;
    }
    $allowed = (array) $this->configFactory->get('mcp_gateway.settings')->get('allowed_origins');
    return in_array(rtrim($origin, '/'), array_map(fn($o) => rtrim((string) $o, '/'), $allowed), TRUE);
  }

  /**
   * Builds a JSON response that is never cached.
   */
  private function json(array $data, int $status = 200): JsonResponse {
    $response = new JsonResponse($data, $status);
    $response->setEncodingOptions(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $response->headers->set('Cache-Control', 'no-store');
    return $response;
  }

}
