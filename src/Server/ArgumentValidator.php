<?php

declare(strict_types=1);

namespace Drupal\mcp_gateway\Server;

/**
 * Validates tool arguments against the JSON Schema subset tools declare.
 *
 * Supports: type (object, string, integer, number, boolean, array), required,
 * properties, additionalProperties: false, enum, minimum, maximum, minLength,
 * maxLength. That covers what the bundled tools use without pulling in a
 * full JSON Schema library.
 */
final class ArgumentValidator {

  /**
   * Validates arguments against a tool's input schema.
   *
   * @param array<string, mixed> $arguments
   *   Arguments sent by the client.
   * @param array<string, mixed> $schema
   *   The tool's input schema.
   *
   * @return string[]
   *   Human-readable errors. Empty when the arguments are valid.
   */
  public function validate(array $arguments, array $schema): array {
    $errors = [];
    $properties = (array) ($schema['properties'] ?? []);

    foreach ($schema['required'] ?? [] as $name) {
      if (!array_key_exists($name, $arguments)) {
        $errors[] = sprintf('Missing required argument "%s".', $name);
      }
    }
    if (($schema['additionalProperties'] ?? TRUE) === FALSE) {
      foreach (array_keys($arguments) as $name) {
        if (!array_key_exists($name, $properties)) {
          $errors[] = sprintf('Unknown argument "%s". Allowed: %s.', $name, $properties ? implode(', ', array_keys($properties)) : 'none');
        }
      }
    }
    foreach ($arguments as $name => $value) {
      if (isset($properties[$name])) {
        array_push($errors, ...$this->validateValue($name, $value, (array) $properties[$name]));
      }
    }
    return $errors;
  }

  /**
   * Validates one argument value against its property rule.
   *
   * @return string[]
   *   Errors for this value.
   */
  private function validateValue(string $name, mixed $value, array $rule): array {
    $type = $rule['type'] ?? NULL;
    if ($type !== NULL && !$this->matchesType($value, $type)) {
      return [sprintf('Argument "%s" must be of type %s, %s given.', $name, $type, get_debug_type($value))];
    }

    $errors = [];
    if (isset($rule['enum']) && !in_array($value, $rule['enum'], TRUE)) {
      $errors[] = sprintf('Argument "%s" must be one of: %s.', $name, implode(', ', $rule['enum']));
    }
    if (is_int($value) || is_float($value)) {
      if (isset($rule['minimum']) && $value < $rule['minimum']) {
        $errors[] = sprintf('Argument "%s" must be at least %s.', $name, $rule['minimum']);
      }
      if (isset($rule['maximum']) && $value > $rule['maximum']) {
        $errors[] = sprintf('Argument "%s" must be at most %s.', $name, $rule['maximum']);
      }
    }
    if (is_string($value)) {
      $length = mb_strlen($value);
      if (isset($rule['minLength']) && $length < $rule['minLength']) {
        $errors[] = sprintf('Argument "%s" must be at least %d characters.', $name, $rule['minLength']);
      }
      if (isset($rule['maxLength']) && $length > $rule['maxLength']) {
        $errors[] = sprintf('Argument "%s" must be at most %d characters.', $name, $rule['maxLength']);
      }
    }
    return $errors;
  }

  /**
   * Checks a value against a JSON Schema type.
   */
  private function matchesType(mixed $value, string $type): bool {
    return match ($type) {
      'string' => is_string($value),
      // JSON has one number type: accept 5.0 as an integer, like JSON Schema.
      'integer' => is_int($value) || (is_float($value) && floor($value) === $value),
      'number' => is_int($value) || is_float($value),
      'boolean' => is_bool($value),
      'array' => is_array($value) && array_is_list($value),
      'object' => is_array($value) && (!array_is_list($value) || $value === []),
      'null' => $value === NULL,
      default => TRUE,
    };
  }

}
