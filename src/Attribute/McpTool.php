<?php

declare(strict_types=1);

namespace Drupal\mcp_gateway\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines an MCP tool plugin.
 *
 * Any module can expose a new tool to AI assistants by adding a class in
 * src/Plugin/McpTool with this attribute and implementing McpToolInterface.
 *
 * @see \Drupal\mcp_gateway\McpToolInterface
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class McpTool extends Plugin {

  /**
   * Constructs an McpTool attribute.
   *
   * @param string $id
   *   Tool name sent to the AI client. Lowercase letters, digits, underscores.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   Human-readable title, shown to admins and to MCP clients.
   * @param string $description
   *   What the tool does and when to use it. Written for the model.
   * @param bool $readOnly
   *   TRUE if the tool never changes site data.
   * @param bool $idempotent
   *   TRUE if calling it twice with the same arguments has no extra effect.
   * @param class-string|null $deriver
   *   (optional) Deriver class.
   */
  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
    public readonly string $description = '',
    public readonly bool $readOnly = TRUE,
    public readonly bool $idempotent = TRUE,
    public readonly ?string $deriver = NULL,
  ) {}

}
