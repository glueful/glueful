<?php
namespace Tests\Unit\Validation;

use Tests\TestCase;
use Glueful\Validation\Validator;
use Glueful\Validation\Attributes\Rules;

/**
 * Tests for validation rules
 */
class RulesTest extends TestCase
{
    private Validator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new Validator();
    }

    /**
     * Data provider for testing string validation
     */
    public static function stringValuesProvider(): array
    {
        return [
            'valid string' => ['This is a string', true],
            'empty string' => ['', true], // Empty string is still a string
            'integer as string' => ['123', true],
            'special chars' => ['!@#$%^&*()', true],
            'multi-line string' => ["Line 1\nLine 2", true],
            'utf8 characters' => ['こんにちは', true], // Japanese: Hello
            'null' => [null, false],
            'integer' => [123, false],
            'float' => [123.45, false],
            'boolean' => [true, false],
            'array' => [['item'], false],
        ];
    }

    /**
     * @dataProvider stringValuesProvider
     */
    public function testStringValidation($value, bool $shouldPass): void
    {
        $dto = new StringTestDTO();
        $dto->value = $value;

        $result = $this->validator->validate($dto);

        // Format display value for messages based on type
        $displayValue = is_array($value) ? 'Array' : (is_object($value) ? get_class($value) : (string)$value);

        if ($shouldPass) {
            $this->assertTrue($result, "Value '$displayValue' should pass string validation");
            $this->assertEmpty($this->validator->errors());
        } else {
            $this->assertFalse($result, "Value '$displayValue' should fail string validation");
            $this->assertArrayHasKey('value', $this->validator->errors());
        }
    }

    /**
     * Data provider for testing email validation
     */
    public static function emailValuesProvider(): array
    {
        return [
            'simple valid' => ['user@example.com', true],
            'subdomain' => ['user@sub.example.com', true],
            'plus sign' => ['user+tag@example.com', true],
            'numbers' => ['user123@example.com', true],
            'dash in domain' => ['user@my-site.com', true],
            'uppercase' => ['USER@EXAMPLE.COM', true],
            'no @' => ['userexample.com', false],
            'multiple @' => ['user@site@example.com', false],
            'invalid chars' => ['user!@example.com', false],
            'no domain' => ['user@', false],
            'no username' => ['@example.com', false],
            'spaces' => ['user @example.com', false],
        ];
    }

    /**
     * @dataProvider emailValuesProvider
     */
    public function testEmailValidation($value, bool $shouldPass): void
    {
        $dto = new EmailOnlyTestDTO();
        $dto->email = $value;

        $result = $this->validator->validate($dto);

        if ($shouldPass) {
            $this->assertTrue($result, "Email '$value' should pass validation");
            $this->assertEmpty($this->validator->errors());
        } else {
            $this->assertFalse($result, "Email '$value' should fail validation");
            $this->assertArrayHasKey('email', $this->validator->errors());
        }
    }

    /**
     * Data provider for testing numeric range validation
     */
    public static function numericRangeProvider(): array
    {
        return [
            'within range' => [50, true],
            'at min boundary' => [10, true],
            'at max boundary' => [100, true],
            'below min' => [9, false],
            'above max' => [101, false],
            'zero' => [0, false],
            'negative' => [-10, false],
            'very large' => [9999, false],
        ];
    }

    /**
     * @dataProvider numericRangeProvider
     */
    public function testNumericRangeValidation($value, bool $shouldPass): void
    {
        $dto = new NumericRangeTestDTO();
        $dto->value = $value;

        $result = $this->validator->validate($dto);

        if ($shouldPass) {
            $this->assertTrue($result, "Value '$value' should pass range validation");
            $this->assertEmpty($this->validator->errors());
        } else {
            $this->assertFalse($result, "Value '$value' should fail range validation");
            $this->assertArrayHasKey('value', $this->validator->errors());
        }
    }

    /**
     * Test required validation with various values
     */
    public function testRequiredValidation(): void
    {
        $dto = new RequiredTestDTO();

        // Test with null value (should fail)
        $dto->value = null;
        $result = $this->validator->validate($dto);
        $this->assertFalse($result);
        $this->assertArrayHasKey('value', $this->validator->errors());

        // Test with empty string (should fail)
        $dto->value = '';
        $result = $this->validator->validate($dto);
        $this->assertFalse($result);
        $this->assertArrayHasKey('value', $this->validator->errors());

        // Test with zero (should pass, as it's a non-empty value)
        $dto->value = 0;
        $result = $this->validator->validate($dto);
        $this->assertTrue($result);
        $this->assertEmpty($this->validator->errors());

        // Test with false (should pass, as it's a non-empty value)
        $dto->value = false;
        $result = $this->validator->validate($dto);
        $this->assertTrue($result);
        $this->assertEmpty($this->validator->errors());

        // Test with string (should pass)
        $dto->value = 'value';
        $result = $this->validator->validate($dto);
        $this->assertTrue($result);
        $this->assertEmpty($this->validator->errors());
    }
}

/**
 * String validation test DTO
 */
class StringTestDTO
{
    #[Rules(['string'])]
    public mixed $value; // Using mixed to allow any value for testing
}

/**
 * Email-only validation test DTO
 */
class EmailOnlyTestDTO
{
    #[Rules(['email'])]
    public string $email;
}

/**
 * Numeric range test DTO
 */
class NumericRangeTestDTO
{
    #[Rules(['int', 'between:10,100'])]
    public int $value;
}

/**
 * Required test DTO
 */
class RequiredTestDTO
{
    #[Rules(['required'])]
    public $value;
}
