<?php

namespace App\Livewire\Concerns;

use App\Enums\CredentialType;
use App\Exceptions\Vault\HostConflictException;
use App\Models\Project;
use App\Vault\HostName;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\Attributes\Computed;

/**
 * The form of a credential, shared by the create and the edit page.
 *
 * The secret is write-only: it is validated and then removed from the component state in every case, including a
 * rejected form, so it never ends up in the Livewire snapshot or in the rendered HTML. After a failed save the admin
 * types the secret again.
 */
trait HasCredentialForm
{
    public string $name = '';

    public string $type = 'http-basic';

    public string $host = '';

    public string $username = '';

    public string $notes = '';

    public string $secret = '';

    /** @var array<int, string> */
    public array $projectIds = [];

    #[Computed]
    public function requiresUsername(): bool
    {
        return CredentialType::tryFrom($this->type)?->requiresUsername() ?? false;
    }

    /**
     * @return Collection<int, Project>
     */
    #[Computed]
    public function projects(): Collection
    {
        return Project::query()->orderBy('name')->get(['id', 'name', 'slug']);
    }

    /**
     * Validate the form and clear the secret from the component state, whatever the outcome.
     *
     * @return array<string, mixed>
     */
    protected function validateCredentialForm(bool $secretRequired): array
    {
        try {
            return $this->validate([
                'name' => ['required', 'string', 'max:100'],
                'type' => ['required', Rule::enum(CredentialType::class)],
                'host' => ['required', 'string', 'max:255', function (string $attribute, mixed $value, Closure $fail): void {
                    try {
                        HostName::normalize((string) $value);
                    } catch (InvalidArgumentException) {
                        $fail(__('Enter a plain hostname such as repo.magento.com, without port, credentials or spaces.'));
                    }
                }],
                'username' => [Rule::requiredIf($this->requiresUsername), 'nullable', 'string', 'max:255'],
                'notes' => ['nullable', 'string', 'max:5000'],
                'secret' => [$secretRequired ? 'required' : 'nullable', 'string', 'max:10000'],
                'projectIds' => ['array'],
                'projectIds.*' => ['string', Rule::exists('projects', 'id')],
            ]);
        } finally {
            $this->secret = '';
        }
    }

    /**
     * The attributes the actions take. A username is only kept for the types that use one.
     *
     * @param  array<string, mixed>  $validated
     * @return array{name: string, type: string, host: string, username: string|null, notes: string|null}
     */
    protected function credentialAttributes(array $validated): array
    {
        $requiresUsername = CredentialType::from($validated['type'])->requiresUsername();

        return [
            'name' => $validated['name'],
            'type' => $validated['type'],
            'host' => $validated['host'],
            'username' => $requiresUsername ? $validated['username'] : null,
            'notes' => ($validated['notes'] ?? '') === '' ? null : $validated['notes'],
        ];
    }

    protected function hostConflictMessage(HostConflictException $exception): string
    {
        return __('The project :project already uses a credential for :host.', ['project' => $exception->projectName, 'host' => $exception->host]);
    }
}
