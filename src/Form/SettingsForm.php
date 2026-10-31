<?php

declare(strict_types=1);

namespace Drupal\mcp_gateway\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\mcp_gateway\McpToolPluginManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Site-wide MCP Gateway settings.
 */
final class SettingsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config,
    private readonly McpToolPluginManager $toolManager,
  ) {
    parent::__construct($config_factory, $typed_config);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('plugin.manager.mcp_tool'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'mcp_gateway_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['mcp_gateway.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('mcp_gateway.settings');

    $form['endpoint'] = [
      '#type' => 'item',
      '#title' => $this->t('MCP endpoint'),
      '#markup' => '<code>' . Url::fromRoute('mcp_gateway.endpoint', [], ['absolute' => TRUE])->toString() . '</code>',
      '#description' => $this->t('Users create personal tokens on the "MCP tokens" tab of their profile, then add this URL to their LLM assistant or other MCP client.'),
    ];

    $options = [];
    foreach ($this->toolManager->getDefinitions() as $id => $definition) {
      $options[$id] = $this->t('<strong>@label</strong> (<code>@id</code>)@write — @description', [
        '@label' => $definition['label'],
        '@id' => $id,
        '@write' => empty($definition['readOnly']) ? ' [writes content]' : '',
        '@description' => $definition['description'],
      ]);
    }
    $form['enabled_tools'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Enabled tools'),
      '#description' => $this->t('Disabled tools are hidden from every user. Enabled tools still need a per-tool permission (<a href=":url">permissions page</a>).', [
        ':url' => Url::fromRoute('user.admin_permissions.module', ['modules' => 'mcp_gateway'])->toString(),
      ]),
      '#options' => $options,
      '#default_value' => (array) $config->get('enabled_tools'),
    ];
    $form['max_results'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum search results'),
      '#min' => 1,
      '#max' => 50,
      '#default_value' => $config->get('max_results'),
    ];
    $form['audit'] = [
      '#type' => 'details',
      '#title' => $this->t('Audit log'),
      '#open' => TRUE,
    ];
    $form['audit']['audit_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Record every tool call'),
      '#default_value' => $config->get('audit.enabled'),
    ];
    $form['audit']['retention_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Keep records for (days)'),
      '#description' => $this->t('Older records are deleted on cron. 0 keeps them forever.'),
      '#min' => 0,
      '#default_value' => $config->get('audit.retention_days'),
    ];
    $form['allowed_origins'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Allowed browser origins'),
      '#description' => $this->t('One per line, e.g. https://app.example.com. Only needed for browser-based MCP clients. Desktop and CLI clients send no Origin header.'),
      '#default_value' => implode("\n", (array) $config->get('allowed_origins')),
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $origins = array_values(array_filter(array_map('trim', explode("\n", (string) $form_state->getValue('allowed_origins')))));
    $this->config('mcp_gateway.settings')
      ->set('enabled_tools', array_values(array_filter($form_state->getValue('enabled_tools'))))
      ->set('max_results', (int) $form_state->getValue('max_results'))
      ->set('audit.enabled', (bool) $form_state->getValue('audit_enabled'))
      ->set('audit.retention_days', (int) $form_state->getValue('retention_days'))
      ->set('allowed_origins', $origins)
      ->save();
    parent::submitForm($form, $form_state);
  }

}
