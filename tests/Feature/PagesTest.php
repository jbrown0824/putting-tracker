<?php

use App\Enums\LineMissCause;
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

it('scopes the stats page to a single session', function () {
    Challenge::factory()->create();

    $tonight = PuttingSession::factory()->create(['started_at' => now()]);
    Putt::factory()->count(12)->create([
        'putting_session_id' => $tonight->id,
        'result' => PuttResult::Sunk,
        'distance_ft' => 10,
    ]);

    $earlier = PuttingSession::factory()->create(['started_at' => now()->subDays(3)]);
    Putt::factory()->count(40)->create([
        'putting_session_id' => $earlier->id,
        'result' => PuttResult::MissShort,
        'distance_ft' => 25,
    ]);

    $this->get(route('stats', ['session' => $tonight]))
        ->assertOk()
        ->assertSee('12 putts')
        ->assertSee('Every putt')
        // The pace chart tracks the challenge window, not one night.
        ->assertDontSee('Pace')
        ->assertDontSee('days left');
});

it('offers every session with putts in the scope picker', function () {
    Challenge::factory()->create();

    $withPutts = PuttingSession::factory()->create();
    Putt::factory()->create(['putting_session_id' => $withPutts->id]);

    PuttingSession::factory()->create();

    $response = $this->get(route('stats'))->assertOk()->assertSee('Overall');

    expect(substr_count($response->getContent(), '<option'))->toBe(2);
});

it('falls back to overall when the session does not exist', function () {
    Challenge::factory()->create();

    $this->get(route('stats', ['session' => 99999]))
        ->assertOk()
        ->assertSee('days left');
});

it('links to session stats from the session detail page', function () {
    $session = PuttingSession::factory()->create();

    $this->get(route('sessions.show', $session))
        ->assertOk()
        ->assertSee('Stats for this session');
});

it('filters the stats page to a context', function () {
    Challenge::factory()->create();

    logMakeRate(40, 100, 12, Putter::Blade, PuttContext::Inside);

    $this->get(route('stats', ['putter' => 'blade', 'context' => 'outside']))
        ->assertOk()
        ->assertSee('No outside putts logged with the blade yet');

    $this->get(route('stats', ['putter' => 'blade', 'context' => 'inside']))
        ->assertOk()
        ->assertSee('Make rate by distance')
        ->assertSee('Everything below is Blade, inside only');
});

it('remembers the last context viewed', function () {
    Challenge::factory()->create();
    logMakeRate(40, 50, 12, Putter::Blade, PuttContext::Outside);

    $this->get(route('stats', ['context' => 'outside']))->assertOk();

    $this->get(route('stats'))
        ->assertOk()
        ->assertSee('Everything below is Blade, outside only');
});

it('clears the context filter by following the Both link', function () {
    Challenge::factory()->create();
    logMakeRate(40, 50, 12, Putter::Blade, PuttContext::Inside);
    logMakeRate(40, 90, 12, Putter::Blade, PuttContext::Outside);

    $filtered = $this->get(route('stats', ['context' => 'outside']))
        ->assertOk()
        ->assertSee('Everything below is Blade, outside only');

    // Follow the link the page actually renders rather than building the URL by
    // hand. The filter is sticky, so a Both link that merely omitted the parameter
    // would read as "no opinion" and silently leave outside in place.
    expect(preg_match('/<a href="([^"]+)"[^>]*>\s*Both\s*<\/a>/', $filtered->getContent(), $matches))->toBe(1);

    $this->get(html_entity_decode($matches[1]))
        ->assertOk()
        ->assertSee('Everything below is Blade only')
        ->assertDontSee('outside only');
});

it('highlights whichever context is active', function () {
    Challenge::factory()->create();
    logMakeRate(40, 50, 12, Putter::Blade, PuttContext::Outside);

    $active = '/<a href="[^"]*"[^>]*ring-sky-500\/50[^>]*>\s*%s\s*<\/a>/';

    $both = $this->get(route('stats', ['context' => PuttContext::ANY]))->getContent();
    $outside = $this->get(route('stats', ['context' => 'outside']))->getContent();

    expect(preg_match(sprintf($active, 'Both'), $both))->toBe(1)
        ->and(preg_match(sprintf($active, 'Outside'), $both))->toBe(0)
        ->and(preg_match(sprintf($active, 'Outside'), $outside))->toBe(1)
        ->and(preg_match(sprintf($active, 'Both'), $outside))->toBe(0);
});

it('shows the carpet versus greens breakdown for both putters', function () {
    Challenge::factory()->create();

    logMakeRate(40, 80, 10, Putter::Blade, PuttContext::Inside);
    logMakeRate(40, 55, 10, Putter::Blade, PuttContext::Outside);
    logMakeRate(40, 60, 10, Putter::Mallet, PuttContext::Inside);
    logMakeRate(40, 58, 10, Putter::Mallet, PuttContext::Outside);

    $this->get(route('stats'))
        ->assertOk()
        ->assertSee('Carpet vs. real greens')
        ->assertSee('The mallet travels best');
});

it('hides the context switch and breakdown when scoped to a session', function () {
    Challenge::factory()->create();

    $session = PuttingSession::factory()->create();
    Putt::factory()->count(10)->create(['putting_session_id' => $session->id]);

    $this->get(route('stats', ['session' => $session]))
        ->assertOk()
        ->assertDontSee('Carpet vs. real greens')
        ->assertDontSee('Both');
});

it('filters the compare page to a context', function () {
    logMakeRate(120, 80, 10, Putter::Blade, PuttContext::Inside);
    logMakeRate(120, 40, 10, Putter::Blade, PuttContext::Outside);
    logMakeRate(120, 50, 10, Putter::Mallet, PuttContext::Inside);
    logMakeRate(120, 70, 10, Putter::Mallet, PuttContext::Outside);

    $this->get(route('stats.compare', ['context' => 'outside']))
        ->assertOk()
        ->assertSee('Play the mallet');

    $this->get(route('stats.compare', ['context' => 'inside']))
        ->assertOk()
        ->assertSee('Play the blade');
});

it('shows the split dial and its toggle on the log screen', function () {
    Challenge::factory()->create();

    $this->get(route('log'))
        ->assertOk()
        ->assertSee('Split pull / read')
        ->assertSee('Pulled it left')
        ->assertSee('Misread the break right')
        ->assertSee('PUSH');
});

it('shows the stroke versus read breakdown once misses are classified', function () {
    Challenge::factory()->create();

    logPutts(40, PuttResult::Sunk, 10);
    logCause(15, PuttResult::MissLeft, LineMissCause::Stroke);
    logCause(5, PuttResult::MissRight, LineMissCause::Read);

    $this->get(route('stats'))
        ->assertOk()
        ->assertSee('Of those line misses')
        ->assertSee('Push / pull (15)')
        ->assertSee('Misread (5)');
});

it('hides the cause breakdown when nothing is classified', function () {
    Challenge::factory()->create();

    logPutts(40, PuttResult::Sunk, 10);
    logPutts(20, PuttResult::MissLeft, 10);

    $this->get(route('stats'))
        ->assertOk()
        ->assertSee('Speed vs. line')
        ->assertDontSee('Of those line misses');
});

it('reports how many line misses were logged without a cause', function () {
    Challenge::factory()->create();

    logPutts(40, PuttResult::Sunk, 10);
    logCause(10, PuttResult::MissLeft, LineMissCause::Stroke);
    logCause(7, PuttResult::MissLeft, null);

    $this->get(route('stats'))
        ->assertOk()
        ->assertSee('7 line misses logged without a cause');
});
