<?php

declare(strict_types=1);

namespace Drupal\mcp_gateway\Plugin\McpTool;

use Drupal\Core\Entity\EntityReferenceFieldItemListInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_gateway\Attribute\McpTool;
use Drupal\mcp_gateway\McpToolBase;
use Drupal\node\NodeInterface;

/**
 * Reads one node in full, honouring entity and field access.
 */
#[McpTool(
  id: 'get_node',
  label: new TranslatableMarkup('Get content item'),
  description: 'Read one content item (node) by ID: title, status, author, dates, URL, body as plain text, and every other field you are allowed to view.',
)]
final class GetNode extends McpToolBase {

  /**
   * Base fields already covered by the summary, or never useful to a model.
   */
  private const SKIP_FIELDS = [
    'nid', 'uuid', 'vid', 'langcode', 'type', 'title', 'status', 'uid', 'created',
    'changed', 'body', 'revision_timestamp', 'revision_uid', 'revision_log',
    'revision_default', 'revision_translation_affected', 'default_langcode',
    'content_translation_source', 'content_translation_outdated', 'path',
  ];

  /**
   * {@inheritdoc}
   */
  public function getInputSchema(): array {
    return [
      'type' => 'object',
      'properties' => [
        'nid' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Node ID, as returned by search_content.'],
      ],
      'required' => ['nid'],
      'additionalProperties' => FALSE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, AccountInterface $account): array {
    $node = $this->loadNode((int) $arguments['nid'], $account);

    $result = $this->summarizeNode($node);
    unset($result['summary']);
    $result['created'] = date(DATE_ATOM, (int) $node->getCreatedTime());
    $result['body'] = ($node->hasField('body') && $node->get('body')->access('view', $account) && !$node->get('body')->isEmpty())
      ? $this->plainText((string) $node->get('body')->value)
      : NULL;
    $result['fields'] = $this->extraFields($node, $account);

    return $result;
  }

  /**
   * Values of every other field the account may view.
   *
   * @return array<string, mixed>
   *   Field values keyed by field name.
   */
  private function extraFields(NodeInterface $node, AccountInterface $account): array {
    $fields = [];
    foreach ($node->getFields() as $name => $items) {
      if (in_array($name, self::SKIP_FIELDS, TRUE) || $items->isEmpty() || !$items->access('view', $account)) {
        continue;
      }
      if ($items instanceof EntityReferenceFieldItemListInterface) {
        $labels = [];
        foreach ($items->referencedEntities() as $entity) {
          if ($entity->access('view label', $account)) {
            $labels[] = $entity->label();
          }
        }
        $value = $labels;
      }
      else {
        $value = [];
        foreach ($items as $item) {
          $main = $item->mainPropertyName();
          $raw = $main ? $item->get($main)->getValue() : $item->getValue();
          $value[] = is_string($raw) ? $this->plainText($raw) : $raw;
        }
      }
      $fields[$name] = count($value) === 1 ? reset($value) : $value;
    }
    return $fields;
  }

}
