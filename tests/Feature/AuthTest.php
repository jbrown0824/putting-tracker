<?php

use App\Models\User;

it('shows the landing page to guests', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Create account')
        ->assertSee('Log in');
});

it('sends a signed-in player straight to the logger', function () {
    $this->actingAs(testUser())->get(route('home'))->assertRedirect(route('log'));
});

it('sends guests to the login page', function (string $route) {
    $this->get(route($route))->assertRedirect(route('login'));
})->with(['log', 'stats', 'stats.compare', 'sessions.index', 'settings']);

it('registers a player with their time zone and a first putter', function () {
    $this->post(route('register'), [
        'name' => 'Jeff',
        'email' => 'jeff@example.com',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
        'timezone' => 'America/Chicago',
    ])->assertRedirect(route('settings'));

    $user = User::query()->where('email', 'jeff@example.com')->sole();

    $this->assertAuthenticatedAs($user);
    expect($user->timezone)->toBe('America/Chicago')
        ->and($user->putters()->count())->toBe(1)
        ->and($user->putters()->first()->is_default)->toBeTrue();
});

it('registers on UTC when the browser sends no time zone', function () {
    $this->post(route('register'), [
        'name' => 'Jeff',
        'email' => 'jeff@example.com',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ])->assertRedirect();

    expect(User::query()->sole()->timezone)->toBe('UTC');
});

it('refuses a duplicate email or a nonsense time zone', function () {
    User::factory()->create(['email' => 'jeff@example.com']);

    $this->post(route('register'), [
        'name' => 'Jeff',
        'email' => 'jeff@example.com',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
        'timezone' => 'Mars/Olympus_Mons',
    ])->assertSessionHasErrors(['email', 'timezone']);
});

it('logs a player in and out', function () {
    $user = User::factory()->create(['password' => 'correct-horse-battery']);

    $this->post(route('login'), ['email' => $user->email, 'password' => 'correct-horse-battery'])
        ->assertRedirect(route('log'));
    $this->assertAuthenticatedAs($user);

    $this->post(route('logout'))->assertRedirect(route('home'));
    $this->assertGuest();
});

it('rejects a wrong password', function () {
    $user = User::factory()->create(['password' => 'correct-horse-battery']);

    $this->post(route('login'), ['email' => $user->email, 'password' => 'wrong'])
        ->assertSessionHasErrors('email');
    $this->assertGuest();
});

it('locks the login form after repeated failures', function () {
    $user = User::factory()->create(['password' => 'correct-horse-battery']);

    foreach (range(1, 5) as $attempt) {
        $this->post(route('login'), ['email' => $user->email, 'password' => 'wrong']);
    }

    $this->post(route('login'), ['email' => $user->email, 'password' => 'correct-horse-battery'])
        ->assertSessionHasErrors('email');
    $this->assertGuest();
});

it('updates the account time zone', function () {
    $this->actingAs(testUser())
        ->patch(route('settings.account'), ['name' => 'Jeff', 'timezone' => 'Europe/London'])
        ->assertRedirect(route('settings'));

    expect(testUser()->timezone)->toBe('Europe/London');
});
