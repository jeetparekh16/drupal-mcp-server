<?php

declare(strict_types=1);

namespace Drupal\mcp_gateway;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\mcp_gateway\Attribute\McpTool;

/**
 * Discovers MCP tool plugins in any module's src/Plugin/McpTool directory.
 */
class McpToolPluginManager extends DefaultPluginManager {

  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct('Plugin/McpTool', $namespaces, $module_handler, McpToolInterface::class, McpTool::class);
    $this->alterInfo('mcp_tool_info');
    $this->setCacheBackend($cache_backend, 'mcp_tool_plugins');
  }

  /**
   * {@inheritdoc}
   */
  public function processDefinition(&$definition, $plugin_id): void {
    parent::processDefinition($definition, $plugin_id);
    if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', $plugin_id)) {
      throw new \InvalidArgumentException(sprintf('MCP tool ID "%s" must be lowercase letters, digits and underscores.', $plugin_id));
    }
  }

}
