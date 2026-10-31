<?php

declare(strict_types=1);

namespace Drupal\mcp_gateway\Plugin\McpTool;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_gateway\Attribute\McpTool;
use Drupal\mcp_gateway\McpToolBase;

/**
 * Tells the assistant which site it is connected to and who it acts as.
 */
#[McpTool(
  id: 'site_info',
  label: new TranslatableMarkup('Site info'),
  description: 'Get the site name, Drupal version, content counts by type, and the Drupal user you are acting as (name and roles). Call this first to orient yourself.',
)]
final class SiteInfo extends McpToolBase {

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
    $site = $this->configFactory->get('system.site');

    $counts = [];
    $storage = $this->entityTypeManager->getStorage('node');
    $sees_all_unpublished = $account->hasPermission('bypass node access') || $account->hasPermission('view any unpublished content');
    foreach ($this->entityTypeManager->getStorage('node_type')->loadMultiple() as $type) {
      // Counts respect node access, so users never learn about hidden content.
      $query = $storage->getQuery()
        ->accessCheck(TRUE)
        ->condition('type', $type->id());
      // Without node grant modules, query access does not filter by status,
      // so apply the unpublished-content rules that node access uses.
      if (!$sees_all_unpublished) {
        $visible = $query->orConditionGroup()->condition('status', 1);
        if ($account->hasPermission('view own unpublished content') && $account->isAuthenticated()) {
          $visible->condition($query->andConditionGroup()->condition('status', 0)->condition('uid', $account->id()));
        }
        $query->condition($visible);
      }
      $counts[$type->id()] = (int) $query->count()->execute();
    }

    return [
      'site_name' => $site->get('name'),
      'slogan' => $site->get('slogan') ?: NULL,
      'drupal_version' => \Drupal::VERSION,
      'content_counts' => $counts,
      'acting_as' => [
        'uid' => (int) $account->id(),
        'name' => $account->getDisplayName(),
        'roles' => array_values(array_diff($account->getRoles(), ['authenticated'])),
      ],
    ];
  }

}
