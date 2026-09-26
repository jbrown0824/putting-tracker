<?php

use App\Enums\ClockPosition;
use App\Enums\LineMissCause;
use App\Enums\PuttContext;
use App\Enums\PutterHeadType;
use App\Enums\PuttResult;
use App\Models\Putt;
use App\Models\PuttingSession;
use App\Models\User;

beforeEach(fn () => $this->actingAs(testUser()));

it('renders the log screen with the dial', function () {
    $this->get(route('log'))
        ->assertOk()
        ->assertSee('SUNK')
        ->assertSee('LONG')
        ->assertSee('SHORT')
        ->assertSee('LEFT')
        ->assertSee('RIGHT')
        ->assertSee('Outside');
});

it('offers the player\'s own putters on the log screen', function () {
    blade();
    mallet();
    blade(User::factory()->create())->update(['name' => 'Someone else\'s']);

    $this->get(route('log'))
        ->assertOk()
        ->assertSee('Blade')
        ->assertSee('Mallet')
        ->assertDontSee('Someone else');
});

it('renders the stats page with data', function () {
    $session = PuttingSession::factory()->for(blade())->create();
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

    $this->get(route('stats'))->assertOk()->assertSee('No putts logged yet');
});

it('lists sessions with make rates', function () {
    $session = PuttingSession::factory()->for(blade())->create(['context' => PuttContext::Outside]);
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
    $session = PuttingSession::factory()->for(blade())->create();
    $putt = Putt::factory()->create(['putting_session_id' => $session->id]);

    $this->get(route('sessions.show', $session))->assertOk();

    $this->delete(route('sessions.putts.destroy', [$session, $putt]))
        ->assertRedirect();

    expect(Putt::count())->toBe(0);
});

it('refuses to delete a putt through the wrong session', function () {
    $session = PuttingSession::factory()->for(blade())->create();
    $other = PuttingSession::factory()->for(blade())->create();
    $putt = Putt::factory()->create(['putting_session_id' => $other->id]);

    $this->delete(route('sessions.putts.destroy', [$session, $putt]))->assertNotFound();

    expect(Putt::count())->toBe(1);
});

it('deletes a session and its putts', function () {
    $session = PuttingSession::factory()->for(blade())->create();
    Putt::factory()->count(3)->create(['putting_session_id' => $session->id]);

    $this->delete(route('sessions.destroy', $session))->assertRedirect(route('sessions.index'));

    expect(PuttingSession::count())->toBe(0)
        ->and(Putt::count())->toBe(0);
});

it('shows a putter toggle on the stats page', function () {
    blade();
    mallet();

    $this->get(route('stats'))
        ->assertOk()
        ->assertSee('Blade')
        ->assertSee('Mallet')
        ->assertSee('Compare');
});

it('scopes the stats page to the requested putter', function () {

    logMakeRate(40, 100, 12, blade());

    $this->get(route('stats', ['putter' => mallet()->id]))
        ->assertOk()
        ->assertSee('No putts logged with Mallet yet')
        ->assertDontSee('Make rate by distance');

    $this->get(route('stats', ['putter' => blade()->id]))
        ->assertOk()
        ->assertSee('Make rate by distance')
        ->assertDontSee('No putts logged with Blade yet');
});

it('remembers the last putter viewed', function () {

    $this->get(route('stats', ['putter' => mallet()->id]))->assertOk();

    $this->get(route('stats'))
        ->assertOk()
        ->assertSee('Showing Mallet.');
});

it('asks for more data on the compare page before recommending', function () {
    logMakeRate(20, 50, 10, blade());
    logMakeRate(20, 90, 10, mallet());

    $this->get(route('stats.compare'))
        ->assertOk()
        ->assertSee('Keep logging')
        ->assertSee('Not enough data to call it');
});

it('recommends a putter on the compare page once the data supports it', function () {
    logMakeRate(120, 30, 10, blade());
    logMakeRate(120, 70, 10, mallet());

    $this->get(route('stats.compare'))
        ->assertOk()
        ->assertSee('Recommended')
        ->assertSee('Play Mallet')
        ->assertSee('Head to head')
        ->assertSee('Matched distances');
});

it('shows the putter on each history row', function () {
    PuttingSession::factory()->for(mallet())->create();

    $this->get(route('sessions.index'))->assertOk()->assertSee('Mallet');
});

it('scopes the stats page to a single session', function () {

    $tonight = PuttingSession::factory()->for(blade())->create(['started_at' => now()]);
    Putt::factory()->count(12)->create([
        'putting_session_id' => $tonight->id,
        'result' => PuttResult::Sunk,
        'distance_ft' => 10,
    ]);

    $earlier = PuttingSession::factory()->for(blade())->create(['started_at' => now()->subDays(3)]);
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

    $withPutts = PuttingSession::factory()->for(blade())->create();
    Putt::factory()->create(['putting_session_id' => $withPutts->id]);

    PuttingSession::factory()->for(blade())->create();

    $response = $this->get(route('stats'))->assertOk()->assertSee('Overall');

    expect(substr_count($response->getContent(), '<option'))->toBe(2);
});

it('falls back to overall when the session does not exist', function () {

    $this->get(route('stats', ['session' => 99999]))
        ->assertOk()
        ->assertSee('Showing all putters');
});

it('links to session stats from the session detail page', function () {
    $session = PuttingSession::factory()->for(blade())->create();

    $this->get(route('sessions.show', $session))
        ->assertOk()
        ->assertSee('Stats for this session');
});

it('filters the stats page to a context', function () {

    logMakeRate(40, 100, 12, blade(), PuttContext::Inside);

    $this->get(route('stats', ['putter' => blade()->id, 'context' => 'outside']))
        ->assertOk()
        ->assertSee('No outside putts logged with Blade yet');

    $this->get(route('stats', ['putter' => blade()->id, 'context' => 'inside']))
        ->assertOk()
        ->assertSee('Make rate by distance')
        ->assertSee('Showing Blade, inside only');
});

it('remembers the last context viewed', function () {
    logMakeRate(40, 50, 12, blade(), PuttContext::Outside);

    $this->get(route('stats', ['context' => 'outside']))->assertOk();

    $this->get(route('stats'))
        ->assertOk()
        ->assertSee('Showing all putters, outside only');
});

it('clears the context filter by following the Both link', function () {
    logMakeRate(40, 50, 12, blade(), PuttContext::Inside);
    logMakeRate(40, 90, 12, blade(), PuttContext::Outside);

    $filtered = $this->get(route('stats', ['context' => 'outside']))
        ->assertOk()
        ->assertSee('Showing all putters, outside only');

    // Follow the link the page actually renders rather than building the URL by
    // hand. The filter is sticky, so a Both link that merely omitted the parameter
    // would read as "no opinion" and silently leave outside in place.
    expect(preg_match('/<a href="([^"]+)"[^>]*>\s*Both\s*<\/a>/', $filtered->getContent(), $matches))->toBe(1);

    $this->get(html_entity_decode($matches[1]))
        ->assertOk()
        ->assertSee('Showing all putters.')
        ->assertDontSee('outside only');
});

it('highlights whichever context is active', function () {
    logMakeRate(40, 50, 12, blade(), PuttContext::Outside);

    $active = '/<a href="[^"]*"[^>]*ring-sky-500\/50[^>]*>\s*%s\s*<\/a>/';

    $both = $this->get(route('stats', ['context' => PuttContext::ANY]))->getContent();
    $outside = $this->get(route('stats', ['context' => 'outside']))->getContent();

    expect(preg_match(sprintf($active, 'Both'), $both))->toBe(1)
        ->and(preg_match(sprintf($active, 'Outside'), $both))->toBe(0)
        ->and(preg_match(sprintf($active, 'Outside'), $outside))->toBe(1)
        ->and(preg_match(sprintf($active, 'Both'), $outside))->toBe(0);
});

it('shows the carpet versus greens breakdown for both putters', function () {

    logMakeRate(40, 80, 10, blade(), PuttContext::Inside);
    logMakeRate(40, 55, 10, blade(), PuttContext::Outside);
    logMakeRate(40, 60, 10, mallet(), PuttContext::Inside);
    logMakeRate(40, 58, 10, mallet(), PuttContext::Outside);

    $this->get(route('stats'))
        ->assertOk()
        ->assertSee('Carpet vs. real greens')
        ->assertSee('Mallet travels best');
});

it('hides the context switch and breakdown when scoped to a session', function () {

    $session = PuttingSession::factory()->for(blade())->create();
    Putt::factory()->count(10)->create(['putting_session_id' => $session->id]);

    $this->get(route('stats', ['session' => $session]))
        ->assertOk()
        ->assertDontSee('Carpet vs. real greens')
        ->assertDontSee('Both');
});

it('filters the compare page to a context', function () {
    logMakeRate(120, 80, 10, blade(), PuttContext::Inside);
    logMakeRate(120, 40, 10, blade(), PuttContext::Outside);
    logMakeRate(120, 50, 10, mallet(), PuttContext::Inside);
    logMakeRate(120, 70, 10, mallet(), PuttContext::Outside);

    $this->get(route('stats.compare', ['context' => 'outside']))
        ->assertOk()
        ->assertSee('Play Mallet');

    $this->get(route('stats.compare', ['context' => 'inside']))
        ->assertOk()
        ->assertSee('Play Blade');
});

it('shows the split dial and its toggle on the log screen', function () {

    $this->get(route('log'))
        ->assertOk()
        ->assertSee('Split pull / read')
        ->assertSee('Pulled it left')
        ->assertSee('Misread the break right')
        ->assertSee('PUSH');
});

it('shows the stroke versus read breakdown once misses are classified', function () {

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

    logPutts(40, PuttResult::Sunk, 10);
    logPutts(20, PuttResult::MissLeft, 10);

    $this->get(route('stats'))
        ->assertOk()
        ->assertSee('Speed vs. line')
        ->assertDontSee('Of those line misses');
});

it('reports how many line misses were logged without a cause', function () {

    logPutts(40, PuttResult::Sunk, 10);
    logCause(10, PuttResult::MissLeft, LineMissCause::Stroke);
    logCause(7, PuttResult::MissLeft, null);

    $this->get(route('stats'))
        ->assertOk()
        ->assertSee('7 line misses logged without a cause');
});

it('shows the clock ring and the position bar on the log screen', function () {

    $this->get(route('log'))
        ->assertOk()
        ->assertSee('stepClockPosition(1)', false)
        ->assertSee('setClockPosition(\'above_right\')', false)
        // The orienting convention has to be on screen, or the data is meaningless.
        ->assertSee('on the high side', false);
});

it('renders the log screen before any position has been chosen', function () {

    $this->get(route('log'))
        ->assertOk()
        ->assertSee('Tap to set position');
});

it('shows the position heat map once putts carry a position', function () {
    logMakeRate(40, 60, 10, blade(), PuttContext::Outside, PuttResult::MissShort, ClockPosition::Below);
    logMakeRate(40, 20, 10, blade(), PuttContext::Outside, PuttResult::MissShort, ClockPosition::AboveRight);

    $this->get(route('stats', ['context' => PuttContext::Outside->value]))
        ->assertOk()
        ->assertSee('Around the hole')
        ->assertSee('is the high side. Dashed segments');
});

it('hides the heat map on the day nothing has a position yet', function () {
    logPutts(60, PuttResult::Sunk, 10);

    $this->get(route('stats'))
        ->assertOk()
        ->assertDontSee('Around the hole');
});

it('says untagged putts are unclassified rather than flat', function () {
    logPutts(40, PuttResult::Sunk, 10, PuttContext::Outside, blade(), ClockPosition::Below);
    logPutts(25, PuttResult::MissShort, 10, PuttContext::Outside);

    $this->get(route('stats', ['context' => PuttContext::Outside->value]))
        ->assertOk()
        ->assertSee('they are unclassified, not flat');
});

it('shows the levelled make rate alongside the raw one', function () {
    logMakeRate(120, 80, 3, blade());
    logMakeRate(120, 45, 10, blade());
    logMakeRate(120, 20, 20, blade());

    $this->get(route('stats', ['putter' => blade()->id]))
        ->assertOk()
        ->assertSee('Make rate, levelled')
        ->assertSee('Levelled');
});

it('shows the levelled row on the compare page', function () {
    logMakeRate(150, 80, 3, blade());
    logMakeRate(150, 50, 10, blade());
    logMakeRate(320, 66, 3, mallet());
    logMakeRate(70, 32, 10, mallet());

    $this->get(route('stats.compare'))
        ->assertOk()
        ->assertSee('Levelled')
        ->assertSee('that is the row the verdict is based on');
});

it('renders the compare page when neither putter has a position yet', function () {
    logMakeRate(60, 50, 10, blade());
    logMakeRate(60, 50, 10, mallet());

    $this->get(route('stats.compare'))->assertOk();
});

it('hides other players\' sessions', function () {
    $theirs = PuttingSession::factory()->for(blade(User::factory()->create()))->create();
    Putt::factory()->create(['putting_session_id' => $theirs->id]);

    $this->get(route('sessions.show', $theirs))->assertNotFound();
    $this->delete(route('sessions.destroy', $theirs))->assertNotFound();
    $this->get(route('sessions.index'))->assertOk()->assertSee('No sessions yet');
    // Asking the stats page for someone else's session falls back to your own overall view.
    $this->get(route('stats', ['session' => $theirs->id]))->assertOk()->assertSee('Showing all putters');

    expect(PuttingSession::query()->count())->toBe(1);
});

it('asks for a second putter before comparing', function () {
    logMakeRate(20, 50, 10, blade());

    $this->get(route('stats.compare'))
        ->assertOk()
        ->assertSee('Log putts with at least two putters');
});

it('compares whichever two putters are chosen', function () {
    $third = putterNamed('Anser', PutterHeadType::Blade);
    logMakeRate(20, 50, 10, blade());
    logMakeRate(20, 50, 10, mallet());
    logMakeRate(20, 50, 10, $third);

    $this->get(route('stats.compare', ['first' => $third->id, 'second' => mallet()->id]))
        ->assertOk()
        ->assertSee('Log 80 more putts with Anser', false);
});
