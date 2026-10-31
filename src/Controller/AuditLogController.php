<?php

declare(strict_types=1);

namespace Drupal\mcp_gateway\Controller;

use Drupal\Core\Url;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\PagerSelectExtender;
use Drupal\Core\Database\Query\TableSortExtender;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Symfony\Component\HttpFoundation\Request;

/**
 * Admin report listing MCP tool calls.
 */
final class AuditLogController extends ControllerBase {

  use AutowireTrait;

  private const OUTCOMES = ['success', 'denied', 'invalid', 'error'];

  public function __construct(
    private readonly Connection $database,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * Builds the audit log report.
   */
  public function list(Request $request): array {
    $outcome = $request->query->get('outcome');
    $header = [
      'created' => ['data' => $this->t('Time'), 'field' => 'a.created', 'sort' => 'desc'],
      'user' => ['data' => $this->t('User'), 'field' => 'u.name'],
      'tool' => ['data' => $this->t('Tool'), 'field' => 'a.tool'],
      'outcome' => ['data' => $this->t('Outcome'), 'field' => 'a.outcome'],
      'arguments' => $this->t('Arguments'),
      'message' => $this->t('Message'),
      'duration' => ['data' => $this->t('ms'), 'field' => 'a.duration_ms'],
    ];

    $query = $this->database->select('mcp_gateway_audit', 'a')
      ->extend(PagerSelectExtender::class)
      ->extend(TableSortExtender::class);
    $query->leftJoin('users_field_data', 'u', 'u.uid = a.uid');
    $query->fields('a')->addField('u', 'name');
    if (in_array($outcome, self::OUTCOMES, TRUE)) {
      $query->condition('a.outcome', $outcome);
    }
    $rows = [];
    foreach ($query->orderByHeader($header)->limit(50)->execute() as $record) {
      $rows[] = [
        'class' => ['mcp-audit--' . $record->outcome],
        'data' => [
          $this->dateFormatter->format((int) $record->created, 'short'),
          $record->name ?? $this->t('deleted user (@uid)', ['@uid' => $record->uid]),
          ['data' => ['#markup' => '<code>' . htmlspecialchars($record->tool) . '</code>']],
          $record->outcome,
          ['data' => ['#markup' => '<code>' . htmlspecialchars((string) $record->arguments) . '</code>']],
          $record->message,
          $record->duration_ms,
        ],
      ];
    }

    $links = [];
    foreach (array_merge([NULL], self::OUTCOMES) as $option) {
      $links[] = [
        '#type' => 'link',
        '#title' => $option ?? $this->t('All'),
        '#url' => Url::fromRoute('mcp_gateway.audit_log', [], ['query' => $option ? ['outcome' => $option] : []]),
      ];
    }

    return [
      'filters' => [
        '#theme' => 'item_list',
        '#items' => $links,
        '#title' => $this->t('Filter by outcome'),
        '#attributes' => ['class' => ['inline']],
      ],
      'table' => [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#empty' => $this->t('No MCP tool calls recorded yet.'),
      ],
      'pager' => ['#type' => 'pager'],
      '#cache' => ['max-age' => 0],
    ];
  }

}
