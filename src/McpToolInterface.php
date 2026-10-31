<?php

declare(strict_types=1);

namespace Drupal\mcp_gateway;

use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Interface for tools exposed to AI assistants over MCP.
 */
interface McpToolInterface extends PluginInspectionInterface {

  /**
   * The tool name used on the wire (the plugin ID).
   */
  public function getName(): string;

  /**
   * Human-readable title.
   */
  public function getTitle(): string;

  /**
   * Description written for the model: what the tool does and when to use it.
   */
  public function getDescription(): string;

  /**
   * JSON Schema (draft 2020-12 subset) describing the tool's arguments.
   *
   * @return array<string, mixed>
   *   The input schema.
   */
  public function getInputSchema(): array;

  /**
   * Whether the tool only reads data.
   */
  public function isReadOnly(): bool;

  /**
   * Whether repeated identical calls have no additional effect.
   */
  public function isIdempotent(): bool;

  /**
   * Checks whether an account may call this tool at all.
   *
   * Object-level checks (e.g. "may this user edit node 5?") happen inside
   * execute(), using Drupal's entity access system.
   */
  public function access(AccountInterface $account): AccessResultInterface;

  /**
   * Runs the tool.
   *
   * @param array<string, mixed> $arguments
   *   Arguments already validated against getInputSchema().
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The Drupal user the AI assistant is acting for.
   *
   * @return array<string, mixed>
   *   Structured result. It is returned to the client both as JSON text and
   *   as MCP structuredContent.
   *
   * @throws \Drupal\mcp_gateway\Exception\McpToolException
   *   For errors the model should see and can recover from (not found,
   *   access denied, bad input).
   */
  public function execute(array $arguments, AccountInterface $account): array;

}
