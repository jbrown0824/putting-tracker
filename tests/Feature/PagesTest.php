<?php

use App\Enums\PuttContext;
use App\Enums\PuttResult;
use App\Models\Challenge;
use App\Models\Putt;
use App\Models\PuttingSession;

it('renders the log screen with the dial', function () {
    Challenge::factory()->create();

    $this->get(route('log'))
        ->assertOk()
        ->assertSee('SUNK')
        ->assertSee('LONG')
        ->assertSee('SHORT')
        ->assertSee('LEFT')
        ->assertSee('RIGHT')
        ->assertSee('Outside');
});

it('renders the log screen when no challenge exists', function () {
    $this->get(route('log'))->assertOk()->assertSee('No challenge configured');
});

it('renders the stats page with data', function () {
    Challenge::factory()->create();
    $session = PuttingSession::factory()->create();
    Putt::factory()->count(30)->create([
        'putting_session_id' => $session->id,
        'result' => PuttResult::MissShort,
        'distance_ft' => 12,
        'context' => PuttContext::Inside,
    ]);

    $this->get(route('stats'))
        ->assertOk()
        ->assertSee('Your miss pattern')
        ->assertSee('Speed vs. line')
        ->assertSee('Make rate by distance');
});

it('renders the stats page with no putts', function () {
    Challenge::factory()->create();

    $this->get(route('stats'))->assertOk()->assertSee('No putts logged yet');
});

it('lists sessions with make rates', function () {
    $session = PuttingSession::factory()->create(['context' => PuttContext::Outside]);
    Putt::factory()->count(4)->create([
        'putting_session_id' => $session->id,
        'result' => PuttResult::Sunk,
        'context' => PuttContext::Outside,
    ]);

    $this->get(route('sessions.index'))
        ->assertOk()
        ->assertSee('4 putts')
        ->assertSee('Outside');
});

it('shows a session and deletes a putt from it', function () {
    $session = PuttingSession::factory()->create();
    $putt = Putt::factory()->create(['putting_session_id' => $session->id]);

    $this->get(route('sessions.show', $session))->assertOk();

    $this->delete(route('sessions.putts.destroy', [$session, $putt]))
        ->assertRedirect();

    expect(Putt::count())->toBe(0);
});

it('refuses to delete a putt through the wrong session', function () {
    $session = PuttingSession::factory()->create();
    $other = PuttingSession::factory()->create();
    $putt = Putt::factory()->create(['putting_session_id' => $other->id]);

    $this->delete(route('sessions.putts.destroy', [$session, $putt]))->assertNotFound();

    expect(Putt::count())->toBe(1);
});

it('deletes a session and its putts', function () {
    $session = PuttingSession::factory()->create();
    Putt::factory()->count(3)->create(['putting_session_id' => $session->id]);

    $this->delete(route('sessions.destroy', $session))->assertRedirect(route('sessions.index'));

    expect(PuttingSession::count())->toBe(0)
        ->and(Putt::count())->toBe(0);
});
