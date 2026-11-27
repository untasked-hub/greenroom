<?php

use App\Models\Room;
use App\Models\User;
use Illuminate\Http\Client\Request as StripeRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function enableHostedBilling(): void
{
    config([
        'saas.enabled' => true,
        'saas.stripe_secret' => 'fake-stripe-key',
        'saas.webhook_secret' => 'fake-signing-key',
        'saas.prices' => [
            'pass' => 'price_test_pass',
            'studio_monthly' => 'price_test_monthly',
            'studio_yearly' => 'price_test_yearly',
            'extra_event' => 'price_test_extra',
        ],
    ]);
}

function hostedUser(): User
{
    return User::create([
        'name' => 'Webinar host',
        'email' => Str::uuid().'@example.test',
        'password' => 'password123',
    ]);
}

function hostedRoom(User $user, string $source): Room
{
    $room = new Room([
        'user_id' => $user->id,
        'slug' => Str::random(8),
        'title' => 'Hosted webinar',
        'presenter_key_hash' => hash('sha256', 'presenter'),
    ]);
    $room->forceFill(['saas_source' => $source])->save();

    return $room;
}

function hostedSubscription(User $user, string $status = 'active'): void
{
    $user->saasSubscriptions()->create([
        'plan' => 'studio_monthly',
        'status' => $status,
        'stripe_customer_id' => 'cus_studio',
        'stripe_subscription_id' => 'sub_studio',
        'stripe_event_at' => now()->timestamp,
        'period_end' => now()->addMonth(),
    ]);
}

function stripeEvent($test, string $type, array $object, ?int $signatureAt = null, ?string $secret = null, ?int $eventAt = null)
{
    $body = json_encode(['type' => $type, 'created' => $eventAt ?? now()->timestamp, 'data' => ['object' => $object]]);
    $signatureAt ??= now()->timestamp;
    $signature = hash_hmac('sha256', $signatureAt.'.'.$body, $secret ?? config('saas.webhook_secret'));

    return $test->call('POST', '/billing/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => "t=$signatureAt,v1=incorrect,v1=$signature",
    ], $body);
}

beforeEach(function () {
    Http::preventStrayRequests();
});

test('self-hosting still permits guest room creation and hides hosted billing', function () {
    expect(config('saas.enabled'))->toBeFalse();
    $this->post('/rooms', ['title' => 'Self-hosted webinar'])->assertRedirect();
    $this->assertDatabaseHas('rooms', ['user_id' => null, 'saas_source' => null]);
    $this->get('/')->assertInertia(fn (Assert $page) => $page->where('saasEnabled', false));
    $this->get('/billing')->assertInertia(fn (Assert $page) => $page->component('Billing')->where('enabled', false));
    $this->post('/billing/checkout')->assertNotFound();
    $this->post('/billing/portal')->assertNotFound();
    $this->post('/billing/webhook')->assertNotFound();
});

test('hosted rooms and purchases require an account and rooms need an entitlement', function () {
    enableHostedBilling();
    $this->post('/rooms', ['title' => 'Hosted'])->assertRedirect('/login')->assertSessionHasErrors('billing');
    $this->post('/billing/checkout', ['plan' => 'pass'])->assertRedirect('/login');
    $this->post('/billing/portal')->assertRedirect('/login');
    $this->actingAs(hostedUser())->post('/rooms', ['title' => 'Hosted'])
        ->assertRedirect('/billing')->assertSessionHasErrors('billing');
    $this->assertDatabaseCount('rooms', 0);
});

test('a signed paid checkout grants exactly one pass and a consumed pass cannot be restored by retries', function () {
    enableHostedBilling();
    $user = hostedUser();
    $session = [
        'id' => 'cs_pass',
        'mode' => 'payment',
        'payment_status' => 'unpaid',
        'metadata' => ['greenroom_plan' => 'pass', 'greenroom_user_id' => (string) $user->id],
    ];

    stripeEvent($this, 'checkout.session.completed', $session, secret: 'wrong')->assertBadRequest();
    stripeEvent($this, 'checkout.session.completed', $session, signatureAt: now()->timestamp - 301)->assertBadRequest();
    stripeEvent($this, 'checkout.session.completed', $session)->assertNoContent();
    $this->assertDatabaseCount('saas_credits', 0);

    $session['payment_status'] = 'paid';
    stripeEvent($this, 'checkout.session.async_payment_succeeded', $session)->assertNoContent();
    $this->actingAs($user)->post('/rooms', ['title' => 'Pass'])->assertRedirectContains('/studio/');
    $room = Room::sole();
    expect($room->saas_source)->toBe('pass');
    $credit = $user->saasCredits()->sole();
    expect($credit->room_id)->toBe($room->id)->and($credit->consumed_at)->not->toBeNull();

    stripeEvent($this, 'checkout.session.completed', $session)->assertNoContent();
    $this->post('/rooms', ['title' => 'Another room'])->assertRedirect('/billing');
    $this->assertDatabaseCount('saas_credits', 1);
    $this->assertDatabaseCount('rooms', 1);
});

test('Studio includes four calendar-month rooms plus paid extras', function () {
    enableHostedBilling();
    $this->travelTo(now()->startOfMonth()->addDay());
    $user = hostedUser();
    hostedSubscription($user, 'trialing');
    $this->actingAs($user);

    $oldRoom = hostedRoom($user, 'studio');
    $oldRoom->forceFill(['created_at' => now()->subMonth()])->save();
    for ($i = 0; $i < 4; $i++) {
        $this->post('/rooms', ['title' => 'Studio webinar'])->assertRedirectContains('/studio/');
    }
    $this->post('/rooms', ['title' => 'Fifth webinar'])->assertRedirect('/billing');
    $this->get('/billing')->assertInertia(fn (Assert $page) => $page->component('Billing')
        ->where('usage.rooms', 4)->where('studio.status', 'trialing')->where('checkoutReady.extra_event', true));

    stripeEvent($this, 'checkout.session.completed', [
        'id' => 'cs_extra',
        'mode' => 'payment',
        'payment_status' => 'paid',
        'metadata' => ['greenroom_plan' => 'extra_event', 'greenroom_user_id' => (string) $user->id],
    ])->assertNoContent();
    $this->post('/rooms', ['title' => 'Extra webinar'])->assertRedirectContains('/studio/');
    $this->assertDatabaseHas('rooms', ['saas_source' => 'extra_event', 'title' => 'Extra webinar']);
    $this->post('/rooms', ['title' => 'Quota exhausted'])->assertRedirect('/billing');
    $this->travel(1)->month();
    $this->post('/rooms', ['title' => 'New month'])->assertRedirectContains('/studio/');
});

test('hosted registration caps new registrations and permits an existing registrant to update', function () {
    enableHostedBilling();
    $room = hostedRoom(hostedUser(), 'studio');
    for ($i = 0; $i < 100; $i++) {
        $room->registrations()->create([
            'name' => 'Guest', 'email' => "guest$i@example.test",
            'registration_type' => 'live', 'token' => Str::random(48),
        ]);
    }

    $this->post("/r/$room->slug/register", ['name' => 'Late', 'email' => 'late@example.test'])
        ->assertSessionHasErrors('email');
    $this->post("/r/$room->slug/register", ['name' => 'Updated', 'email' => 'guest0@example.test'])
        ->assertRedirect("/r/$room->slug/register")->assertSessionDoesntHaveErrors();
    $this->assertDatabaseCount('webinar_registrations', 100);
});

test('recording downloads require ownership and respect the pass window and Studio archive', function () {
    enableHostedBilling();
    config(['greenroom.storage_url' => 'https://storage.test', 'greenroom.storage_token' => 'fake-storage']);
    Http::fake(['https://storage.test/*' => Http::response('video bytes')]);
    $user = hostedUser();
    hostedSubscription($user);
    $studio = hostedRoom($user, 'studio');
    $pass = hostedRoom($user, 'pass');
    $recording = fn (Room $room) => $room->recordings()->create(['storage_key' => 'video.webm', 'mime' => 'video/webm', 'size' => 11]);

    $studioRecordings = collect(range(1, 4))->map(fn () => $recording($studio));
    $passRecording = $recording($pass);
    $this->actingAs($user)->get(route('recordings.video', $studioRecordings[0]))->assertForbidden();
    $this->get(route('recordings.video', $studioRecordings[1]))->assertOk()->assertSee('video bytes');
    $this->get(route('recordings.video', $passRecording))->assertOk();

    $this->travel(30)->days();
    $this->get(route('recordings.video', $passRecording))->assertForbidden();
    $user->saasSubscriptions()->update(['status' => 'canceled']);
    $this->get(route('recordings.video', $studioRecordings[1]))->assertForbidden();

    config(['saas.enabled' => false]);
    $this->get(route('recordings.video', $passRecording))->assertOk();
    $this->actingAs(hostedUser())->get(route('recordings.video', $passRecording))->assertForbidden();
});

test('a pass broadcast stops after two hours and cannot be restarted', function () {
    enableHostedBilling();
    $room = hostedRoom(hostedUser(), 'pass');
    $url = "/studio/$room->slug/presenter";
    $this->postJson("$url/go-live")->assertOk()->assertJsonPath('live.is_live', true);
    $this->travel(120)->minutes();
    $this->getJson("/r/$room->slug/chat/room")->assertOk()->assertJsonPath('live.is_live', false);
    $this->postJson("/r/$room->slug/signals")->assertForbidden();
    $this->postJson("$url/go-live")->assertForbidden();
    $this->postJson("$url/end-live")->assertOk()->assertJsonPath('live.is_live', false);
    expect($room->fresh()->live_ended_at)->not->toBeNull();
});

test('checkout and portal use Stripe REST and follow the request host', function () {
    enableHostedBilling();
    Http::fake([
        'https://api.stripe.com/v1/checkout/sessions' => Http::response(['url' => 'https://checkout.stripe.com/fake']),
        'https://api.stripe.com/v1/billing_portal/sessions' => Http::response(['url' => 'https://billing.stripe.com/fake']),
    ]);
    $user = hostedUser();
    $this->actingAs($user)->from('/billing')->post('/billing/checkout', ['plan' => 'invalid'])->assertSessionHasErrors('plan');
    $this->from('/billing')->post('/billing/checkout', ['plan' => 'extra_event'])->assertSessionHasErrors('billing');
    $this->from('/billing')->post('/billing/portal')->assertSessionHasErrors('billing');
    $this->post('https://webinars.example.test/billing/checkout', ['plan' => 'studio_yearly'], ['X-Inertia' => 'true'])
        ->assertStatus(409)->assertHeader('X-Inertia-Location', 'https://checkout.stripe.com/fake');
    Http::assertSent(fn (StripeRequest $request) => $request->hasHeader('Authorization', 'Bearer fake-stripe-key')
        && $request['mode'] === 'subscription'
        && $request['line_items'][0]['price'] === 'price_test_yearly'
        && $request['subscription_data']['metadata']['greenroom_user_id'] === (string) $user->id
        && $request['success_url'] === 'https://webinars.example.test/billing?checkout=success'
        && $request['cancel_url'] === 'https://webinars.example.test/billing');

    hostedSubscription($user);
    $this->from('/billing')->post('/billing/checkout', ['plan' => 'studio_monthly'])->assertSessionHasErrors('billing');
    $this->post('/billing/portal')->assertRedirect('https://billing.stripe.com/fake');
    Http::assertSent(fn (StripeRequest $request) => $request->url() === 'https://api.stripe.com/v1/billing_portal/sessions'
        && $request['customer'] === 'cus_studio');
});

test('checkout fetches the Studio subscription and signed events sync plan, status, and period', function () {
    enableHostedBilling();
    $user = hostedUser();
    $end = now()->addYear()->startOfSecond();
    $subscription = [
        'id' => 'sub_checkout', 'customer' => 'cus_checkout', 'status' => 'active',
        'items' => ['data' => [['current_period_end' => $end->timestamp, 'price' => ['id' => 'price_test_yearly']]]],
    ];
    Http::fake(['https://api.stripe.com/v1/subscriptions/sub_checkout' => Http::response($subscription)]);
    stripeEvent($this, 'checkout.session.completed', [
        'id' => 'cs_studio', 'mode' => 'subscription', 'subscription' => 'sub_checkout',
        'metadata' => ['greenroom_plan' => 'studio_yearly', 'greenroom_user_id' => (string) $user->id],
    ])->assertNoContent();
    $record = $user->saasSubscriptions()->sole();
    expect($record->plan)->toBe('studio_yearly')->and($record->period_end->equalTo($end))->toBeTrue();

    $subscription['status'] = 'past_due';
    $subscription['items']['data'][0]['price']['id'] = 'price_test_monthly';
    stripeEvent($this, 'customer.subscription.updated', $subscription)->assertNoContent();
    expect($record->fresh()->status)->toBe('past_due')->and($record->fresh()->plan)->toBe('studio_monthly');

    stripeEvent($this, 'customer.subscription.updated', [...$subscription, 'status' => 'active'], eventAt: now()->timestamp - 1)
        ->assertNoContent();
    expect($record->fresh()->status)->toBe('past_due');

    stripeEvent($this, 'customer.subscription.deleted', $subscription)->assertNoContent();
    stripeEvent($this, 'customer.subscription.updated', [...$subscription, 'status' => 'active'])->assertNoContent();
    expect($record->fresh()->status)->toBe('canceled');
});

test('missing Stripe credentials and Stripe failures return graceful availability errors', function () {
    enableHostedBilling();
    $this->actingAs(hostedUser());
    config(['saas.stripe_secret' => null, 'saas.webhook_secret' => null]);
    $this->get('/billing')->assertInertia(fn (Assert $page) => $page->where('checkoutReady.pass', false));
    $this->post('/billing/checkout', ['plan' => 'pass'])->assertServiceUnavailable();
    $this->post('/billing/portal')->assertServiceUnavailable();
    $this->post('/billing/webhook')->assertServiceUnavailable();

    enableHostedBilling();
    Http::fake(['https://api.stripe.com/v1/checkout/sessions' => Http::response([], 502)]);
    $this->post('/billing/checkout', ['plan' => 'pass'])->assertServiceUnavailable();
});
