<?php

declare(strict_types=1);

namespace Drupal\mcp_gateway\Plugin\McpTool;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_gateway\Attribute\McpTool;
use Drupal\mcp_gateway\McpToolBase;

/**
 * Lists content types and whether the current user may create each one.
 */
#[McpTool(
  id: 'list_content_types',
  label: new TranslatableMarkup('List content types'),
  description: 'List the site\'s content types (machine name, label, description) and whether you are allowed to create drafts of each. Use the machine name as "type" in other tools.',
)]
final class ListContentTypes extends McpToolBase {

  /**
   * {@inheritdoc}
   */
  public function getInputSchema(): array {
    return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => FALSE];
  }

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, AccountInterface $account): array {
    $access = $this->entityTypeManager->getAccessControlHandler('node');
    $types = [];
    foreach ($this->entityTypeManager->getStorage('node_type')->loadMultiple() as $type) {
      $types[] = [
        'type' => $type->id(),
        'label' => $type->label(),
        'description' => (string) $type->getDescription(),
        'can_create' => $access->createAccess($type->id(), $account),
      ];
    }
    return ['content_types' => $types];
  }

}
