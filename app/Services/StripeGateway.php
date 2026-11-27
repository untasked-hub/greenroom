<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class StripeGateway
{
    public function checkout(array $parameters): ?string
    {
        return $this->request('POST', 'checkout/sessions', $parameters)['url'] ?? null;
    }

    public function portal(string $customer, string $returnUrl): ?string
    {
        return $this->request('POST', 'billing_portal/sessions', [
            'customer' => $customer,
            'return_url' => $returnUrl,
        ])['url'] ?? null;
    }

    public function subscription(string $id): ?array
    {
        return $this->request('GET', 'subscriptions/'.rawurlencode($id));
    }

    private function request(string $method, string $path, array $parameters = []): ?array
    {
        $secret = config('saas.stripe_secret');
        if (! $secret) {
            return null;
        }

        try {
            $response = Http::withToken($secret)->asForm()->acceptJson()->timeout(10)
                ->send($method, 'https://api.stripe.com/v1/'.$path, ['form_params' => $parameters]);

            return $response->successful() ? $response->json() : null;
        } catch (ConnectionException) {
            return null;
        }
    }

    public function validSignature(string $payload, string $header): bool
    {
        $secret = config('saas.webhook_secret');
        if (! $secret) {
            return false;
        }

        $timestamp = '';
        $signatures = [];
        foreach (explode(',', $header) as $component) {
            [$key, $value] = array_pad(explode('=', trim($component), 2), 2, '');
            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        if (! ctype_digit($timestamp) || abs(now()->timestamp - (int) $timestamp) > 300) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }
}
