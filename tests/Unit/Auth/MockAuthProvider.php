<?php

namespace Tests\Unit\Auth;

use Symfony\Component\HttpFoundation\Request;
use Glueful\Auth\AuthenticationProviderInterface;

/**
 * Mock authentication provider for testing
 */
class MockAuthProvider implements AuthenticationProviderInterface
{
    private ?array $userData = null;
    private ?string $error = null;
    private bool $adminStatus = false;
    private bool $tokenValidity = true;
    private bool $canHandleTokenStatus = true;
    private array $generatedTokens = [];
    private ?array $refreshedTokens = null;

    /**
     * Set the user data this provider will return
     */
    public function setUserData(?array $userData): self
    {
        $this->userData = $userData;
        return $this;
    }

    /**
     * Set the error message this provider will return
     */
    public function setError(?string $error): self
    {
        $this->error = $error;
        return $this;
    }

    /**
     * Set the admin status this provider will report
     */
    public function setAdminStatus(bool $isAdmin): self
    {
        $this->adminStatus = $isAdmin;
        return $this;
    }

    /**
     * Set whether token validation succeeds
     */
    public function setTokenValidity(bool $isValid): self
    {
        $this->tokenValidity = $isValid;
        return $this;
    }

    /**
     * Set whether this provider can handle tokens
     */
    public function setCanHandleToken(bool $canHandle): self
    {
        $this->canHandleTokenStatus = $canHandle;
        return $this;
    }

    /**
     * Set tokens to return from generateTokens
     */
    public function setGeneratedTokens(array $tokens): self
    {
        $this->generatedTokens = $tokens;
        return $this;
    }

    /**
     * Set tokens to return from refreshTokens
     */
    public function setRefreshedTokens(?array $tokens): self
    {
        $this->refreshedTokens = $tokens;
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function authenticate(Request $request): ?array
    {
        return $this->userData;
    }

    /**
     * {@inheritDoc}
     */
    public function isAdmin(array $userData): bool
    {
        return $this->adminStatus;
    }

    /**
     * {@inheritDoc}
     */
    public function getError(): ?string
    {
        return $this->error;
    }

    /**
     * {@inheritDoc}
     */
    public function validateToken(string $token): bool
    {
        return $this->tokenValidity;
    }

    /**
     * {@inheritDoc}
     */
    public function canHandleToken(string $token): bool
    {
        return $this->canHandleTokenStatus;
    }

    /**
     * {@inheritDoc}
     */
    public function generateTokens(
        array $userData,
        ?int $accessTokenLifetime = null,
        ?int $refreshTokenLifetime = null
    ): array
    {
        if (empty($this->generatedTokens)) {
            return [
                'access_token' => 'mock-access-token-' . uniqid(),
                'refresh_token' => 'mock-refresh-token-' . uniqid(),
                'expires_in' => $accessTokenLifetime ?? 3600,
                'token_type' => 'Bearer'
            ];
        }

        return $this->generatedTokens;
    }

    /**
     * {@inheritDoc}
     */
    public function refreshTokens(string $refreshToken, array $sessionData): ?array
    {
        return $this->refreshedTokens ?? [
            'access_token' => 'mock-refreshed-access-token-' . uniqid(),
            'refresh_token' => 'mock-refreshed-refresh-token-' . uniqid(),
            'expires_in' => 3600,
            'token_type' => 'Bearer'
        ];
    }
}
