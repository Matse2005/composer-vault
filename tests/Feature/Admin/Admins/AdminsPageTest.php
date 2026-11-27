<?php

use App\Models\AuditEntry;
use App\Models\Invitation;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->withTwoFactor()->create(['name' => 'Alice Admin', 'email' => 'alice@example.test']);
    $this->actingAs($this->admin);
});

it('lists active admins with their second factor', function () {
    User::factory()->withTwoFactor()->create(['name' => 'Bob Builder']);
    User::factory()->create(['name' => 'Carol NoFactor']);

    $component = Livewire::test('pages::admins.index');

    expect($component->get('activeAdmins'))->toHaveCount(3);

    $component->assertSee('Alice Admin')->assertSee('Bob Builder')->assertSee('Carol NoFactor')
        ->assertSee('Authenticator app')->assertSee('None yet');
});

it('lists deactivated admins separately and not among the active ones', function () {
    $gone = User::factory()->withTwoFactor()->create(['name' => 'Dave Gone']);
    $gone->forceFill(['deactivated_at' => now()])->save();

    $component = Livewire::test('pages::admins.index');

    expect($component->get('activeAdmins')->pluck('name')->all())->toBe(['Alice Admin'])
        ->and($component->get('deactivatedAdmins')->pluck('name')->all())->toBe(['Dave Gone']);
    $component->assertSee('Deactivated admins');
});

it('shows no deactivated section when nobody is deactivated', function () {
    Livewire::test('pages::admins.index')->assertDontSee('Deactivated admins');
});

it('invites an admin and shows the link once', function () {
    config(['mail.default' => 'log']);

    $component = Livewire::test('pages::admins.index')
        ->set('email', 'new@example.test')
        ->call('invite')
        ->assertHasNoErrors();

    $url = $component->get('invitationUrl');
    expect($url)->toStartWith(url('/invitations/'))
        ->and($component->html())->toContain($url)->toContain('No email was sent')
        ->and(Invitation::query()->where('email', 'new@example.test')->exists())->toBeTrue()
        ->and(AuditEntry::query()->where('event', 'admin_invited')->count())->toBe(1);

    $component->call('closeInvitationModal');

    expect($component->get('invitationUrl'))->toBeNull()
        ->and($component->html())->not->toContain($url);
});

it('rejects an address that already belongs to an admin or has an open invitation', function () {
    Livewire::test('pages::admins.index')
        ->set('email', 'ALICE@example.test')
        ->call('invite')
        ->assertHasErrors('email')
        ->assertSet('invitationUrl', null);

    Livewire::test('pages::admins.index')->set('email', 'new@example.test')->call('invite')->call('closeInvitationModal')
        ->set('email', 'new@example.test')->call('invite')
        ->assertHasErrors('email');
});

it('validates the invitation email', function () {
    Livewire::test('pages::admins.index')->set('email', 'not-an-email')->call('invite')->assertHasErrors('email');
    Livewire::test('pages::admins.index')->set('email', '')->call('invite')->assertHasErrors('email');

    expect(Invitation::query()->count())->toBe(0);
});

it('does not keep the invitation link in the snapshot of a fresh page', function () {
    $component = Livewire::test('pages::admins.index')->set('email', 'new@example.test')->call('invite');
    $url = $component->get('invitationUrl');

    $fresh = Livewire::test('pages::admins.index');

    expect($fresh->get('invitationUrl'))->toBeNull()->and($fresh->html())->not->toContain($url);
});

it('lists pending invitations and revokes one', function () {
    $invitation = Invitation::factory()->create(['email' => 'pending@example.test', 'invited_by' => $this->admin->id]);
    Invitation::factory()->create(['email' => 'revoked@example.test', 'revoked_at' => now()]);
    Invitation::factory()->create(['email' => 'accepted@example.test', 'accepted_at' => now()]);

    $component = Livewire::test('pages::admins.index')
        ->assertSee('pending@example.test')
        ->assertDontSee('revoked@example.test')
        ->assertDontSee('accepted@example.test');

    $component->call('revokeInvitation', $invitation->id)->assertDontSee('pending@example.test');

    expect($invitation->fresh()->revoked_at)->not->toBeNull()
        ->and(AuditEntry::query()->where('event', 'invitation_revoked')->count())->toBe(1);
});

it('shows an empty state without pending invitations', function () {
    Livewire::test('pages::admins.index')->assertSee('There are no pending invitations.');
});

it('deactivates another admin', function () {
    $other = User::factory()->withTwoFactor()->create(['name' => 'Bob Builder']);

    $component = Livewire::test('pages::admins.index')->call('deactivate', $other->id);

    expect($other->fresh()->isDeactivated())->toBeTrue()
        ->and($component->get('activeAdmins')->pluck('name')->all())->toBe(['Alice Admin'])
        ->and(AuditEntry::query()->where('event', 'admin_deactivated')->count())->toBe(1);
});

it('refuses to deactivate yourself', function () {
    User::factory()->create();

    Livewire::test('pages::admins.index')->call('deactivate', $this->admin->id)->assertHasErrors('deactivate');

    expect($this->admin->fresh()->isDeactivated())->toBeFalse();
});

it('offers no deactivate button for yourself', function () {
    $other = User::factory()->withTwoFactor()->create();

    $html = Livewire::test('pages::admins.index')->html();

    expect($html)->toContain('deactivate('.$other->id.')')->not->toContain('deactivate('.$this->admin->id.')');
});

it('renders the page', function () {
    $this->get(route('admins.index'))->assertOk()->assertSeeLivewire('pages::admins.index');
});
