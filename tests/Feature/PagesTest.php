<?php

use App\Enums\PuttContext;
use App\Enums\Putter;
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

    $this->get(route('stats'))->assertOk()->assertSee('No putts logged with the blade yet');
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

it('shows a putter toggle on the stats page', function () {
    Challenge::factory()->create();

    $this->get(route('stats'))
        ->assertOk()
        ->assertSee('Blade')
        ->assertSee('Mallet')
        ->assertSee('Compare');
});

it('scopes the stats page to the requested putter', function () {
    Challenge::factory()->create();

    logMakeRate(40, 100, 12, Putter::Blade);

    $this->get(route('stats', ['putter' => 'mallet']))
        ->assertOk()
        ->assertSee('No putts logged with the mallet yet')
        ->assertDontSee('Make rate by distance');

    $this->get(route('stats', ['putter' => 'blade']))
        ->assertOk()
        ->assertSee('Make rate by distance')
        ->assertDontSee('No putts logged with the blade yet');
});

it('remembers the last putter viewed', function () {
    Challenge::factory()->create();

    $this->get(route('stats', ['putter' => 'mallet']))->assertOk();

    $this->get(route('stats'))
        ->assertOk()
        ->assertSee('Everything below is Mallet only');
});

it('asks for more data on the compare page before recommending', function () {
    logMakeRate(20, 50, 10, Putter::Blade);
    logMakeRate(20, 90, 10, Putter::Mallet);

    $this->get(route('stats.compare'))
        ->assertOk()
        ->assertSee('Keep logging')
        ->assertSee('Not enough data to call it');
});

it('recommends a putter on the compare page once the data supports it', function () {
    logMakeRate(120, 30, 10, Putter::Blade);
    logMakeRate(120, 70, 10, Putter::Mallet);

    $this->get(route('stats.compare'))
        ->assertOk()
        ->assertSee('Recommended')
        ->assertSee('Play the mallet')
        ->assertSee('Head to head')
        ->assertSee('Matched distances');
});

it('shows the putter on each history row', function () {
    PuttingSession::factory()->mallet()->create();

    $this->get(route('sessions.index'))->assertOk()->assertSee('Mallet');
});
