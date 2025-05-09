<?php
namespace Tests\Unit\Auth;

use Tests\TestCase;
use Glueful\Auth\PasswordHasher;

/**
 * Tests for the Password Hasher
 */
class PasswordHasherTest extends TestCase
{
    /**
     * @var PasswordHasher The hasher being tested
     */
    private PasswordHasher $hasher;
    
    /**
     * @var string Sample password for testing
     */
    private string $password = 'S3cureP@ssw0rd!';
    
    /**
     * Set up test environment
     */
    protected function setUp(): void
    {
        parent::setUp();
        
        // Create password hasher with default options
        $this->hasher = new PasswordHasher();
    }
    
    /**
     * Test password hashing
     */
    public function testHashCreatesSecureHash(): void
    {
        // Hash a password
        $hash = $this->hasher->hash($this->password);
        
        // Assert the hash is a non-empty string
        $this->assertIsString($hash);
        $this->assertNotEmpty($hash);
        
        // Verify hash is not the original password
        $this->assertNotEquals($this->password, $hash);
        
        // Verify the hash starts with the bcrypt identifier
        $this->assertStringStartsWith('$2y$', $hash, 'Hash should use bcrypt format by default');
    }
    
    /**
     * Test password verification with correct password
     */
    public function testVerifyWithCorrectPassword(): void
    {
        // Hash a password
        $hash = $this->hasher->hash($this->password);
        
        // Verify with correct password
        $result = $this->hasher->verify($this->password, $hash);
        
        // Should succeed
        $this->assertTrue($result);
    }
    
    /**
     * Test password verification with incorrect password
     */
    public function testVerifyWithIncorrectPassword(): void
    {
        // Hash a password
        $hash = $this->hasher->hash($this->password);
        
        // Verify with wrong password
        $result = $this->hasher->verify('WrongPassword123!', $hash);
        
        // Should fail
        $this->assertFalse($result);
    }
    
    /**
     * Test needs rehash functionality
     */
    public function testNeedsRehash(): void
    {
        // Create a hasher with default options
        $defaultHasher = new PasswordHasher();
        
        // Create a hasher with higher cost
        $secureHasher = new PasswordHasher(['cost' => 15]);
        
        // Hash with default options
        $hash = $defaultHasher->hash($this->password);
        
        // Check if hash needs upgrade with more secure options
        $needsRehash = $secureHasher->needsRehash($hash);
        
        // Should need rehashing due to different options
        $this->assertTrue($needsRehash);
        
        // Hash with secure options
        $secureHash = $secureHasher->hash($this->password);
        
        // Check if secure hash needs upgrade with same secure options
        $needsRehash = $secureHasher->needsRehash($secureHash);
        
        // Should not need rehashing
        $this->assertFalse($needsRehash);
    }
    
    /**
     * Test hashing with different algorithms
     */
    public function testHashingWithDifferentAlgorithms(): void
    {
        // Skip if PHP doesn't support Argon2
        if (!defined('PASSWORD_ARGON2ID')) {
            $this->markTestSkipped('Argon2id not available in this PHP build');
            return;
        }
        
        // Test with bcrypt (default)
        $bcryptHash = $this->hasher->hash($this->password, PASSWORD_BCRYPT);
        $this->assertStringStartsWith('$2y$', $bcryptHash);
        $this->assertTrue($this->hasher->verify($this->password, $bcryptHash));
        
        // Test with argon2id
        $argon2Hash = $this->hasher->hash($this->password, PASSWORD_ARGON2ID);
        $this->assertStringStartsWith('$argon2id$', $argon2Hash);
        $this->assertTrue($this->hasher->verify($this->password, $argon2Hash));
    }
    
    /**
     * Test custom options
     */
    public function testCustomOptions(): void
    {
        // Create hasher with custom options
        $customHasher = new PasswordHasher([
            'cost' => 10, // Lower cost for faster tests
        ]);
        
        // Hash password
        $hash = $customHasher->hash($this->password);
        
        // Verify options were applied (cost parameter is encoded in bcrypt hash)
        $this->assertStringContainsString('$10$', $hash, 'Hash should use custom cost option');
        
        // Verify password still works
        $this->assertTrue($customHasher->verify($this->password, $hash));
    }
}
