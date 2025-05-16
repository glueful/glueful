<?php
namespace Tests\Unit\Helpers;

use Glueful\Auth\AuthenticationService;

/**
 * Test helper to override static methods for testing purposes
 * This approach avoids modifying original files while allowing tests to run properly
 */
class AuthTestHelper
{
    /**
     * @var string|null The mocked token value for testing
     */
    private static ?string $mockedToken = null;

    /**
     * Set the mocked token value to be returned by extractTokenFromRequest
     *
     * @param string|null $token The token value to return
     */
    public static function setMockedToken(?string $token): void
    {
        self::$mockedToken = $token;
    }

    /**
     * Mock implementation of the extractTokenFromRequest method
     *
     * @return string|null The mocked token value
     */
    public static function extractTokenFromRequest(): ?string
    {
        return self::$mockedToken;
    }
}
