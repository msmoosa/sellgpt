<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Osiset\ShopifyApp\Contracts\Queries\Shop as IShopQuery;
use Osiset\ShopifyApp\Objects\Values\ShopDomain;

class GenerateController extends Controller
{
    /**
     * Generate a markdown list of products for the current shop.
     *
     * This endpoint takes no parameters and saves the LLMs.txt file to storage.
     */
    public function index(Request $request)
    {
        $shop = Auth::user();
        
        $validation = $this->validateShop($shop);
        if ($validation) {
            return $validation;
        }

        try {
            $api = $this->initializeApi($shop);
            $shopData = $this->fetchShopData($api);
            $products = $this->fetchProducts($api);
        } catch (\Exception $e) {
            return $this->handleApiError($e, $shop);
        }

        $shopUrl = 'https://' . $shop->getDomain()->toNative();
        $shopDomain = $shop->getDomain()->toNative();
        $markdown = $this->buildMarkdown($shopData, $products, $shopUrl);
        
        // Create redirect URL in Shopify
        $this->createRedirectUrl($api, $shopDomain);
        
        return $this->saveMarkdownFile($shopDomain, $shop, $markdown);
    }

    /**
     * Validate shop authentication and token.
     */
    protected function validateShop($shop)
    {
        if (! $shop) {
            return response('Unauthorized', 401);
        }

        if (empty($shop->password)) {
            return response(
                'Shop is not properly authenticated. Missing access token. Please re-install the app.',
                403
            )->header('Content-Type', 'text/plain');
        }

        Log::info('Shop API Request', [
            'shop_domain' => $shop->name,
            'has_password' => !empty($shop->password),
            'password_length' => strlen($shop->password ?? ''),
        ]);

        return null;
    }

    /**
     * Initialize and verify Shopify API connection.
     */
    protected function initializeApi($shop)
    {
        $apiHelper = $shop->apiHelper();
        $api = $apiHelper->getApi();
        
        $session = $api->getSession();
        if (!$session) {
            throw new \Exception('API session not initialized. Please check configuration.');
        }

        return $api;
    }

    /**
     * Fetch shop information from Shopify API.
     */
    protected function fetchShopData($api)
    {
        $shopResult = $api->rest('GET', '/admin/shop.json');
        
        if (isset($shopResult['errors']) && $shopResult['errors'] === true) {
            throw new \Exception('Error fetching shop information: ' . ($shopResult['body'] ?? 'Unknown error'));
        }

        // Handle ResponseAccess object or array
        $body = $shopResult['body'] ?? null;
        
        // If body is a ResponseAccess object, convert to array
        if (is_object($body) && method_exists($body, 'toArray')) {
            $body = $body->toArray();
        }
        
        // Extract shop data from body array
        if (is_array($body)) {
            $shop = $body['shop'] ?? null;
            
            // If shop is still a ResponseAccess object, convert it
            if (is_object($shop) && method_exists($shop, 'toArray')) {
                return $shop->toArray();
            }
            
            // If shop is already an array, return it
            if (is_array($shop)) {
                return $shop;
            }
        }

        return [];
    }

    /**
     * Fetch products from Shopify API.
     */
    protected function fetchProducts($api)
    {
        $productsResult = $api->rest('GET', '/admin/products.json', [
            'limit' => 250,
            'fields' => 'id,title,handle,body_html,vendor,product_type,updated_at,status,variants,images',
        ]);

        if (isset($productsResult['errors']) && $productsResult['errors'] === true) {
            $errorMessage = $productsResult['body'] ?? 'Unknown error';
            
            if (isset($productsResult['status']) && $productsResult['status'] === 401) {
                throw new \Exception(
                    'Authentication failed. The access token might be invalid or expired. ' .
                    'Please ensure: 1) The app was properly installed, 2) The API key/secret in .env matches the app credentials. ' .
                    'Error: ' . $errorMessage
                );
            }

            throw new \Exception('Error fetching products: ' . $errorMessage);
        }

        $products = $productsResult['body']['products'] ?? null;
        
        // Convert ResponseAccess to array if needed
        if (is_object($products) && method_exists($products, 'toArray')) {
            return $products->toArray();
        }
        
        // If it's already an array, return it
        if (is_array($products)) {
            return $products;
        }

        return [];
    }

    /**
     * Handle API errors and return appropriate response.
     */
    protected function handleApiError(\Exception $e, $shop)
    {
        Log::error('Exception fetching data', [
            'shop' => $shop->name ?? 'unknown',
            'error' => $e->getMessage(),
        ]);
        
        return response('Error connecting to Shopify API: ' . $e->getMessage(), 500)
            ->header('Content-Type', 'text/plain');
    }

    /**
     * Build the complete markdown content.
     */
    protected function buildMarkdown(array $shopData, array $products, string $shopUrl): string
    {
        $lines = [];
        
        $lines = array_merge($lines, $this->buildShopHeader($shopData, $shopUrl));
        $lines = array_merge($lines, $this->buildShopMetadata($shopData, $shopUrl));
        $lines = array_merge($lines, $this->buildProductsSection($products, $shopUrl, $shopData));

        return implode("\n", $lines);
    }

    /**
     * Build shop header section.
     */
    protected function buildShopHeader(array $shopData, string $shopUrl): array
    {
        $shopDomain = parse_url($shopUrl, PHP_URL_HOST);
        $shopName = $shopData['name'] ?? $shopDomain;
        
        return [
            sprintf('# %s (%s)', $shopName, $shopUrl),
            '',
        ];
    }

    /**
     * Build shop metadata section.
     */
    protected function buildShopMetadata(array $shopData, string $shopUrl): array
    {
        $lines = [
            sprintf('- Domain: %s', $shopUrl),
            sprintf('- Locale: %s', $shopData['primary_locale'] ?? 'en'),
            sprintf('- Currency: %s', $shopData['currency'] ?? 'USD'),
            sprintf('- Timezone: %s', $shopData['iana_timezone'] ?? 'UTC'),
        ];

        if (!empty($shopData['created_at'])) {
            $lines[] = sprintf('- Created At: %s', $shopData['created_at']);
        }

        if (!empty($shopData['email'])) {
            $lines[] = sprintf('- Contact Email: %s', $shopData['email']);
        }

        if (!empty($shopData['updated_at'])) {
            $lines[] = sprintf('- Updated At: %s', $shopData['updated_at']);
        }

        $lines[] = '';

        return $lines;
    }

    /**
     * Build products section.
     */
    protected function buildProductsSection(array $products, string $shopUrl, array $shopData): array
    {
        $lines = ['## Products', ''];
        $currency = $shopData['currency'] ?? 'USD';

        foreach ($products as $product) {
            $productLines = $this->buildProductLines($product, $shopUrl, $currency);
            $lines = array_merge($lines, $productLines);
        }

        return $lines;
    }

    /**
     * Build lines for a single product.
     */
    protected function buildProductLines(array $product, string $shopUrl, string $currency): array
    {
        $lines = [];
        $title = $product['title'] ?? '';
        $handle = $product['handle'] ?? '';
        $productUrl = sprintf('%s/products/%s', $shopUrl, $handle);
        $description = isset($product['body_html']) ? strip_tags($product['body_html']) : '';
        $updatedAt = $product['updated_at'] ?? '';
        $vendor = $product['vendor'] ?? '';
        $productType = $product['product_type'] ?? '';
        $status = $product['status'] ?? 'active';
        $availability = strtolower($status) === 'active' ? 'Available' : 'Unavailable';
        
        // Get first image
        $imageUrl = $this->getProductImage($product);

        // Product line
        $productLine = sprintf('- [%s](%s)', $title, $productUrl);
        if (!empty($description)) {
            $productLine .= ': ' . $description;
        }
        $lines[] = $productLine;

        // Product metadata
        if (!empty($updatedAt)) {
            $lines[] = sprintf('  Updated: %s', $updatedAt);
        }
        if (!empty($vendor)) {
            $lines[] = sprintf('  Vendor: %s', $vendor);
        }
        if (!empty($productType)) {
            $lines[] = sprintf('  Product Type: %s', $productType);
        }
        $lines[] = sprintf('  Availability: %s', $availability);
        
        if (!empty($imageUrl)) {
            $lines[] = sprintf('  Image: %s', $imageUrl);
        }

        // Price and variants
        $variants = $product['variants'] ?? [];
        if (!empty($variants)) {
            $firstVariant = reset($variants);
            $price = $firstVariant['price'] ?? '';
            if (!empty($price)) {
                $lines[] = sprintf('  Price: $%s %s', number_format((float)$price, 2, '.', ''), $currency);
            }
        }

        // Variants (if more than one)
        if (count($variants) > 1) {
            $variantLines = $this->buildVariantLines($variants, $productUrl, $currency);
            $lines = array_merge($lines, $variantLines);
        }

        return $lines;
    }

    /**
     * Get the first product image URL.
     */
    protected function getProductImage(array $product): string
    {
        if (empty($product['images']) || !is_array($product['images'])) {
            return '';
        }

        $firstImage = reset($product['images']);
        return $firstImage['src'] ?? '';
    }

    /**
     * Build variant lines for a product.
     */
    protected function buildVariantLines(array $variants, string $productUrl, string $currency): array
    {
        $lines = [];

        foreach ($variants as $variant) {
            $variantTitle = $variant['title'] ?? '';
            $variantId = $variant['id'] ?? '';
            $variantPrice = $variant['price'] ?? '';
            $variantInventoryPolicy = $variant['inventory_policy'] ?? 'deny';
            $variantAvailable = ($variant['inventory_quantity'] ?? 0) > 0 || $variantInventoryPolicy === 'continue';
            $variantAvailability = $variantAvailable ? 'Available' : 'Out of stock';
            
            // Build variant URL
            $variantUrl = $productUrl;
            if (!empty($variantId) && $variantTitle !== 'Default Title') {
                $variantUrl .= '?variant=' . $variantId;
            }
            
            $lines[] = sprintf('  - [%s](%s)', $variantTitle ?: 'Default', $variantUrl);
            $lines[] = sprintf('    Availability: %s', $variantAvailability);
            
            if (!empty($variantPrice)) {
                $lines[] = sprintf('    Price: $%s %s', number_format((float)$variantPrice, 2, '.', ''), $currency);
            }
        }

        return $lines;
    }

    /**
     * Save the markdown content to a file in storage.
     */
    protected function saveMarkdownFile(string $shopDomain, $shop, string $markdown)
    {
        $shopId = $shop->getId()->toNative();
        $filename = "llm/{$shopId}.txt";
        
        try {
            Storage::put($filename, $markdown);
            
            // Update the shop's llm_generated_at field
            $shop->llm_generated_at = now();
            $shop->save();
            
            return response()->json([
                'success' => true,
                'message' => 'LLMs.txt file generated successfully',
                'filename' => basename($filename),
                'path' => Storage::path($filename),
                'redirect_url' => 'https://' . $shopDomain . ("/llms.txt"),
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error saving LLMs.txt file', [
                'shop_id' => $shopId,
                'filename' => $filename,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error saving file: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Retrieve and display the LLMs.txt file for a shop by domain.
     */
    public function show(Request $request, IShopQuery $shopQuery)
    {
        $shopDomain = $request->input('shop');
        
        if (empty($shopDomain)) {
            return response('shop_domain parameter is required', 400)
                ->header('Content-Type', 'text/plain');
        }

        try {
            $shop = $shopQuery->getByDomain(
                ShopDomain::fromNative($shopDomain)
            );

            if (!$shop) {
                return response("Shop not found: {$shopDomain}", 404)
                    ->header('Content-Type', 'text/plain');
            }

            $shopId = $shop->getId()->toNative();
            $filename = "llm/{$shopId}.txt";

            if (!Storage::exists($filename)) {
                return response("LLMs.txt file not found for shop: {$shopDomain}", 404)
                    ->header('Content-Type', 'text/plain');
            }

            $content = Storage::get($filename);

            return response($content, 200)
                ->header('Content-Type', 'text/plain; charset=UTF-8');
        } catch (\Exception $e) {
            Log::error('Error retrieving LLMs.txt file', [
                'shop_domain' => $shopDomain,
                'error' => $e->getMessage(),
            ]);

            return response('Error retrieving file: ' . $e->getMessage(), 500)
                ->header('Content-Type', 'text/plain');
        }
    }

    /**
     * Create or update redirect URL in Shopify to point llms.txt to our endpoint.
     */
    protected function createRedirectUrl($api, string $shopDomain): void
    {
        try {
            // Build the target URL - using app/sellgpt/llms as requested
            $targetUrl = 'https://' . $shopDomain . ("/apps/sellgpt/llms");
            
            // First, check if redirect already exists
            $existingRedirects = $api->rest('GET', '/admin/redirects.json', [
                'path' => '/llms.txt',
                'limit' => 1,
            ]);

            $redirectId = null;
            if (isset($existingRedirects['body']['redirects']) && !empty($existingRedirects['body']['redirects'])) {
                $redirectId = $existingRedirects['body']['redirects'][0]['id'] ?? null;
            }

            $redirectData = [
                'redirect' => [
                    'path' => '/llms.txt',
                    'target' => $targetUrl,
                ],
            ];

            if ($redirectId) {
                // Update existing redirect
                $api->rest('PUT', "/admin/redirects/{$redirectId}.json", $redirectData);
                Log::info('Updated existing Shopify redirect', [
                    'redirect_id' => $redirectId,
                    'path' => '/llms.txt',
                    'target' => $targetUrl,
                ]);
            } else {
                // Create new redirect
                $result = $api->rest('POST', '/admin/redirects.json', $redirectData);
                Log::info('Created Shopify redirect', [
                    'path' => '/llms.txt',
                    'target' => $targetUrl,
                    'result' => $result['body'] ?? null,
                ]);
            }
        } catch (\Exception $e) {
            // Log error but don't fail the entire generation process
            Log::warning('Failed to create Shopify redirect', [
                'shop_domain' => $shopDomain,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
