<?php

declare(strict_types=1);

namespace Drupal\Tests\mcp_gateway\Functional;

use Drupal\Core\Url;
use Drupal\mcp_gateway\TokenRepository;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Http\Message\ResponseInterface;

/**
 * End-to-end tests of the HTTP endpoint, token auth and admin UI.
 */
#[Group('mcp_gateway')]
#[RunTestsInSeparateProcesses]
final class McpEndpointTest extends BrowserTestBase {

  protected static $modules = ['node', 'mcp_gateway'];

  protected $defaultTheme = 'stark';

  /**
   * Posts a JSON-RPC message to the endpoint.
   */
  private function post(array|string $body, ?string $token = NULL, array $headers = []): ResponseInterface {
    $headers += ['Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream'];
    if ($token) {
      $headers['Authorization'] = 'Bearer ' . $token;
    }
    return $this->getHttpClient()->post(Url::fromRoute('mcp_gateway.endpoint', [], ['absolute' => TRUE])->toString(), [
      'headers' => $headers,
      'body' => is_string($body) ? $body : json_encode($body),
      'http_errors' => FALSE,
    ]);
  }

  /**
   * Decodes a JSON response body.
   */
  private static function decode(ResponseInterface $response): array {
    return json_decode((string) $response->getBody(), TRUE);
  }

  /**
   * Tests authentication.
   */
  public function testAuthentication(): void {
    $ping = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'];

    $response = $this->post($ping);
    $this->assertSame(401, $response->getStatusCode());
    $this->assertSame('Bearer realm="mcp"', $response->getHeaderLine('WWW-Authenticate'));

    $this->assertSame(401, $this->post($ping, 'mcp_' . str_repeat('a', 48))->getStatusCode());

    $no_permission = $this->drupalCreateUser(['access content']);
    $token = $this->container->get(TokenRepository::class)->generate((int) $no_permission->id(), 'test');
    $this->assertSame(403, $this->post($ping, $token)->getStatusCode());

    // Blocked users are locked out even with a valid token.
    $user = $this->drupalCreateUser(['use mcp gateway']);
    $token = $this->container->get(TokenRepository::class)->generate((int) $user->id(), 'test');
    $this->assertSame(200, $this->post($ping, $token)->getStatusCode());
    $user->block()->save();
    $this->assertSame(401, $this->post($ping, $token)->getStatusCode());
  }

  /**
   * Tests full session.
   */
  public function testFullSession(): void {
    $this->drupalCreateContentType(['type' => 'article', 'name' => 'Article']);
    $this->drupalCreateNode(['type' => 'article', 'title' => 'Grant reporting FAQ', 'status' => 1]);
    $user = $this->drupalCreateUser([
      'use mcp gateway', 'access content', 'create article content',
      'use mcp tool search_content', 'use mcp tool create_draft', 'use mcp tool site_info',
    ]);
    $token = $this->container->get(TokenRepository::class)->generate((int) $user->id(), 'test');

    $init = $this->post(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
      'protocolVersion' => '2025-11-25', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'phpunit', 'version' => '1.0'],
    ]], $token);
    $this->assertSame(200, $init->getStatusCode());
    $this->assertStringStartsWith('application/json', $init->getHeaderLine('Content-Type'));
    $this->assertSame('2025-11-25', self::decode($init)['result']['protocolVersion']);

    $notification = $this->post(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $token);
    $this->assertSame(202, $notification->getStatusCode());
    $this->assertSame('', (string) $notification->getBody());

    $tools = array_column(self::decode($this->post(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], $token))['result']['tools'], 'name');
    $this->assertEqualsCanonicalizing(['search_content', 'create_draft', 'site_info'], $tools);

    $search = self::decode($this->post(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => [
      'name' => 'search_content', 'arguments' => ['query' => 'grant'],
    ]], $token));
    $this->assertSame('Grant reporting FAQ', $search['result']['structuredContent']['results'][0]['title']);

    $draft = self::decode($this->post(['jsonrpc' => '2.0', 'id' => 'abc', 'method' => 'tools/call', 'params' => [
      'name' => 'create_draft', 'arguments' => ['type' => 'article', 'title' => 'Written by Claude'],
    ]], $token));
    $this->assertSame('abc', $draft['id']);
    $this->assertFalse($draft['result']['isError']);
    $this->assertSame('unpublished', $draft['result']['structuredContent']['status']);

    $this->assertSame(-32700, self::decode($this->post('{not json', $token))['error']['code']);

    // Browser requests from foreign origins are refused.
    $foreign = $this->post(['jsonrpc' => '2.0', 'id' => 9, 'method' => 'ping'], $token, ['Origin' => 'https://evil.example']);
    $this->assertSame(403, $foreign->getStatusCode());
  }

  /**
   * Tests token ui and audit report.
   */
  public function testTokenUiAndAuditReport(): void {
    $user = $this->drupalCreateUser(['use mcp gateway', 'use mcp tool site_info']);
    $this->drupalLogin($user);

    $this->drupalGet('user/' . $user->id() . '/mcp-tokens');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('No tokens yet.');
    $this->submitForm(['label' => 'Claude Desktop', 'lifetime' => '30'], 'Generate token');
    $this->assertSession()->pageTextContains('Copy your new token now');
    $this->assertSession()->pageTextContains('claude mcp add --transport http drupal');

    $token = $this->getSession()->getPage()->find('css', 'code.mcp-token')->getText();
    $this->assertMatchesRegularExpression('/^mcp_[0-9a-f]{48}$/', $token);
    $this->assertSame(200, $this->post(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'site_info']], $token)->getStatusCode());

    // Users cannot open someone else's token page.
    $other = $this->drupalCreateUser(['use mcp gateway']);
    $this->drupalGet('user/' . $other->id() . '/mcp-tokens');
    $this->assertSession()->statusCodeEquals(403);

    // Revoking kills the token.
    $this->drupalGet('user/' . $user->id() . '/mcp-tokens');
    $this->assertSession()->pageTextContains('Claude Desktop');
    $checkbox = $this->getSession()->getPage()->find('css', 'input[name^="existing["]');
    $checkbox->check();
    $this->submitForm([], 'Revoke selected');
    $this->assertSession()->pageTextContains('Revoked 1 token.');
    $this->assertSame(401, $this->post(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], $token)->getStatusCode());

    // The admin sees the call in the audit log.
    $this->drupalLogin($this->drupalCreateUser(['view mcp audit log', 'administer mcp gateway']));
    $this->drupalGet('admin/reports/mcp-audit');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('site_info');
    $this->assertSession()->pageTextContains($user->getAccountName());

    $this->drupalGet('admin/config/services/mcp-gateway');
    $this->assertSession()->statusCodeEquals(200);
    $this->submitForm(['enabled_tools[create_draft]' => FALSE, 'retention_days' => '30'], 'Save configuration');
    $this->assertSession()->pageTextContains('The configuration options have been saved.');
    $config = $this->config('mcp_gateway.settings');
    $this->assertNotContains('create_draft', $config->get('enabled_tools'));
    $this->assertSame(30, $config->get('audit.retention_days'));
  }

}
