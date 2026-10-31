<?php

declare(strict_types=1);

namespace Drupal\mcp_gateway\Plugin\McpTool;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_gateway\Attribute\McpTool;
use Drupal\mcp_gateway\McpToolBase;

/**
 * Searches content the current user is allowed to see.
 */
#[McpTool(
  id: 'search_content',
  label: new TranslatableMarkup('Search content'),
  description: 'Search site content by words in the title and/or body. Returns only content you are allowed to view, newest first, with a short summary of each item. Use get_node to read one in full.',
)]
final class SearchContent extends McpToolBase {

  /**
   * {@inheritdoc}
   */
  public function getInputSchema(): array {
    return [
      'type' => 'object',
      'properties' => [
        'query' => [
          'type' => 'string',
          'description' => 'Words to look for in the title or body. Leave empty to list recent content.',
          'maxLength' => 255,
        ],
        'type' => [
          'type' => 'string',
          'description' => 'Limit to one content type machine name, e.g. "article". See list_content_types.',
        ],
        'status' => [
          'type' => 'string',
          'enum' => ['published', 'unpublished', 'any'],
          'description' => 'Publication status filter. Defaults to "any" (unpublished items appear only if you may see them).',
        ],
        'limit' => [
          'type' => 'integer',
          'minimum' => 1,
          'maximum' => 50,
          'description' => 'Maximum number of results (default 10).',
        ],
      ],
      'additionalProperties' => FALSE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, AccountInterface $account): array {
    $limit = min((int) ($arguments['limit'] ?? 10), $this->maxResults());
    $storage = $this->entityTypeManager->getStorage('node');

    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->sort('changed', 'DESC');

    $words = trim((string) ($arguments['query'] ?? ''));
    if ($words !== '') {
      $match = $query->orConditionGroup()->condition('title', $words, 'CONTAINS');
      if ($this->bodyFieldExists()) {
        $match->condition('body.value', $words, 'CONTAINS');
      }
      $query->condition($match);
    }
    if (!empty($arguments['type'])) {
      $query->condition('type', $arguments['type']);
    }
    $status = $arguments['status'] ?? 'any';
    if ($status !== 'any') {
      $query->condition('status', $status === 'published' ? 1 : 0);
    }

    // Over-fetch, then apply entity access per node: query access alone does
    // not hide unpublished nodes on sites without node grant modules.
    $ids = $query->range(0, $limit * 3)->execute();

    $results = [];
    foreach ($storage->loadMultiple($ids) as $node) {
      if (count($results) >= $limit) {
        break;
      }
      if ($node->access('view', $account)) {
        $results[] = $this->summarizeNode($node);
      }
    }

    return [
      'query' => $words,
      'count' => count($results),
      'results' => $results,
    ];
  }

  /**
   * Whether any content type has a body field.
   */
  private function bodyFieldExists(): bool {
    $map = $this->entityFieldManager->getFieldMap();
    return isset($map['node']['body']);
  }

}
