<?php

declare(strict_types=1);

namespace Glueful\Serialization;

/**
 * Money Value Object
 *
 * Represents a monetary amount with currency support.
 */
class Money
{
    private int $amount; // Amount in smallest currency unit (e.g., cents)
    private string $currency;

    /**
     * Constructor
     *
     * @param int|float $amount Amount (will be converted to smallest unit)
     * @param string $currency Currency code (ISO 4217)
     */
    public function __construct(int|float $amount, string $currency = 'USD')
    {
        // Convert to smallest unit if float is provided
        $this->amount = is_float($amount) ? (int) round($amount * 100) : $amount;
        $this->currency = strtoupper($currency);
    }

    /**
     * Create from major unit (e.g., dollars)
     */
    public static function fromMajor(float $amount, string $currency = 'USD'): self
    {
        return new self($amount * 100, $currency);
    }

    /**
     * Create from minor unit (e.g., cents)
     */
    public static function fromMinor(int $amount, string $currency = 'USD'): self
    {
        return new self($amount, $currency);
    }

    /**
     * Get amount in smallest unit
     */
    public function getAmount(): int
    {
        return $this->amount;
    }

    /**
     * Get amount in major unit
     */
    public function getMajorAmount(): float
    {
        return $this->amount / 100;
    }

    /**
     * Get currency code
     */
    public function getCurrency(): string
    {
        return $this->currency;
    }

    /**
     * Format money with currency symbol
     */
    public function format(?string $locale = null): string
    {
        $majorAmount = $this->getMajorAmount();

        // Simple formatting - in real implementation, use NumberFormatter
        $symbols = [
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'JPY' => '¥',
        ];

        $symbol = $symbols[$this->currency] ?? $this->currency . ' ';

        if ($this->currency === 'JPY') {
            // JPY doesn't use decimal places
            return $symbol . number_format($this->amount);
        }

        return $symbol . number_format($majorAmount, 2);
    }

    /**
     * Get display amount (formatted without symbol)
     */
    public function getDisplayAmount(): string
    {
        if ($this->currency === 'JPY') {
            return number_format($this->amount);
        }

        return number_format($this->getMajorAmount(), 2);
    }

    /**
     * Add money
     */
    public function add(Money $other): self
    {
        $this->assertSameCurrency($other);
        return new self($this->amount + $other->amount, $this->currency);
    }

    /**
     * Subtract money
     */
    public function subtract(Money $other): self
    {
        $this->assertSameCurrency($other);
        return new self($this->amount - $other->amount, $this->currency);
    }

    /**
     * Multiply by factor
     */
    public function multiply(float $factor): self
    {
        return new self((int) round($this->amount * $factor), $this->currency);
    }

    /**
     * Divide by factor
     */
    public function divide(float $factor): self
    {
        if ($factor == 0) {
            throw new \InvalidArgumentException('Cannot divide by zero');
        }

        return new self((int) round($this->amount / $factor), $this->currency);
    }

    /**
     * Check if equal to another money object
     */
    public function equals(Money $other): bool
    {
        return $this->amount === $other->amount && $this->currency === $other->currency;
    }

    /**
     * Check if greater than another money object
     */
    public function greaterThan(Money $other): bool
    {
        $this->assertSameCurrency($other);
        return $this->amount > $other->amount;
    }

    /**
     * Check if less than another money object
     */
    public function lessThan(Money $other): bool
    {
        $this->assertSameCurrency($other);
        return $this->amount < $other->amount;
    }

    /**
     * Check if zero
     */
    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    /**
     * Check if positive
     */
    public function isPositive(): bool
    {
        return $this->amount > 0;
    }

    /**
     * Check if negative
     */
    public function isNegative(): bool
    {
        return $this->amount < 0;
    }

    /**
     * Assert same currency
     */
    private function assertSameCurrency(Money $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new \InvalidArgumentException(
                "Currency mismatch: {$this->currency} vs {$other->currency}"
            );
        }
    }

    /**
     * String representation
     */
    public function __toString(): string
    {
        return $this->format();
    }
}
