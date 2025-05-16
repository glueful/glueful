<?php
namespace Tests\Unit\Validation;

use Tests\TestCase;
use Glueful\Validation\Validator;
use Glueful\Validation\Attributes\Rules;

/**
 * Tests for custom validation rules
 */
class CustomRulesTest extends TestCase
{
    private Validator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new Validator();
        $this->validator->reset(); // Ensure errors are cleared

        // Register custom validation rules
        $this->registerCustomRules();
    }

    /**
     * Register test custom validation rules
     */
    private function registerCustomRules(): void
    {
        // Register a rule to check if a value is a valid US phone number
        $this->validator->addRule('us_phone', function($value) {
            // Very simplified US phone validation
            return preg_match('/^\d{3}-\d{3}-\d{4}$/', $value);
        }, 'The :attribute must be a valid US phone number (format: XXX-XXX-XXXX).');

        // Register a rule to check if a string is a palindrome
        $this->validator->addRule('palindrome', function($value) {
            $value = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $value));
            return $value === strrev($value);
        }, 'The :attribute must be a palindrome.');

        // Register a rule for validating strong passwords
        $this->validator->addRule('strong_password', function($value) {
            // Password must have at least 8 characters, 1 uppercase, 1 lowercase, 1 number
            return preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/', $value);
        }, 'The :attribute must be at least 8 characters and contain uppercase, lowercase, and numbers.');
    }

    /**
     * Test custom US phone number validation
     */
    public function testCustomPhoneValidation(): void
    {
        $validDTO = new PhoneTestDTO();
        $validDTO->phone = '555-123-4567';

        $result = $this->validator->validate($validDTO);
        $this->assertTrue($result);
        $this->assertEmpty($this->validator->errors());

        $invalidDTO = new PhoneTestDTO();
        $invalidDTO->phone = '5551234567';  // Missing dashes

        $result = $this->validator->validate($invalidDTO);
        $this->assertFalse($result);
        $this->assertArrayHasKey('phone', $this->validator->errors());
    }

    /**
     * Test custom palindrome validation
     */
    public function testPalindromeValidation(): void
    {
        $validDTO = new PalindromeTestDTO();

        // Test valid palindromes
        $validDTO->text = 'racecar';
        $result = $this->validator->validate($validDTO);
        $this->assertTrue($result);

        $validDTO->text = 'A man a plan a canal Panama';  // Ignores spaces
        $result = $this->validator->validate($validDTO);
        $this->assertTrue($result);

        // Test invalid palindromes
        $invalidDTO = new PalindromeTestDTO();
        $invalidDTO->text = 'hello world';

        $result = $this->validator->validate($invalidDTO);
        $this->assertFalse($result);
        $this->assertArrayHasKey('text', $this->validator->errors());
    }

    /**
     * Test custom password strength validation
     */
    public function testPasswordStrengthValidation(): void
    {
        $dto = new PasswordTestDTO();

        // Test invalid passwords
        $invalidPasswords = [
            'short',           // Too short
            'onlylowercase',   // No uppercase or numbers
            'ALLUPPERCASE',    // No lowercase or numbers
            '12345678',        // No letters
        ];

        foreach ($invalidPasswords as $password) {
            $dto->password = $password;
            $result = $this->validator->validate($dto);
            $this->assertFalse($result, "Password '$password' should fail validation");
            $this->assertArrayHasKey('password', $this->validator->errors());
        }

        // Test valid password
        $dto->password = 'SecureP4ssword';
        $result = $this->validator->validate($dto);
        $this->assertTrue($result, "Password 'SecureP4ssword' should pass validation");
        $this->assertEmpty($this->validator->errors());
    }

    /**
     * Test combining multiple custom rules
     */
    public function testCombiningCustomRules(): void
    {
        $registrationDTO = new RegistrationTestDTO();
        $registrationDTO->username = 'user123';
        $registrationDTO->password = 'weak';  // Too weak
        $registrationDTO->phone = '123456789';  // Invalid format

        $result = $this->validator->validate($registrationDTO);
        $this->assertFalse($result);

        // Should have both password and phone errors
        $errors = $this->validator->errors();
        $this->assertCount(2, $errors);
        $this->assertArrayHasKey('password', $errors);
        $this->assertArrayHasKey('phone', $errors);

        // Fix both errors
        $registrationDTO->password = 'StrongP4ss';
        $registrationDTO->phone = '555-123-4567';

        $result = $this->validator->validate($registrationDTO);
        $this->assertTrue($result);
        $this->assertEmpty($this->validator->errors());
    }
}

/**
 * Phone test DTO
 */
class PhoneTestDTO
{
    #[Rules(['us_phone'])]
    public string $phone;
}

/**
 * Palindrome test DTO
 */
class PalindromeTestDTO
{
    #[Rules(['palindrome'])]
    public string $text;
}

/**
 * Password test DTO
 */
class PasswordTestDTO
{
    #[Rules(['strong_password'])]
    public string $password;
}

/**
 * Registration test DTO with multiple custom rules
 */
class RegistrationTestDTO
{
    #[Rules(['required', 'string'])]
    public string $username;

    #[Rules(['required', 'strong_password'])]
    public string $password;

    #[Rules(['us_phone'])]
    public string $phone;
}
