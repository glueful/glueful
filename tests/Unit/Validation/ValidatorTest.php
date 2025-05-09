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
