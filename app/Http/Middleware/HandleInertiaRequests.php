<?php

namespace App\Http\Middleware;

use App\Models\ExchangeRate;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'celebrate' => fn () => $request->session()->get('celebrate'),
            ],
            'notifications' => [
                'vapidPublicKey' => config('services.webpush.public_key'),
                'subscribed' => fn () => $request->user()
                    ? $request->user()->pushSubscriptions()->exists()
                    : false,
            ],
            'rates' => function () {
                $r = ExchangeRate::orderByDesc('fetched_at')->orderByDesc('id')->first();
                if (! $r) {
                    return null;
                }
                return [
                    'bcv_usd' => $r->bcv_usd !== null ? (float) $r->bcv_usd : null,
                    'parallel_usd' => $r->parallel_usd !== null ? (float) $r->parallel_usd : null,
                    'bcv_eur' => $r->bcv_eur !== null ? (float) $r->bcv_eur : null,
                    'fetched_at' => optional($r->fetched_at)->toIso8601String(),
                    'rate_date' => optional($r->rate_date)->toDateString(),
                ];
            },
        ];
    }
}
