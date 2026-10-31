<?php

declare(strict_types=1);

namespace Drupal\Tests\mcp_gateway\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\mcp_gateway\TokenRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests token generation, hashing, expiry and revocation.
 */
#[Group('mcp_gateway')]
#[RunTestsInSeparateProcesses]
final class TokenRepositoryTest extends KernelTestBase {

  protected static $modules = ['system', 'user', 'node', 'mcp_gateway'];

  private TokenRepository $tokens;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('mcp_gateway', ['mcp_gateway_token', 'mcp_gateway_audit']);
    $this->tokens = $this->container->get(TokenRepository::class);
  }

  /**
   * Tests round trip.
   */
  public function testRoundTrip(): void {
    $token = $this->tokens->generate(7, 'Laptop');
    $this->assertMatchesRegularExpression('/^mcp_[0-9a-f]{48}$/', $token);
    $this->assertSame(7, $this->tokens->verify($token));
    $this->assertNull($this->tokens->verify($token . 'x'));
    $this->assertNull($this->tokens->verify('mcp_' . str_repeat('0', 48)));
    $this->assertNull($this->tokens->verify('not-a-token'));
  }

  /**
   * Tests that only hash is stored.
   */
  public function testOnlyHashIsStored(): void {
    $token = $this->tokens->generate(7, 'Laptop');
    $stored = $this->container->get('database')->select('mcp_gateway_token', 't')->fields('t')->execute()->fetchAssoc();
    $this->assertNotContains($token, $stored);
    $this->assertSame(hash('sha256', $token), $stored['token_hash']);
    $this->assertSame(substr($token, -6), $stored['token_hint']);
  }

  /**
   * Tests that expired token is rejected.
   */
  public function testExpiredTokenIsRejected(): void {
    $token = $this->tokens->generate(7, 'Old', \Drupal::time()->getRequestTime() - 1);
    $this->assertNull($this->tokens->verify($token));
  }

  /**
   * Tests revoke.
   */
  public function testRevoke(): void {
    $token = $this->tokens->generate(7, 'One');
    $id = (int) $this->tokens->listForUser(7)[0]['id'];
    $this->assertFalse($this->tokens->revoke($id, 8), 'Another user cannot revoke it.');
    $this->assertTrue($this->tokens->revoke($id, 7));
    $this->assertNull($this->tokens->verify($token));
  }

}
