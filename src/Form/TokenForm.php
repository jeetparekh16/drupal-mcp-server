<?php

declare(strict_types=1);

namespace Drupal\mcp_gateway\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\mcp_gateway\TokenRepository;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lets a user create and revoke their personal MCP tokens.
 */
final class TokenForm extends FormBase {

  public function __construct(
    private readonly TokenRepository $tokens,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(TokenRepository::class),
      $container->get('date.formatter'),
      $container->get('datetime.time'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'mcp_gateway_tokens';
  }

  /**
   * Users manage their own tokens; administrators may manage anyone's.
   */
  public static function access(UserInterface $user, AccountInterface $account): AccessResultInterface {
    if ($account->hasPermission('administer mcp gateway')) {
      return AccessResult::allowed()->cachePerPermissions();
    }
    return AccessResult::allowedIf((int) $user->id() === (int) $account->id() && $account->hasPermission('use mcp gateway'))
      ->cachePerUser()
      ->cachePerPermissions();
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?UserInterface $user = NULL): array {
    $form_state->set('uid', (int) $user->id());
    $endpoint = Url::fromRoute('mcp_gateway.endpoint', [], ['absolute' => TRUE])->toString();

    if ($new = $form_state->get('new_token')) {
      $form['new_token'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['messages', 'messages--warning']],
        'title' => ['#markup' => '<h3>' . $this->t('Copy your new token now. It will not be shown again.') . '</h3>'],
        'token' => ['#markup' => '<p><code class="mcp-token">' . $new . '</code></p>'],
        'claude_code_label' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('Connect Claude Code:'),
        ],
        'claude_code' => [
          '#type' => 'html_tag',
          '#tag' => 'pre',
          '#plain_text' => sprintf('claude mcp add --transport http drupal %s --header "Authorization: Bearer %s"', $endpoint, $new),
        ],
      ];
    }

    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Tokens let AI assistants such as Claude act as <strong>@name</strong> on this site through the MCP endpoint <code>@endpoint</code>. They get exactly the permissions this account has. Treat tokens like passwords.', [
        '@name' => $user->getDisplayName(),
        '@endpoint' => $endpoint,
      ]) . '</p>',
    ];

    $rows = [];
    foreach ($this->tokens->listForUser((int) $user->id()) as $token) {
      $rows[$token['id']] = [
        'label' => $token['label'] ?: $this->t('(no label)'),
        'hint' => '…' . $token['token_hint'],
        'created' => $this->dateFormatter->format((int) $token['created'], 'short'),
        'last_used' => $token['last_used'] ? $this->dateFormatter->formatTimeDiffSince((int) $token['last_used']) . ' ' . $this->t('ago') : $this->t('never'),
        'expires' => $token['expires'] ? $this->dateFormatter->format((int) $token['expires'], 'short') : $this->t('never'),
      ];
    }
    $form['existing'] = [
      '#type' => 'tableselect',
      '#header' => [
        'label' => $this->t('Label'),
        'hint' => $this->t('Ends with'),
        'created' => $this->t('Created'),
        'last_used' => $this->t('Last used'),
        'expires' => $this->t('Expires'),
      ],
      '#options' => $rows,
      '#empty' => $this->t('No tokens yet.'),
    ];
    if ($rows) {
      $form['revoke'] = [
        '#type' => 'submit',
        '#value' => $this->t('Revoke selected'),
        '#submit' => ['::revokeSubmit'],
        '#button_type' => 'danger',
        '#limit_validation_errors' => [['existing']],
      ];
    }

    $form['new'] = [
      '#type' => 'details',
      '#title' => $this->t('Create a token'),
      '#open' => TRUE,
    ];
    $form['new']['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#description' => $this->t('Where you will use it, e.g. "Claude Desktop on my laptop".'),
      '#maxlength' => 128,
      '#required' => TRUE,
    ];
    $form['new']['lifetime'] = [
      '#type' => 'select',
      '#title' => $this->t('Expires'),
      '#options' => [
        30 => $this->t('In 30 days'),
        90 => $this->t('In 90 days'),
        365 => $this->t('In 1 year'),
        0 => $this->t('Never'),
      ],
      '#default_value' => 90,
    ];
    $form['new']['generate'] = [
      '#type' => 'submit',
      '#value' => $this->t('Generate token'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $days = (int) $form_state->getValue('lifetime');
    $expires = $days > 0 ? $this->time->getRequestTime() + $days * 86400 : 0;
    $token = $this->tokens->generate((int) $form_state->get('uid'), (string) $form_state->getValue('label'), $expires);
    // Rebuild instead of redirecting, so the token is shown exactly once and
    // never stored in the session or a message queue.
    $form_state->set('new_token', $token);
    $form_state->setRebuild();
  }

  /**
   * Submit handler: revokes the selected tokens.
   */
  public function revokeSubmit(array &$form, FormStateInterface $form_state): void {
    $uid = (int) $form_state->get('uid');
    $count = 0;
    foreach (array_filter((array) $form_state->getValue('existing')) as $id) {
      $count += (int) $this->tokens->revoke((int) $id, $uid);
    }
    $this->messenger()->addStatus($this->formatPlural($count, 'Revoked 1 token.', 'Revoked @count tokens.'));
  }

}
