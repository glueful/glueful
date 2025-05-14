<?php

declare(strict_types=1);

namespace Glueful\Extensions\Admin\Components\ConfigUI;

/**
 * Configuration UI Manager
 *
 * Generates UI form components based on schema definitions
 * Used for standardizing extension configuration UI components
 */
class ConfigUIManager
{
    /**
     * Generate configuration form from schema
     *
     * @param array $schema Configuration schema definition
     * @return array Form components ready for rendering
     */
    public static function generateForm(array $schema): array
    {
        $formComponents = [];

        foreach ($schema as $field => $definition) {
            $formComponents[] = self::createFormField($field, $definition);
        }

        return $formComponents;
    }

    /**
     * Create appropriate form field based on definition
     *
     * @param string $field Field name/key
     * @param array $definition Field definition with type, label, etc.
     * @return array Form field component
     */
    private static function createFormField(string $field, array $definition): array
    {
        $type = $definition['type'] ?? 'text';

        return match ($type) {
            'text' => self::createTextField($field, $definition),
            'textarea' => self::createTextareaField($field, $definition),
            'select' => self::createSelectField($field, $definition),
            'boolean', 'toggle' => self::createToggleField($field, $definition),
            'number' => self::createNumberField($field, $definition),
            'color' => self::createColorField($field, $definition),
            'date' => self::createDateField($field, $definition),
            'file' => self::createFileField($field, $definition),
            'group' => self::createGroupField($field, $definition),
            'password' => self::createPasswordField($field, $definition),
            // Default to text field for unknown types
            default => self::createTextField($field, $definition)
        };
    }

    /**
     * Create a text input field
     *
     * @param string $field Field name/key
     * @param array $definition Field definition properties
     * @return array Text field component definition
     */
    private static function createTextField(string $field, array $definition): array
    {
        return [
            'type' => 'text',
            'name' => $field,
            'label' => $definition['label'] ?? self::humanize($field),
            'value' => $definition['value'] ?? '',
            'placeholder' => $definition['placeholder'] ?? '',
            'required' => $definition['required'] ?? false,
            'validation' => $definition['validation'] ?? [],
            'helpText' => $definition['helpText'] ?? '',
            'disabled' => $definition['disabled'] ?? false,
            'className' => $definition['className'] ?? '',
            'attributes' => $definition['attributes'] ?? [],
        ];
    }

    /**
     * Create a textarea field for multi-line text
     *
     * @param string $field Field name/key
     * @param array $definition Field definition properties
     * @return array Textarea field component definition
     */
    private static function createTextareaField(string $field, array $definition): array
    {
        return [
            'type' => 'textarea',
            'name' => $field,
            'label' => $definition['label'] ?? self::humanize($field),
            'value' => $definition['value'] ?? '',
            'placeholder' => $definition['placeholder'] ?? '',
            'required' => $definition['required'] ?? false,
            'validation' => $definition['validation'] ?? [],
            'helpText' => $definition['helpText'] ?? '',
            'rows' => $definition['rows'] ?? 3,
            'disabled' => $definition['disabled'] ?? false,
            'className' => $definition['className'] ?? '',
            'attributes' => $definition['attributes'] ?? [],
        ];
    }

    /**
     * Create a select/dropdown field
     *
     * @param string $field Field name/key
     * @param array $definition Field definition properties
     * @return array Select field component definition
     */
    private static function createSelectField(string $field, array $definition): array
    {
        return [
            'type' => 'select',
            'name' => $field,
            'label' => $definition['label'] ?? self::humanize($field),
            'value' => $definition['value'] ?? '',
            'options' => $definition['options'] ?? [],
            'required' => $definition['required'] ?? false,
            'validation' => $definition['validation'] ?? [],
            'helpText' => $definition['helpText'] ?? '',
            'multiple' => $definition['multiple'] ?? false,
            'disabled' => $definition['disabled'] ?? false,
            'className' => $definition['className'] ?? '',
            'attributes' => $definition['attributes'] ?? [],
        ];
    }

    /**
     * Create a toggle/boolean field
     *
     * @param string $field Field name/key
     * @param array $definition Field definition properties
     * @return array Toggle field component definition
     */
    private static function createToggleField(string $field, array $definition): array
    {
        return [
            'type' => 'toggle',
            'name' => $field,
            'label' => $definition['label'] ?? self::humanize($field),
            'value' => $definition['value'] ?? false,
            'helpText' => $definition['helpText'] ?? '',
            'onText' => $definition['onText'] ?? 'Yes',
            'offText' => $definition['offText'] ?? 'No',
            'disabled' => $definition['disabled'] ?? false,
            'className' => $definition['className'] ?? '',
            'attributes' => $definition['attributes'] ?? [],
        ];
    }

    /**
     * Create a number input field
     *
     * @param string $field Field name/key
     * @param array $definition Field definition properties
     * @return array Number field component definition
     */
    private static function createNumberField(string $field, array $definition): array
    {
        return [
            'type' => 'number',
            'name' => $field,
            'label' => $definition['label'] ?? self::humanize($field),
            'value' => $definition['value'] ?? 0,
            'placeholder' => $definition['placeholder'] ?? '',
            'required' => $definition['required'] ?? false,
            'validation' => $definition['validation'] ?? [],
            'helpText' => $definition['helpText'] ?? '',
            'min' => $definition['min'] ?? null,
            'max' => $definition['max'] ?? null,
            'step' => $definition['step'] ?? 1,
            'disabled' => $definition['disabled'] ?? false,
            'className' => $definition['className'] ?? '',
            'attributes' => $definition['attributes'] ?? [],
        ];
    }

    /**
     * Create a color picker field
     *
     * @param string $field Field name/key
     * @param array $definition Field definition properties
     * @return array Color field component definition
     */
    private static function createColorField(string $field, array $definition): array
    {
        return [
            'type' => 'color',
            'name' => $field,
            'label' => $definition['label'] ?? self::humanize($field),
            'value' => $definition['value'] ?? '#000000',
            'helpText' => $definition['helpText'] ?? '',
            'required' => $definition['required'] ?? false,
            'disabled' => $definition['disabled'] ?? false,
            'className' => $definition['className'] ?? '',
            'attributes' => $definition['attributes'] ?? [],
        ];
    }

    /**
     * Create a date input field
     *
     * @param string $field Field name/key
     * @param array $definition Field definition properties
     * @return array Date field component definition
     */
    private static function createDateField(string $field, array $definition): array
    {
        return [
            'type' => 'date',
            'name' => $field,
            'label' => $definition['label'] ?? self::humanize($field),
            'value' => $definition['value'] ?? '',
            'placeholder' => $definition['placeholder'] ?? '',
            'required' => $definition['required'] ?? false,
            'validation' => $definition['validation'] ?? [],
            'helpText' => $definition['helpText'] ?? '',
            'min' => $definition['min'] ?? null,
            'max' => $definition['max'] ?? null,
            'disabled' => $definition['disabled'] ?? false,
            'className' => $definition['className'] ?? '',
            'attributes' => $definition['attributes'] ?? [],
        ];
    }

    /**
     * Create a file upload field
     *
     * @param string $field Field name/key
     * @param array $definition Field definition properties
     * @return array File field component definition
     */
    private static function createFileField(string $field, array $definition): array
    {
        return [
            'type' => 'file',
            'name' => $field,
            'label' => $definition['label'] ?? self::humanize($field),
            'accept' => $definition['accept'] ?? '*/*',
            'required' => $definition['required'] ?? false,
            'validation' => $definition['validation'] ?? [],
            'helpText' => $definition['helpText'] ?? '',
            'multiple' => $definition['multiple'] ?? false,
            'maxSize' => $definition['maxSize'] ?? null, // in bytes
            'disabled' => $definition['disabled'] ?? false,
            'className' => $definition['className'] ?? '',
            'attributes' => $definition['attributes'] ?? [],
        ];
    }

    /**
     * Create a group of fields
     *
     * @param string $field Field name/key
     * @param array $definition Field definition properties
     * @return array Group field component definition
     */
    private static function createGroupField(string $field, array $definition): array
    {
        $fields = [];

        if (isset($definition['fields']) && is_array($definition['fields'])) {
            foreach ($definition['fields'] as $subField => $subDefinition) {
                $fields[] = self::createFormField($subField, $subDefinition);
            }
        }

        return [
            'type' => 'group',
            'name' => $field,
            'label' => $definition['label'] ?? self::humanize($field),
            'helpText' => $definition['helpText'] ?? '',
            'fields' => $fields,
            'collapsible' => $definition['collapsible'] ?? false,
            'collapsed' => $definition['collapsed'] ?? false,
            'className' => $definition['className'] ?? '',
            'attributes' => $definition['attributes'] ?? [],
        ];
    }

    /**
     * Create a password input field with masked value
     *
     * @param string $field Field name/key
     * @param array $definition Field definition properties
     * @return array Password field component definition
     */
    private static function createPasswordField(string $field, array $definition): array
    {
        return [
            'type' => 'password',
            'name' => $field,
            'label' => $definition['label'] ?? self::humanize($field),
            'value' => $definition['value'] ?? '',
            'placeholder' => $definition['placeholder'] ?? '',
            'required' => $definition['required'] ?? false,
            'validation' => $definition['validation'] ?? [],
            'helpText' => $definition['helpText'] ?? '',
            'showToggle' => $definition['showToggle'] ?? true, // Toggle to show/hide password
            'disabled' => $definition['disabled'] ?? false,
            'className' => $definition['className'] ?? '',
            'attributes' => $definition['attributes'] ?? [],
        ];
    }

    /**
     * Convert a field name to a human-readable label
     *
     * @param string $field Field name/key
     * @return string Human-readable label
     */
    private static function humanize(string $field): string
    {
        // Convert snake_case or camelCase to Title Case with spaces
        $label = preg_replace('/[_]+/', ' ', $field);
        $label = preg_replace('/([a-z])([A-Z])/', '$1 $2', $label);
        $label = ucfirst($label);

        return $label;
    }
}
