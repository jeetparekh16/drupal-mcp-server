<?php

declare(strict_types=1);

namespace Drupal\mcp_gateway\Plugin\McpTool;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_gateway\Attribute\McpTool;
use Drupal\mcp_gateway\Exception\McpToolException;
use Drupal\mcp_gateway\McpToolBase;

/**
 * Edits the title or body of existing content as a new revision.
 */
#[McpTool(
  id: 'update_node',
  label: new TranslatableMarkup('Update content item'),
  description: 'Change the title and/or body of an existing content item you are allowed to edit. Saves a new revision with a log message, so every AI edit can be reviewed and reverted. Does not change publication status.',
  readOnly: FALSE,
)]
final class UpdateNode extends McpToolBase {

  /**
   * {@inheritdoc}
   */
  public function getInputSchema(): array {
    return [
      'type' => 'object',
      'properties' => [
        'nid' => ['type' => 'integer', 'minimum' => 1],
        'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
        'body' => ['type' => 'string', 'description' => 'Replacement body text. Basic HTML allowed.'],
        'revision_log' => ['type' => 'string', 'maxLength' => 255, 'description' => 'Why the change was made. Shown in the revision history.'],
      ],
      'required' => ['nid'],
      'additionalProperties' => FALSE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, AccountInterface $account): array {
    if (!isset($arguments['title']) && !isset($arguments['body'])) {
      throw new McpToolException('Nothing to update: provide "title", "body" or both.');
    }
    $node = $this->loadNode((int) $arguments['nid'], $account, 'update');

    $changed = [];
    if (isset($arguments['title']) && $arguments['title'] !== $node->label()) {
      $node->setTitle(trim((string) $arguments['title']));
      $changed[] = 'title';
    }
    if (isset($arguments['body'])) {
      if (!$node->hasField('body')) {
        throw new McpToolException(sprintf('Content type "%s" has no body field.', $node->bundle()));
      }
      if (!$node->get('body')->access('edit', $account)) {
        throw McpToolException::accessDenied('You are not allowed to edit the body field.');
      }
      $format = $node->get('body')->format ?: $this->fallbackFormat();
      $node->set('body', ['value' => $arguments['body'], 'format' => $format]);
      $changed[] = 'body';
    }
    if (!$changed) {
      return ['updated' => FALSE, 'nid' => (int) $node->id(), 'message' => 'No changes: the values were already the same.'];
    }

    $node->setNewRevision();
    $node->setRevisionUserId($account->id());
    $node->setRevisionCreationTime(\Drupal::time()->getRequestTime());
    $node->setRevisionLogMessage('Edited by an AI assistant via MCP. ' . ($arguments['revision_log'] ?? ''));
    $violations = $node->validate()->getEntityViolations();
    if (count($violations)) {
      throw new McpToolException('Validation failed: ' . $violations->get(0)->getMessage());
    }
    $node->save();

    return [
      'updated' => TRUE,
      'nid' => (int) $node->id(),
      'changed_fields' => $changed,
      'revision_id' => (int) $node->getRevisionId(),
      'url' => $node->toUrl('canonical', ['absolute' => TRUE])->toString(),
    ];
  }

}
