<?php

declare(strict_types=1);

namespace Drupal\mcp_gateway\Plugin\McpTool;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_gateway\Attribute\McpTool;
use Drupal\mcp_gateway\Exception\McpToolException;
use Drupal\mcp_gateway\McpToolBase;
use Drupal\node\NodeInterface;

/**
 * Creates new content, always unpublished, so a human reviews it first.
 */
#[McpTool(
  id: 'create_draft',
  label: new TranslatableMarkup('Create draft'),
  description: 'Create a new content item as an UNPUBLISHED draft owned by you. A human editor reviews and publishes it in Drupal. Body may contain basic HTML (p, h2, h3, ul, ol, li, a, strong, em).',
  readOnly: FALSE,
  idempotent: FALSE,
)]
final class CreateDraft extends McpToolBase {

  /**
   * {@inheritdoc}
   */
  public function getInputSchema(): array {
    return [
      'type' => 'object',
      'properties' => [
        'type' => ['type' => 'string', 'description' => 'Content type machine name, e.g. "article". See list_content_types.'],
        'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
        'body' => ['type' => 'string', 'description' => 'Main text. Basic HTML allowed.'],
        'revision_log' => ['type' => 'string', 'maxLength' => 255, 'description' => 'Short note for editors explaining why this draft was created.'],
      ],
      'required' => ['type', 'title'],
      'additionalProperties' => FALSE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, AccountInterface $account): array {
    $type = (string) $arguments['type'];
    if (!$this->entityTypeManager->getStorage('node_type')->load($type)) {
      throw new McpToolException(sprintf('Content type "%s" does not exist. Call list_content_types to see valid types.', $type));
    }
    if (!$this->entityTypeManager->getAccessControlHandler('node')->createAccess($type, $account)) {
      throw McpToolException::accessDenied(sprintf('You are not allowed to create "%s" content.', $type));
    }

    /** @var \Drupal\node\NodeInterface $node */
    $node = $this->entityTypeManager->getStorage('node')->create([
      'type' => $type,
      'title' => trim((string) $arguments['title']),
      'uid' => $account->id(),
      // Safety by design: AI-created content is never published directly.
      'status' => NodeInterface::NOT_PUBLISHED,
    ]);
    if (isset($arguments['body']) && $node->hasField('body')) {
      $node->set('body', ['value' => $arguments['body'], 'format' => $this->bodyFormat($account)]);
    }
    $node->setNewRevision();
    $node->setRevisionUserId($account->id());
    $node->setRevisionLogMessage('Draft created by an AI assistant via MCP. ' . ($arguments['revision_log'] ?? ''));

    $violations = $node->validate()->getEntityViolations();
    if (count($violations)) {
      throw new McpToolException('Validation failed: ' . $violations->get(0)->getMessage());
    }
    $node->save();

    return [
      'created' => TRUE,
      'nid' => (int) $node->id(),
      'status' => 'unpublished',
      'url' => $node->toUrl('canonical', ['absolute' => TRUE])->toString(),
      'edit_url' => $node->toUrl('edit-form', ['absolute' => TRUE])->toString(),
      'message' => 'Draft saved. An editor must review and publish it.',
    ];
  }

  /**
   * Picks the richest text format the account may use, falling back safely.
   */
  private function bodyFormat(AccountInterface $account): string {
    foreach (['basic_html', 'restricted_html'] as $format) {
      $entity = $this->entityTypeManager->getStorage('filter_format')->load($format);
      if ($entity && $entity->access('use', $account)) {
        return $format;
      }
    }
    return $this->fallbackFormat();
  }

}
