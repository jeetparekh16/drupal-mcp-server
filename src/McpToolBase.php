<?php

declare(strict_types=1);

namespace Drupal\mcp_gateway;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\mcp_gateway\Exception\McpToolException;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class for MCP tools, with shared helpers for working with nodes.
 */
abstract class McpToolBase extends PluginBase implements McpToolInterface, ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly EntityFieldManagerInterface $entityFieldManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('config.factory'),
      $container->get('entity_field.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getName(): string {
    return $this->getPluginId();
  }

  /**
   * {@inheritdoc}
   */
  public function getTitle(): string {
    return (string) $this->pluginDefinition['label'];
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string {
    return (string) $this->pluginDefinition['description'];
  }

  /**
   * {@inheritdoc}
   */
  public function isReadOnly(): bool {
    return (bool) $this->pluginDefinition['readOnly'];
  }

  /**
   * {@inheritdoc}
   */
  public function isIdempotent(): bool {
    return (bool) $this->pluginDefinition['idempotent'];
  }

  /**
   * {@inheritdoc}
   */
  public function access(AccountInterface $account): AccessResultInterface {
    return AccessResult::allowedIfHasPermission($account, 'use mcp gateway')
      ->andIf(AccessResult::allowedIfHasPermission($account, McpPermissions::permissionFor($this->getPluginId())));
  }

  /**
   * Loads a node the account may perform $operation on, or throws.
   *
   * A missing node and a node the user cannot see produce the same message,
   * so the tool never confirms that hidden content exists.
   */
  protected function loadNode(int $nid, AccountInterface $account, string $operation = 'view'): NodeInterface {
    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$node instanceof NodeInterface || !$node->access('view', $account)) {
      throw McpToolException::notFound(sprintf('Node %d was not found, or you do not have access to it.', $nid));
    }
    if ($operation !== 'view' && !$node->access($operation, $account)) {
      throw McpToolException::accessDenied(sprintf('You are not allowed to %s node %d ("%s").', $operation, $nid, $node->label()));
    }
    return $node;
  }

  /**
   * Compact, model-friendly description of a node.
   *
   * @return array<string, mixed>
   *   Node summary.
   */
  protected function summarizeNode(NodeInterface $node): array {
    return [
      'nid' => (int) $node->id(),
      'title' => $node->label(),
      'type' => $node->bundle(),
      'status' => $node->isPublished() ? 'published' : 'unpublished',
      'author' => $node->getOwner()?->getDisplayName(),
      'changed' => date(DATE_ATOM, (int) $node->getChangedTime()),
      'url' => $node->toUrl('canonical', ['absolute' => TRUE])->toString(),
      'summary' => $this->bodySummary($node),
    ];
  }

  /**
   * Plain-text summary of the body field.
   */
  protected function bodySummary(NodeInterface $node, int $length = 240): string {
    if (!$node->hasField('body') || $node->get('body')->isEmpty()) {
      return '';
    }
    // Works for text_with_summary and plain long-text body fields alike.
    $values = $node->get('body')->first()->getValue();
    $text = (string) (($values['summary'] ?? '') ?: ($values['value'] ?? ''));
    return Unicode::truncate($this->plainText($text), $length, TRUE, TRUE);
  }

  /**
   * Converts HTML to readable plain text.
   */
  protected function plainText(string $html): string {
    $text = Html::decodeEntities(strip_tags(str_replace(['</p>', '<br>', '<br />', '</li>'], "\n", $html)));
    return trim(preg_replace("/[ \t]+/", ' ', preg_replace("/\n{3,}/", "\n\n", $text)));
  }

  /**
   * The site's fallback text format (works on every Drupal 11 minor version).
   */
  protected function fallbackFormat(): string {
    return (string) ($this->configFactory->get('filter.settings')->get('fallback_format') ?: 'plain_text');
  }

  /**
   * Maximum results a search may return.
   */
  protected function maxResults(): int {
    return max(1, (int) ($this->configFactory->get('mcp_gateway.settings')->get('max_results') ?: 25));
  }

}
