<?php

declare(strict_types=1);

namespace Drupal\Tests\mcp_gateway\Unit;

use Drupal\mcp_gateway\Server\ArgumentValidator;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the tool argument validator.
 */
#[CoversClass(ArgumentValidator::class)]
#[Group('mcp_gateway')]
final class ArgumentValidatorTest extends UnitTestCase {

  private const SCHEMA = [
    'type' => 'object',
    'properties' => [
      'nid' => ['type' => 'integer', 'minimum' => 1],
      'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 10],
      'status' => ['type' => 'string', 'enum' => ['published', 'any']],
      'flag' => ['type' => 'boolean'],
    ],
    'required' => ['nid'],
    'additionalProperties' => FALSE,
  ];

  /**
   * Data provider: arguments and the expected number of errors.
   */
  public static function cases(): array {
    return [
      'valid minimal' => [['nid' => 5], 0],
      'valid full' => [['nid' => 5, 'title' => 'Hello', 'status' => 'any', 'flag' => TRUE], 0],
      'whole float is an integer' => [['nid' => 5.0], 0],
      'missing required' => [[], 1],
      'wrong type' => [['nid' => '5'], 1],
      'below minimum' => [['nid' => 0], 1],
      'string too long' => [['nid' => 1, 'title' => 'Much too long'], 1],
      'empty string' => [['nid' => 1, 'title' => ''], 1],
      'not in enum' => [['nid' => 1, 'status' => 'draft'], 1],
      'unknown argument' => [['nid' => 1, 'sql' => 'DROP TABLE'], 1],
      'several problems' => [['nid' => 'x', 'flag' => 'yes', 'extra' => 1], 3],
    ];
  }

  /**
   * Tests validate.
   */
  #[DataProvider('cases')]
  public function testValidate(array $arguments, int $expected_errors): void {
    $errors = (new ArgumentValidator())->validate($arguments, self::SCHEMA);
    $this->assertCount($expected_errors, $errors, implode("\n", $errors));
  }

  /**
   * Tests empty schema rejects arguments.
   */
  public function testEmptySchemaRejectsArguments(): void {
    $schema = ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => FALSE];
    $validator = new ArgumentValidator();
    $this->assertSame([], $validator->validate([], $schema));
    $this->assertCount(1, $validator->validate(['anything' => 1], $schema));
  }

}
