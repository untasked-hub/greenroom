<?php

namespace App\Http\Controllers;

use App\Models\SaasCredit;
use App\Models\SaasSubscription;
use App\Models\User;
use App\Services\SaasService;
use App\Services\StripeGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BillingController extends Controller
{
    private const STUDIO_PLANS = ['studio_monthly', 'studio_yearly'];

    public function index(Request $request, SaasService $saas): Response
    {
        $enabled = (bool) config('saas.enabled');
        $user = $enabled ? $request->user() : null;
        $studio = $user ? $saas->studio($user) : null;
        $credits = $user?->saasCredits()->whereNull('consumed_at')->pluck('type')->countBy();

        return Inertia::render('Billing', [
            'enabled' => $enabled,
            'prices' => [
                'pass' => 9,
                'studio_monthly' => 19,
                'studio_yearly' => 190,
                'extra_event' => 9,
            ],
            'checkoutReady' => array_map(fn ($price) => $enabled && $this->checkoutConfigured($price), config('saas.prices')),
            'portalReady' => (bool) ($user && config('saas.stripe_secret') && $user->saasSubscriptions()->exists()),
            'signedIn' => $user !== null,
            'studio' => $studio?->only(['plan', 'status', 'period_end']),
            'usage' => [
                'rooms' => $user ? $saas->monthlyUsage($user) : 0,
                'passes' => $credits?->get('pass', 0) ?? 0,
                'extras' => $credits?->get('extra_event', 0) ?? 0,
            ],
            'limits' => [
                'attendees' => config('saas.attendee_limit'),
                'durationMinutes' => config('saas.pass_duration_minutes'),
                'downloadDays' => config('saas.pass_download_days'),
                'rooms' => config('saas.studio_rooms_per_month'),
                'recordings' => config('saas.studio_recordings'),
            ],
            'checkoutReturned' => $request->query('checkout') === 'success',
        ]);
    }

    public function checkout(Request $request, StripeGateway $stripe, SaasService $saas)
    {
        abort_unless(config('saas.enabled'), 404);
        if (! $request->user()) {
            return redirect()->guest(route('login'));
        }

        $prices = config('saas.prices');
        $plan = $request->validate(['plan' => ['required', Rule::in(array_keys($prices))]])['plan'];
        abort_unless($this->checkoutConfigured($prices[$plan]), 503, 'Hosted checkout is awaiting Stripe configuration.');

        $user = $request->user();
        $subscription = in_array($plan, self::STUDIO_PLANS, true);
        $studio = $saas->studio($user);
        if (($subscription && $studio) || ($plan === 'extra_event' && ! $studio)) {
            return back()->withErrors(['billing' => $subscription
                ? 'Use the billing portal to manage your existing Studio subscription.'
                : 'An active Studio subscription is required to buy an extra webinar.']);
        }

        $metadata = ['greenroom_user_id' => (string) $user->id, 'greenroom_plan' => $plan];
        $parameters = [
            'mode' => $subscription ? 'subscription' : 'payment',
            'line_items' => [['price' => $prices[$plan], 'quantity' => 1]],
            'client_reference_id' => (string) $user->id,
            'metadata' => $metadata,
            'success_url' => route('billing.index', ['checkout' => 'success']),
            'cancel_url' => route('billing.index'),
        ];

        $customer = $user->saasSubscriptions()->latest('id')->value('stripe_customer_id');
        $parameters += $customer ? ['customer' => $customer] : ['customer_email' => $user->email];
        if ($subscription) {
            $parameters['subscription_data'] = ['metadata' => $metadata];
        }

        $url = $stripe->checkout($parameters);
        abort_unless($url, 503, 'Stripe checkout is temporarily unavailable. Please try again.');

        return Inertia::location($url);
    }

    public function portal(Request $request, StripeGateway $stripe)
    {
        abort_unless(config('saas.enabled'), 404);
        if (! $request->user()) {
            return redirect()->guest(route('login'));
        }

        abort_unless(config('saas.stripe_secret'), 503, 'The billing portal is awaiting Stripe configuration.');
        $customer = $request->user()->saasSubscriptions()->latest('id')->value('stripe_customer_id');
        if (! $customer) {
            return back()->withErrors(['billing' => 'The billing portal becomes available after you subscribe to Studio.']);
        }

        $url = $stripe->portal($customer, route('billing.index'));
        abort_unless($url, 503, 'The Stripe billing portal is temporarily unavailable. Please try again.');

        return Inertia::location($url);
    }

    public function webhook(Request $request, StripeGateway $stripe)
    {
        abort_unless(config('saas.enabled'), 404);
        abort_unless(config('saas.webhook_secret'), 503, 'The Stripe webhook is awaiting configuration.');
        $payload = $request->getContent();
        abort_unless($stripe->validSignature($payload, $request->header('Stripe-Signature', '')), 400);

        $event = json_decode($payload, true);
        $object = data_get($event, 'data.object');
        abort_unless(is_array($object) && isset($event['type'], $event['created']), 400);
        $eventAt = (int) $event['created'];

        switch ($event['type']) {
            case 'checkout.session.completed':
            case 'checkout.session.async_payment_succeeded':
                $this->completeCheckout($object, $eventAt, $stripe);
                break;
            case 'customer.subscription.updated':
            case 'customer.subscription.deleted':
                if ($event['type'] === 'customer.subscription.deleted') {
                    $object['status'] = 'canceled';
                }
                $this->syncSubscription($object, $eventAt);
                break;
        }

        return response()->noContent();
    }

    private function checkoutConfigured(?string $price): bool
    {
        return (bool) ($price && config('saas.stripe_secret') && config('saas.webhook_secret'));
    }

    private function completeCheckout(array $session, int $eventAt, StripeGateway $stripe): void
    {
        $plan = data_get($session, 'metadata.greenroom_plan');
        $user = User::find(data_get($session, 'metadata.greenroom_user_id'));
        if (! $user || empty($session['id'])) {
            return;
        }

        if (in_array($plan, ['pass', 'extra_event'], true)
            && ($session['mode'] ?? null) === 'payment'
            && ($session['payment_status'] ?? null) === 'paid') {
            SaasCredit::query()->insertOrIgnore([
                'stripe_checkout_session_id' => $session['id'],
                'user_id' => $user->id,
                'type' => $plan,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (in_array($plan, self::STUDIO_PLANS, true)
            && ($session['mode'] ?? null) === 'subscription'
            && ! empty($session['subscription'])) {
            $subscription = $stripe->subscription($session['subscription']);
            abort_unless($subscription, 503, 'Stripe subscription details are temporarily unavailable.');
            $this->syncSubscription($subscription, $eventAt, $user, $plan);
        }
    }

    private function syncSubscription(array $object, int $eventAt, ?User $user = null, ?string $plan = null): void
    {
        if (empty($object['id']) || empty($object['customer']) || empty($object['status'])) {
            return;
        }

        $existing = SaasSubscription::where('stripe_subscription_id', $object['id'])->first();
        $user ??= User::find($existing?->user_id ?? data_get($object, 'metadata.greenroom_user_id'));
        $plan ??= data_get($object, 'metadata.greenroom_plan', $existing?->plan);
        $price = data_get($object, 'items.data.0.price.id');
        if ($price) {
            $configuredPlan = array_search($price, config('saas.prices'), true);
            if (in_array($configuredPlan, self::STUDIO_PLANS, true)) {
                $plan = $configuredPlan;
            } elseif ($object['status'] !== 'canceled') {
                // An unrecognized Stripe product does not confer Studio access.
                $object['status'] = 'unrecognized_price';
            }
        }

        if (! $user || ! in_array($plan, self::STUDIO_PLANS, true)) {
            return;
        }

        DB::transaction(function () use ($object, $eventAt, $user, $plan) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $existing = $user->saasSubscriptions()->where('stripe_subscription_id', $object['id'])->first();
            if ($existing && ($existing->status === 'canceled'
                || ($existing->stripe_event_at > $eventAt && $object['status'] !== 'canceled'))) {
                return;
            }

            $periodEnd = data_get($object, 'items.data.0.current_period_end', $object['current_period_end'] ?? null);
            $user->saasSubscriptions()->updateOrCreate(['stripe_subscription_id' => $object['id']], [
                'stripe_customer_id' => $object['customer'],
                'plan' => $plan,
                'status' => $object['status'],
                'period_end' => $periodEnd ? Carbon::createFromTimestamp((int) $periodEnd, config('app.timezone')) : null,
                'stripe_event_at' => $eventAt,
            ]);
        }, 3);
    }
}
