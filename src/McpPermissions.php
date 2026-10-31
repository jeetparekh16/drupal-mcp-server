<?php

declare(strict_types=1);

namespace Drupal\mcp_gateway;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Generates one permission per MCP tool.
 *
 * Each role then gets exactly the tools it should have: for example, editors
 * may draft content while reviewers may only search and read.
 */
final class McpPermissions implements ContainerInjectionInterface {

  use StringTranslationTrait;

  public function __construct(private readonly McpToolPluginManager $toolManager) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('plugin.manager.mcp_tool'));
  }

  /**
   * Permission callback: one permission per discovered tool.
   *
   * @return array<string, array<string, mixed>>
   *   Permission definitions.
   */
  public function toolPermissions(): array {
    $permissions = [];
    foreach ($this->toolManager->getDefinitions() as $id => $definition) {
      $permissions[self::permissionFor($id)] = [
        'title' => $this->t('MCP tool: @label', ['@label' => $definition['label']]),
        'description' => $definition['description'] ?? '',
        // Flag write tools so admins notice them on the permissions page.
        'restrict access' => empty($definition['readOnly']),
      ];
    }
    return $permissions;
  }

  /**
   * The permission name for a tool.
   */
  public static function permissionFor(string $tool_id): string {
    return 'use mcp tool ' . $tool_id;
  }

}
