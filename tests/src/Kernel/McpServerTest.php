<?php

declare(strict_types=1);

namespace Drupal\Tests\mcp_gateway\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\mcp_gateway\Server\McpServer;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the MCP server: protocol handling, tools and permission enforcement.
 */
#[Group('mcp_gateway')]
#[RunTestsInSeparateProcesses]
final class McpServerTest extends KernelTestBase {

  use UserCreationTrait;

  protected static $modules = ['system', 'user', 'node', 'field', 'filter', 'text', 'mcp_gateway'];

  private McpServer $server;
  private UserInterface $reader;
  private UserInterface $editor;
  private UserInterface $outsider;
  private NodeInterface $published;
  private NodeInterface $unpublished;
  private int $requestId = 0;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('mcp_gateway', ['mcp_gateway_token', 'mcp_gateway_audit']);
    $this->installConfig(['system', 'filter', 'node', 'mcp_gateway']);
    $this->config('system.site')->set('name', 'Test Health Site')->save();

    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    NodeType::create(['type' => 'page', 'name' => 'Basic page'])->save();
    FieldStorageConfig::create(['field_name' => 'body', 'entity_type' => 'node', 'type' => 'text_long'])->save();
    foreach (['article', 'page'] as $bundle) {
      FieldConfig::create(['field_name' => 'body', 'entity_type' => 'node', 'bundle' => $bundle, 'label' => 'Body'])->save();
    }

    // UID 1 is a superuser; burn it so test users are ordinary accounts.
    $this->createUser();

    $read_tools = ['use mcp gateway', 'access content', 'use mcp tool site_info', 'use mcp tool search_content', 'use mcp tool get_node', 'use mcp tool list_content_types'];
    $this->reader = $this->createUser($read_tools, 'reader');
    $this->editor = $this->createUser(array_merge($read_tools, [
      'use mcp tool create_draft',
      'use mcp tool update_node',
      'create article content',
      'edit own article content',
      'view own unpublished content',
    ]), 'editor');
    $this->outsider = $this->createUser(['access content'], 'outsider');

    $this->published = Node::create([
      'type' => 'article',
      'title' => 'Opioid treatment locator guide',
      'body' => ['value' => '<p>How states can find <strong>treatment</strong> providers.</p>', 'format' => 'plain_text'],
      'uid' => $this->editor->id(),
      'status' => 1,
    ]);
    $this->published->save();
    $this->unpublished = Node::create([
      'type' => 'article',
      'title' => 'Secret opioid budget draft',
      'uid' => $this->editor->id(),
      'status' => 0,
    ]);
    $this->unpublished->save();

    $this->server = $this->container->get(McpServer::class);
  }

  /**
   * Sends a JSON-RPC request to the server.
   */
  private function rpc(string $method, array $params, UserInterface $user): array {
    return $this->server->handle(['jsonrpc' => '2.0', 'id' => ++$this->requestId, 'method' => $method, 'params' => $params], $user);
  }

  /**
   * Calls a tool and returns the MCP result.
   */
  private function call(string $tool, array $arguments, UserInterface $user): array {
    $response = $this->rpc('tools/call', ['name' => $tool, 'arguments' => $arguments], $user);
    $this->assertArrayHasKey('result', $response, json_encode($response));
    return $response['result'];
  }

  /**
   * Tests initialize negotiates version.
   */
  public function testInitializeNegotiatesVersion(): void {
    $result = $this->rpc('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1']], $this->reader)['result'];
    $this->assertSame('2025-06-18', $result['protocolVersion']);
    $this->assertSame('drupal-mcp-gateway', $result['serverInfo']['name']);
    $this->assertSame('Test Health Site (Drupal)', $result['serverInfo']['title']);
    $this->assertArrayHasKey('tools', $result['capabilities']);

    $unknown = $this->rpc('initialize', ['protocolVersion' => '1999-01-01'], $this->reader)['result'];
    $this->assertSame(McpServer::SUPPORTED_VERSIONS[0], $unknown['protocolVersion']);
  }

  /**
   * Tests protocol errors.
   */
  public function testProtocolErrors(): void {
    $this->assertNull($this->server->handle(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $this->reader));
    $this->assertSame(McpServer::METHOD_NOT_FOUND, $this->rpc('resources/list', [], $this->reader)['error']['code']);
    $this->assertSame(McpServer::INVALID_REQUEST, $this->server->handle(['id' => 1, 'method' => 'ping'], $this->reader)['error']['code']);
    $this->assertSame(McpServer::INVALID_REQUEST, $this->server->handle([['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']], $this->reader)['error']['code']);
    $this->assertSame(McpServer::INVALID_PARAMS, $this->rpc('tools/call', ['name' => 'no_such_tool'], $this->reader)['error']['code']);
    $this->assertEquals(new \stdClass(), $this->rpc('ping', [], $this->reader)['result']);
  }

  /**
   * Tests that tool list depends on permissions.
   */
  public function testToolListDependsOnPermissions(): void {
    $names = fn(UserInterface $u) => array_column($this->rpc('tools/list', [], $u)['result']['tools'], 'name');

    $this->assertEqualsCanonicalizing(['site_info', 'list_content_types', 'search_content', 'get_node'], $names($this->reader));
    $this->assertContains('create_draft', $names($this->editor));
    $this->assertSame([], $names($this->outsider));

    // An admin-disabled tool disappears for everyone.
    $this->config('mcp_gateway.settings')->set('enabled_tools', ['site_info', 'get_node'])->save();
    $this->assertEqualsCanonicalizing(['site_info', 'get_node'], $names($this->editor));
    $this->assertSame(McpServer::INVALID_PARAMS, $this->rpc('tools/call', ['name' => 'search_content'], $this->editor)['error']['code']);
  }

  /**
   * Tests tool annotations.
   */
  public function testToolAnnotations(): void {
    $tools = array_column($this->rpc('tools/list', [], $this->editor)['result']['tools'], NULL, 'name');
    $this->assertTrue($tools['search_content']['annotations']['readOnlyHint']);
    $this->assertFalse($tools['create_draft']['annotations']['readOnlyHint']);
    $this->assertSame(['nid'], $tools['get_node']['inputSchema']['required']);
  }

  /**
   * Tests site info.
   */
  public function testSiteInfo(): void {
    $data = $this->call('site_info', [], $this->reader)['structuredContent'];
    $this->assertSame('Test Health Site', $data['site_name']);
    $this->assertSame('reader', $data['acting_as']['name']);
    // The reader cannot see the unpublished article, so it is not counted.
    $this->assertSame(1, $data['content_counts']['article']);
  }

  /**
   * Tests that search hides content the user cannot see.
   */
  public function testSearchHidesContentTheUserCannotSee(): void {
    $reader_results = $this->call('search_content', ['query' => 'opioid'], $this->reader)['structuredContent']['results'];
    $this->assertSame(['Opioid treatment locator guide'], array_column($reader_results, 'title'));
    $this->assertSame('How states can find treatment providers.', $reader_results[0]['summary']);

    // The author may see their own draft.
    $editor_results = $this->call('search_content', ['query' => 'opioid'], $this->editor)['structuredContent']['results'];
    $this->assertCount(2, $editor_results);

    // Body text is searched too.
    $body_match = $this->call('search_content', ['query' => 'providers'], $this->reader)['structuredContent'];
    $this->assertSame(1, $body_match['count']);

    $filtered = $this->call('search_content', ['status' => 'unpublished'], $this->editor)['structuredContent'];
    $this->assertSame(['Secret opioid budget draft'], array_column($filtered['results'], 'title'));
  }

  /**
   * Tests that get node does not leak hidden content.
   */
  public function testGetNodeDoesNotLeakHiddenContent(): void {
    $result = $this->call('get_node', ['nid' => (int) $this->published->id()], $this->reader);
    $this->assertFalse($result['isError']);
    $this->assertSame('How states can find treatment providers.', $result['structuredContent']['body']);

    $hidden = $this->call('get_node', ['nid' => (int) $this->unpublished->id()], $this->reader);
    $missing = $this->call('get_node', ['nid' => 9999], $this->reader);
    $this->assertTrue($hidden['isError']);
    $this->assertStringContainsString('not found, or you do not have access', $hidden['content'][0]['text']);
    // Hidden and nonexistent nodes are indistinguishable.
    $this->assertSame(
      str_replace((string) $this->unpublished->id(), 'N', $hidden['content'][0]['text']),
      str_replace('9999', 'N', $missing['content'][0]['text']),
    );
  }

  /**
   * Tests that create draft is always unpublished.
   */
  public function testCreateDraftIsAlwaysUnpublished(): void {
    $result = $this->call('create_draft', ['type' => 'article', 'title' => 'AI written summary', 'body' => '<p>Draft text</p>'], $this->editor);
    $this->assertFalse($result['isError'], $result['content'][0]['text']);
    $node = Node::load($result['structuredContent']['nid']);
    $this->assertFalse($node->isPublished());
    $this->assertSame((int) $this->editor->id(), (int) $node->getOwnerId());
    $this->assertStringContainsString('via MCP', $node->getRevisionLogMessage());
    $this->assertSame('<p>Draft text</p>', $node->get('body')->value);
  }

  /**
   * Tests that create draft respects content type permissions.
   */
  public function testCreateDraftRespectsContentTypePermissions(): void {
    $result = $this->call('create_draft', ['type' => 'page', 'title' => 'Not allowed'], $this->editor);
    $this->assertTrue($result['isError']);
    $this->assertStringContainsString('not allowed to create "page"', $result['content'][0]['text']);

    $bad_type = $this->call('create_draft', ['type' => 'nope', 'title' => 'x'], $this->editor);
    $this->assertStringContainsString('does not exist', $bad_type['content'][0]['text']);

    // The reader lacks the tool permission entirely.
    $denied = $this->call('create_draft', ['type' => 'article', 'title' => 'x'], $this->reader);
    $this->assertTrue($denied['isError']);
    $this->assertStringContainsString('Permission denied', $denied['content'][0]['text']);
  }

  /**
   * Tests that update node creates revision and checks ownership.
   */
  public function testUpdateNodeCreatesRevisionAndChecksOwnership(): void {
    $nid = (int) $this->published->id();
    $old_revision = (int) $this->published->getRevisionId();
    $result = $this->call('update_node', ['nid' => $nid, 'title' => 'Treatment locator guide (2026)', 'revision_log' => 'Year update'], $this->editor);
    $this->assertFalse($result['isError'], $result['content'][0]['text']);
    $this->assertSame(['title'], $result['structuredContent']['changed_fields']);

    $node = Node::load($nid);
    $this->assertSame('Treatment locator guide (2026)', $node->label());
    $this->assertGreaterThan($old_revision, (int) $node->getRevisionId());
    $this->assertTrue($node->isPublished(), 'Publication status is unchanged.');

    // Someone else's content cannot be edited, even with the tool permission.
    $other = $this->createUser(array_merge(['use mcp gateway', 'use mcp tool update_node', 'access content', 'edit own article content']));
    $denied = $this->call('update_node', ['nid' => $nid, 'title' => 'Hijacked'], $other);
    $this->assertTrue($denied['isError']);
    $this->assertStringContainsString('not allowed to update', $denied['content'][0]['text']);
    $this->assertSame('Treatment locator guide (2026)', Node::load($nid)->label());
  }

  /**
   * Tests that argument validation errors reach the model.
   */
  public function testArgumentValidationErrorsReachTheModel(): void {
    $result = $this->call('get_node', ['nid' => 'five'], $this->reader);
    $this->assertTrue($result['isError']);
    $this->assertStringContainsString('must be of type integer', $result['content'][0]['text']);
  }

  /**
   * Tests that every call is audited.
   */
  public function testEveryCallIsAudited(): void {
    $this->call('search_content', ['query' => 'opioid'], $this->reader);
    $this->call('create_draft', ['type' => 'article', 'title' => 'x'], $this->reader);
    $this->call('get_node', ['nid' => 'bad'], $this->reader);
    $this->call('create_draft', ['type' => 'article', 'title' => 'Long', 'body' => str_repeat('a', 1000)], $this->editor);

    $rows = $this->container->get('database')->select('mcp_gateway_audit', 'a')->fields('a')->orderBy('id')->execute()->fetchAll();
    $this->assertSame(['success', 'denied', 'invalid', 'success'], array_column($rows, 'outcome'));
    $this->assertSame((string) $this->reader->id(), (string) $rows[0]->uid);
    $this->assertSame('{"query":"opioid"}', $rows[0]->arguments);
    $this->assertStringContainsString('[1000 chars]', $rows[3]->arguments, 'Long values are shortened.');

    $this->config('mcp_gateway.settings')->set('audit.enabled', FALSE)->save();
    $this->call('site_info', [], $this->reader);
    $this->assertCount(4, $this->container->get('database')->select('mcp_gateway_audit', 'a')->fields('a')->execute()->fetchAll());
  }

}
