<?php
namespace App\Http\Middleware;

use Closure;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Osiset\ShopifyApp\Contracts\Queries\Shop as IShopQuery;
use Osiset\ShopifyApp\Objects\Values\ShopDomain;

class VerifyShopifySessionToken
{
    public function __construct(
        protected IShopQuery $shopQuery,
    ) {}

    public function handle(Request $request, Closure $next)
    {
        $authHeader = $request->header('Authorization', '');

        if (! str_starts_with($authHeader, 'Bearer ')) {
            return response()->json(['message' => 'Missing session token'], 401);
        }

        $token = substr($authHeader, 7);

        try {
            $secret = config('shopify-app.api_secret');
            $payload = JWT::decode($token, new Key($secret, 'HS256'));
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Invalid session token'], 401);
        }

        // Basic expiry check
        if (! isset($payload->exp) || $payload->exp < time()) {
            return response()->json(['message' => 'Session token expired'], 401);
        }

        // dest looks like "https://shop-domain.myshopify.com"
        $dest = $payload->dest ?? null;
        if (! $dest) {
            return response()->json(['message' => 'Invalid session token'], 401);
        }

        $shopHost = parse_url($dest, PHP_URL_HOST);
        if (! $shopHost) {
            return response()->json(['message' => 'Invalid shop domain'], 401);
        }

        $shop = $this->shopQuery->getByDomain(
            ShopDomain::fromNative($shopHost)
        );

        if (! $shop) {
            return response()->json(['message' => 'Shop not found'], 401);
        }

        // Authenticate this shop for the duration of the request (no cookies)
        Auth::login($shop);

        return $next($request);
    }
}