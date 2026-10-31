<?php

declare(strict_types=1);

namespace Drupal\mcp_gateway\Server;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\mcp_gateway\AuditLogger;
use Drupal\mcp_gateway\Exception\McpToolException;
use Drupal\mcp_gateway\McpToolInterface;
use Drupal\mcp_gateway\McpToolPluginManager;
use Psr\Log\LoggerInterface;

/**
 * Transport-independent MCP server: handles one JSON-RPC 2.0 message.
 *
 * Implements the server side of the Model Context Protocol lifecycle
 * (initialize, ping) and the tools capability (tools/list, tools/call).
 *
 * @see https://modelcontextprotocol.io/specification
 */
final class McpServer {

  public const SERVER_NAME = 'drupal-mcp-gateway';
  public const SERVER_VERSION = '1.0.0';

  /**
   * Protocol revisions this server speaks, newest first.
   */
  public const SUPPORTED_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26'];

  // JSON-RPC 2.0 error codes.
  public const PARSE_ERROR = -32700;
  public const INVALID_REQUEST = -32600;
  public const METHOD_NOT_FOUND = -32601;
  public const INVALID_PARAMS = -32602;
  public const INTERNAL_ERROR = -32603;

  public function __construct(
    private readonly McpToolPluginManager $toolManager,
    private readonly ArgumentValidator $validator,
    private readonly AuditLogger $auditLogger,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Handles one decoded JSON-RPC message.
   *
   * @param mixed $message
   *   The decoded request body.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user the AI assistant acts as.
   *
   * @return array<string, mixed>|null
   *   The JSON-RPC response, or NULL for notifications (no reply expected).
   */
  public function handle(mixed $message, AccountInterface $account): ?array {
    if (!is_array($message) || array_is_list($message)) {
      // Batching was removed from MCP in revision 2025-06-18.
      return self::error(NULL, self::INVALID_REQUEST, 'Expected a single JSON-RPC request object.');
    }
    $id = $message['id'] ?? NULL;
    if (($message['jsonrpc'] ?? NULL) !== '2.0' || !is_string($message['method'] ?? NULL)) {
      return self::error($id, self::INVALID_REQUEST, 'Invalid JSON-RPC 2.0 request.');
    }
    if ($id !== NULL && !is_string($id) && !is_int($id)) {
      return self::error(NULL, self::INVALID_REQUEST, 'Request id must be a string or integer.');
    }

    $method = $message['method'];
    $params = $message['params'] ?? [];
    if (!is_array($params)) {
      return self::error($id, self::INVALID_PARAMS, 'params must be an object.');
    }

    // Notifications (no id) never get a response.
    if (!array_key_exists('id', $message)) {
      return NULL;
    }

    return match ($method) {
      'initialize' => self::result($id, $this->initialize($params)),
      'ping' => self::result($id, new \stdClass()),
      'tools/list' => self::result($id, ['tools' => $this->listTools($account)]),
      'tools/call' => $this->callTool($id, $params, $account),
      default => self::error($id, self::METHOD_NOT_FOUND, sprintf('Method "%s" is not supported.', $method)),
    };
  }

  /**
   * Builds the initialize result: negotiated version, capabilities, info.
   *
   * @return array<string, mixed>
   *   The initialize result.
   */
  private function initialize(array $params): array {
    $requested = $params['protocolVersion'] ?? NULL;
    $version = in_array($requested, self::SUPPORTED_VERSIONS, TRUE) ? $requested : self::SUPPORTED_VERSIONS[0];
    $site = (string) $this->configFactory->get('system.site')->get('name');

    return [
      'protocolVersion' => $version,
      'capabilities' => ['tools' => ['listChanged' => FALSE]],
      'serverInfo' => [
        'name' => self::SERVER_NAME,
        'title' => $site !== '' ? $site . ' (Drupal)' : 'Drupal',
        'version' => self::SERVER_VERSION,
      ],
      'instructions' => sprintf(
        'You are connected to the Drupal site "%s" as a specific Drupal user. Every tool enforces that user\'s permissions. '
        . 'Call site_info first. Use search_content then get_node to read content. New content created with create_draft '
        . 'is always unpublished until a human editor publishes it. All tool calls are audited.',
        $site,
      ),
    ];
  }

  /**
   * Tools enabled by the admin that this account may use.
   *
   * @return list<array<string, mixed>>
   *   MCP tool descriptors.
   */
  private function listTools(AccountInterface $account): array {
    $tools = [];
    foreach ($this->availableTools() as $tool) {
      if (!$tool->access($account)->isAllowed()) {
        continue;
      }
      $tools[] = [
        'name' => $tool->getName(),
        'title' => $tool->getTitle(),
        'description' => $tool->getDescription(),
        'inputSchema' => $tool->getInputSchema(),
        'annotations' => [
          'title' => $tool->getTitle(),
          'readOnlyHint' => $tool->isReadOnly(),
          'destructiveHint' => FALSE,
          'idempotentHint' => $tool->isIdempotent(),
          'openWorldHint' => FALSE,
        ],
      ];
    }
    return $tools;
  }

  /**
   * Runs a tool call with permission checks, validation and auditing.
   *
   * @return array<string, mixed>
   *   The JSON-RPC response.
   */
  private function callTool(string|int $id, array $params, AccountInterface $account): array {
    $name = $params['name'] ?? NULL;
    $arguments = $params['arguments'] ?? [];
    if (!is_string($name) || $name === '') {
      return self::error($id, self::INVALID_PARAMS, 'tools/call requires a "name".');
    }
    if (!is_array($arguments) || ($arguments !== [] && array_is_list($arguments))) {
      return self::error($id, self::INVALID_PARAMS, '"arguments" must be an object.');
    }

    $tools = $this->availableTools();
    if (!isset($tools[$name])) {
      return self::error($id, self::INVALID_PARAMS, sprintf('Unknown tool "%s".', $name));
    }
    $tool = $tools[$name];
    $started = hrtime(TRUE);

    if (!$tool->access($account)->isAllowed()) {
      $this->auditLogger->log($account, $name, $arguments, AuditLogger::DENIED, 'Missing tool permission', $started);
      return self::result($id, self::toolError(sprintf('Permission denied: your Drupal account may not use the "%s" tool.', $name)));
    }

    $errors = $this->validator->validate($arguments, $tool->getInputSchema());
    if ($errors) {
      $this->auditLogger->log($account, $name, $arguments, AuditLogger::INVALID, implode(' ', $errors), $started);
      return self::result($id, self::toolError('Invalid arguments: ' . implode(' ', $errors)));
    }

    try {
      $data = $tool->execute($arguments, $account);
    }
    catch (McpToolException $e) {
      $outcome = $e->reason === McpToolException::ACCESS_DENIED ? AuditLogger::DENIED : AuditLogger::INVALID;
      $this->auditLogger->log($account, $name, $arguments, $outcome, $e->getMessage(), $started);
      return self::result($id, self::toolError($e->getMessage()));
    }
    catch (\Throwable $e) {
      $this->logger->error('MCP tool @tool failed: @message', ['@tool' => $name, '@message' => $e->getMessage()]);
      $this->auditLogger->log($account, $name, $arguments, AuditLogger::ERROR, $e->getMessage(), $started);
      return self::result($id, self::toolError('The tool failed with an internal error. The site administrator can see details in the Drupal log.'));
    }

    $this->auditLogger->log($account, $name, $arguments, AuditLogger::SUCCESS, '', $started);
    return self::result($id, [
      'content' => [[
        'type' => 'text',
        'text' => json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
      ]],
      'structuredContent' => $data,
      'isError' => FALSE,
    ]);
  }

  /**
   * Instantiated tools that the site administrator has enabled.
   *
   * @return array<string, \Drupal\mcp_gateway\McpToolInterface>
   *   Tools keyed by name.
   */
  private function availableTools(): array {
    $enabled = (array) $this->configFactory->get('mcp_gateway.settings')->get('enabled_tools');
    $tools = [];
    foreach ($this->toolManager->getDefinitions() as $tool_id => $definition) {
      if (in_array($tool_id, $enabled, TRUE)) {
        $tool = $this->toolManager->createInstance($tool_id);
        assert($tool instanceof McpToolInterface);
        $tools[$tool_id] = $tool;
      }
    }
    return $tools;
  }

  /**
   * Builds a tool result the model sees as an error it can react to.
   *
   * @return array<string, mixed>
   *   The tool result.
   */
  private static function toolError(string $message): array {
    return ['content' => [['type' => 'text', 'text' => $message]], 'isError' => TRUE];
  }

  /**
   * Builds a JSON-RPC success response.
   *
   * @return array<string, mixed>
   *   The response.
   */
  public static function result(string|int|null $id, mixed $result): array {
    return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
  }

  /**
   * Builds a JSON-RPC error response.
   *
   * @return array<string, mixed>
   *   The response.
   */
  public static function error(string|int|null $id, int $code, string $message): array {
    return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
  }

}
