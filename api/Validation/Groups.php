<?php

declare(strict_types=1);

namespace Glueful\Validation;

/**
 * Validation Groups
 *
 * Defines common validation groups for context-aware validation.
 * Groups allow different validation rules to be applied in different scenarios.
 *
 * Example usage:
 *
 * ```php
 * use Glueful\Validation\Groups;
 * use Glueful\Validation\Constraints\Required;
 *
 * class UserDTO {
 *     #[Required(groups: [Groups::CREATE])]
 *     public string $password;
 *
 *     #[Required(groups: [Groups::CREATE, Groups::UPDATE])]
 *     public string $email;
 * }
 *
 * // Validate only for creation
 * $validator->validate($user, [Groups::CREATE]);
 * ```
 */
class Groups
{
    /** @var string Default validation group (always applied) */
    public const DEFAULT = 'Default';

    /** @var string Group for create operations */
    public const CREATE = 'Create';

    /** @var string Group for update operations */
    public const UPDATE = 'Update';

    /** @var string Group for delete operations */
    public const DELETE = 'Delete';

    /** @var string Group for partial updates (PATCH operations) */
    public const PATCH = 'Patch';

    /** @var string Group for admin-only validation */
    public const ADMIN = 'Admin';

    /** @var string Group for user-facing validation */
    public const USER = 'User';

    /** @var string Group for API validation */
    public const API = 'Api';

    /** @var string Group for web form validation */
    public const WEB = 'Web';

    /** @var string Group for import operations */
    public const IMPORT = 'Import';

    /** @var string Group for export operations */
    public const EXPORT = 'Export';

    /** @var string Group for strict validation */
    public const STRICT = 'Strict';

    /** @var string Group for lenient validation */
    public const LENIENT = 'Lenient';

    /**
     * Get all predefined groups
     *
     * @return array<string> List of all group constants
     */
    public static function all(): array
    {
        return [
            self::DEFAULT,
            self::CREATE,
            self::UPDATE,
            self::DELETE,
            self::PATCH,
            self::ADMIN,
            self::USER,
            self::API,
            self::WEB,
            self::IMPORT,
            self::EXPORT,
            self::STRICT,
            self::LENIENT,
        ];
    }

    /**
     * Get CRUD operation groups
     *
     * @return array<string> CRUD operation groups
     */
    public static function crud(): array
    {
        return [
            self::CREATE,
            self::UPDATE,
            self::DELETE,
            self::PATCH,
        ];
    }

    /**
     * Get user context groups
     *
     * @return array<string> User context groups
     */
    public static function userContext(): array
    {
        return [
            self::ADMIN,
            self::USER,
        ];
    }

    /**
     * Get interface groups
     *
     * @return array<string> Interface groups
     */
    public static function interfaces(): array
    {
        return [
            self::API,
            self::WEB,
        ];
    }

    /**
     * Check if a group name is valid
     *
     * @param string $group Group name to validate
     * @return bool True if the group is predefined
     */
    public static function isValid(string $group): bool
    {
        return in_array($group, self::all(), true);
    }

    /**
     * Combine multiple group arrays
     *
     * @param array<string> ...$groupArrays Arrays of group names
     * @return array<string> Combined unique groups
     */
    public static function combine(array ...$groupArrays): array
    {
        $combined = [];
        foreach ($groupArrays as $groups) {
            $combined = array_merge($combined, $groups);
        }

        return array_unique($combined);
    }

    /**
     * Create a custom group combination for common scenarios
     *
     * @param string $operation Operation type (create, update, etc.)
     * @param string $context Context (admin, user, api, web)
     * @return array<string> Group combination
     */
    public static function for(string $operation, string $context = 'user'): array
    {
        $groups = [self::DEFAULT];

        // Add operation group
        $operationGroup = match (strtolower($operation)) {
            'create', 'post' => self::CREATE,
            'update', 'put' => self::UPDATE,
            'patch' => self::PATCH,
            'delete' => self::DELETE,
            'import' => self::IMPORT,
            'export' => self::EXPORT,
            default => null,
        };

        if ($operationGroup) {
            $groups[] = $operationGroup;
        }

        // Add context group
        $contextGroup = match (strtolower($context)) {
            'admin' => self::ADMIN,
            'user' => self::USER,
            'api' => self::API,
            'web' => self::WEB,
            'strict' => self::STRICT,
            'lenient' => self::LENIENT,
            default => null,
        };

        if ($contextGroup) {
            $groups[] = $contextGroup;
        }

        return array_unique($groups);
    }
}
