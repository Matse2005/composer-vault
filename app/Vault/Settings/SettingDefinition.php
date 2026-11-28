<?php

namespace App\Vault\Settings;

/**
 * What the vault knows about one setting: where it is edited, which permission edits it, whether a change is critical
 * (guarded and, when enabled, approved by a second Owner), whether it holds a secret and which environment variable
 * overrides it.
 */
final readonly class SettingDefinition
{
    /**
     * @param  list<mixed>  $rules
     */
    public function __construct(
        public string $key,
        public string $area,
        public string $permission,
        public bool $isCritical,
        public bool $isSecret,
        public ?string $envVariable,
        public mixed $default,
        public array $rules,
    ) {}
}
