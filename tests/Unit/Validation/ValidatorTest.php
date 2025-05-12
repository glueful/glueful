<?php
namespace Tests\Unit\Validation;

use Tests\TestCase;
use Glueful\Validation\Validator;
use Glueful\Validation\Attributes\Rules;
use Glueful\Validation\Attributes\Sanitize;

/**
 * Tests for the Validation system
 */
class ValidatorTest extends TestCase
{
    private Validator $validator;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new Validator();
    }
    
    /**
     * Test basic validation rules: required
     */
    public function testRequiredValidation(): void
    {
        // Create an incomplete DTO missing a required field
        $incompleteUser = new UserDTO();
        $incompleteUser->name = 'John Doe';
        $incompleteUser->email = 'john@example.com';
        // age is required but missing
        
        $result = $this->validator->validate($incompleteUser);
        
        $this->assertFalse($result);
        $this->assertNotEmpty($this->validator->errors());
        $this->assertArrayHasKey('age', $this->validator->errors());
        
        // Test with all required fields present
        $completeUser = new UserDTO();
        $completeUser->name = 'John Doe';
        $completeUser->email = 'john@example.com';
        $completeUser->age = 25;
        
        $result = $this->validator->validate($completeUser);
        
        $this->assertTrue($result);
        $this->assertEmpty($this->validator->errors());
    }
    
    /**
     * Test email validation rule
     */
    public function testEmailValidation(): void
    {
        // Create DTO with valid and invalid emails
        $emailTest = new EmailTestDTO();
        $emailTest->email1 = 'valid@example.com';
        $emailTest->email2 = 'not-an-email';
        
        $result = $this->validator->validate($emailTest);
        
        $this->assertFalse($result);
        $this->assertNotEmpty($this->validator->errors());
        $this->assertArrayHasKey('email2', $this->validator->errors());
        $this->assertArrayNotHasKey('email1', $this->validator->errors());
    }
    
    /**
     * Test integer validation rule
     */
    public function testIntegerValidation(): void
    {
        // Create DTO with various integer validations
        $intTest = new IntegerTestDTO();
        $intTest->age1 = 25;
        $intTest->age2 = 25; // Integer attribute forces this to be stored as int
        $intTest->age3 = 'twenty-five'; // This will fail validation
        
        $result = $this->validator->validate($intTest);
        
        $this->assertFalse($result);
        $this->assertNotEmpty($this->validator->errors());
        $this->assertArrayNotHasKey('age1', $this->validator->errors());
        $this->assertArrayNotHasKey('age2', $this->validator->errors());
        $this->assertArrayHasKey('age3', $this->validator->errors());
    }

    /**
     * Test min/max validation rules
     */
    public function testMinMaxValidation(): void
    {
        // Create DTO with boundary values
        $rangeTest = new RangeTestDTO();
        
        // Test with values outside the range
        $rangeTest->shortString = "ab"; // Min is 3, should fail
        $rangeTest->longString = str_repeat("x", 101); // Max is 100, should fail
        $rangeTest->smallNumber = 5; // Min is 10, should fail
        $rangeTest->largeNumber = 55; // Max is 50, should fail
        
        $result = $this->validator->validate($rangeTest);
        
        $this->assertFalse($result);
        $this->assertCount(4, $this->validator->errors());
        $this->assertArrayHasKey('shortString', $this->validator->errors());
        $this->assertArrayHasKey('longString', $this->validator->errors());
        $this->assertArrayHasKey('smallNumber', $this->validator->errors());
        $this->assertArrayHasKey('largeNumber', $this->validator->errors());
        
        // Test with values inside the range
        $validRangeTest = new RangeTestDTO();
        $validRangeTest->shortString = "abc";
        $validRangeTest->longString = str_repeat("x", 100);
        $validRangeTest->smallNumber = 10;
        $validRangeTest->largeNumber = 50;
        
        $result = $this->validator->validate($validRangeTest);
        
        $this->assertTrue($result);
        $this->assertEmpty($this->validator->errors());
    }
    
    /**
     * Test between validation rule
     */
    public function testBetweenValidation(): void
    {
        $betweenTest = new BetweenTestDTO();
        
        // Test with values outside the boundaries
        $betweenTest->score1 = 9; // Under min of 10
        $betweenTest->score2 = 101; // Over max of 100
        // Initialize score3 to prevent uninitialized property error
        $betweenTest->score3 = 50; 
        
        $result = $this->validator->validate($betweenTest);
        
        $this->assertFalse($result);
        $this->assertCount(2, $this->validator->errors());
        $this->assertArrayHasKey('score1', $this->validator->errors());
        $this->assertArrayHasKey('score2', $this->validator->errors());
        
        // Test with values at the boundaries and within range
        $validBetweenTest = new BetweenTestDTO();
        $validBetweenTest->score1 = 10; // Min boundary
        $validBetweenTest->score2 = 100; // Max boundary
        $validBetweenTest->score3 = 55; // Middle of range
        
        $result = $this->validator->validate($validBetweenTest);
        
        $this->assertTrue($result);
        $this->assertEmpty($this->validator->errors());
    }
    
    /**
     * Test in validation rule
     */
    public function testInValidation(): void
    {
        $inTest = new EnumTestDTO();
        
        // Test with invalid value
        $inTest->status = "pending"; // Not in the allowed values
        $result = $this->validator->validate($inTest);
        
        $this->assertFalse($result);
        $this->assertCount(1, $this->validator->errors());
        $this->assertArrayHasKey('status', $this->validator->errors());
        
        // Test with valid values
        $validInTest = new EnumTestDTO();
        $validInTest->status = "active";
        $result = $this->validator->validate($validInTest);
        
        $this->assertTrue($result);
        $this->assertEmpty($this->validator->errors());
    }
    
    /**
     * Test sanitization filters
     */
    public function testSanitizationFilters(): void
    {
        $sanitizeTest = new SanitizationTestDTO();
        $sanitizeTest->trimmedText = "  spaced text  ";
        $sanitizeTest->strippedText = "<p>HTML tags</p>";
        $sanitizeTest->numberText = "42";
        $sanitizeTest->emailText = " user@example.com ";
        
        $this->validator->validate($sanitizeTest);
        
        // Check that sanitization worked
        $this->assertEquals("spaced text", $sanitizeTest->trimmedText);
        $this->assertEquals("HTML tags", $sanitizeTest->strippedText);
        $this->assertEquals(42, $sanitizeTest->numberText);
        $this->assertEquals("user@example.com", $sanitizeTest->emailText);
    }
}

/**
 * User DTO for testing validation
 */
class UserDTO
{
    #[Sanitize(['trim', 'strip_tags'])]
    #[Rules(['required', 'string', 'min:3', 'max:50'])]
    public string $name;
    
    #[Sanitize(['trim', 'sanitize_email'])]
    #[Rules(['required', 'email'])]
    public string $email;
    
    #[Sanitize(['intval'])]
    #[Rules(['required', 'int', 'min:18', 'max:99'])]
    public int $age;
}

/**
 * Email validation test DTO
 */
class EmailTestDTO
{
    #[Rules(['email'])]
    public string $email1;
    
    #[Rules(['email'])]
    public string $email2;
}

/**
 * Integer validation test DTO
 */
class IntegerTestDTO
{
    #[Rules(['int'])]
    public int $age1;
    
    #[Sanitize(['intval'])]
    #[Rules(['int'])]
    public int $age2;
    
    #[Rules(['int'])]
    public string $age3;
}

/**
 * Range validation test DTO
 */
class RangeTestDTO
{
    #[Rules(['string', 'min:3'])]
    public string $shortString;
    
    #[Rules(['string', 'max:100'])]
    public string $longString;
    
    #[Rules(['int', 'min:10'])]
    public int $smallNumber;
    
    #[Rules(['int', 'max:50'])]
    public int $largeNumber;
}

/**
 * Between validation test DTO
 */
class BetweenTestDTO
{
    #[Rules(['int', 'between:10,100'])]
    public int $score1;
    
    #[Rules(['int', 'between:10,100'])]
    public int $score2;
    
    #[Rules(['int', 'between:10,100'])]
    public int $score3;
}

/**
 * Enum validation test DTO
 */
class EnumTestDTO
{
    #[Rules(['string', 'in:active,inactive,archived'])]
    public string $status;
}

/**
 * Sanitization test DTO
 */
class SanitizationTestDTO
{
    #[Sanitize(['trim'])]
    public string $trimmedText;
    
    #[Sanitize(['strip_tags'])]
    public string $strippedText;
    
    #[Sanitize(['intval'])]
    public int $numberText;
    
    #[Sanitize(['trim', 'sanitize_email'])]
    public string $emailText;
}
