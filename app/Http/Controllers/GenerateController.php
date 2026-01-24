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
            $collections = $this->fetchCollections($api);
            $pages = $this->fetchPages($api);
            $blogs = $this->fetchBlogs($api);
        } catch (\Exception $e) {
            return $this->handleApiError($e, $shop);
        }

        $shopUrl = 'https://' . $shop->getDomain()->toNative();
        $shopDomain = $shop->getDomain()->toNative();
        $markdown = $this->buildMarkdown($shopData, $products, $collections, $pages, $blogs, $shopUrl);
        
        // Save counts to user model
        $this->saveContentCounts($shop, $products, $collections, $pages, $blogs);
        
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
     * Fetch collections from Shopify API.
     */
    protected function fetchCollections($api)
    {
        $collectionsResult = $api->rest('GET', '/admin/api/2024-10/smart_collections.json', [
            'limit' => 250,
            'fields' => 'id,title,handle,body_html,updated_at,published_at',
        ]);

        if (isset($collectionsResult['errors']) && $collectionsResult['errors'] === true) {
            Log::warning('Error fetching collections', ['error' => $collectionsResult['body'] ?? 'Unknown error']);
            return [];
        }

        $smartCollections = $collectionsResult['body']['smart_collections'] ?? [];
        
        // Convert ResponseAccess to array if needed
        if (is_object($smartCollections) && method_exists($smartCollections, 'toArray')) {
            $smartCollections = $smartCollections->toArray();
        }
        if (!is_array($smartCollections)) {
            $smartCollections = [];
        }

        // Also fetch custom collections
        $customCollectionsResult = $api->rest('GET', '/admin/api/2024-10/custom_collections.json', [
            'limit' => 250,
            'fields' => 'id,title,handle,body_html,updated_at,published_at',
        ]);

        $customCollections = [];
        if (!isset($customCollectionsResult['errors']) || $customCollectionsResult['errors'] !== true) {
            $customCollections = $customCollectionsResult['body']['custom_collections'] ?? [];
            
            // Convert ResponseAccess to array if needed
            if (is_object($customCollections) && method_exists($customCollections, 'toArray')) {
                $customCollections = $customCollections->toArray();
            }
            if (!is_array($customCollections)) {
                $customCollections = [];
            }
        }

        // Merge both types (now guaranteed to be arrays)
        $allCollections = array_merge($smartCollections, $customCollections);

        return $allCollections;
    }

    /**
     * Fetch pages from Shopify API.
     */
    protected function fetchPages($api)
    {
        $pagesResult = $api->rest('GET', '/admin/api/2024-10/pages.json', [
            'limit' => 250,
            'fields' => 'id,title,handle,body_html,updated_at,published_at',
        ]);

        if (isset($pagesResult['errors']) && $pagesResult['errors'] === true) {
            Log::warning('Error fetching pages', ['error' => $pagesResult['body'] ?? 'Unknown error']);
            return [];
        }

        $pages = $pagesResult['body']['pages'] ?? null;

        // Convert ResponseAccess to array if needed
        if (is_object($pages) && method_exists($pages, 'toArray')) {
            return $pages->toArray();
        }

        return is_array($pages) ? $pages : [];
    }

    /**
     * Fetch blogs and their articles from Shopify API.
     */
    protected function fetchBlogs($api)
    {
        $blogsResult = $api->rest('GET', '/admin/api/2024-10/blogs.json', [
            'limit' => 250,
            'fields' => 'id,title,handle,updated_at',
        ]);

        if (isset($blogsResult['errors']) && $blogsResult['errors'] === true) {
            Log::warning('Error fetching blogs', ['error' => $blogsResult['body'] ?? 'Unknown error']);
            return [];
        }

        $blogs = $blogsResult['body']['blogs'] ?? [];

        // Convert ResponseAccess to array if needed
        if (is_object($blogs) && method_exists($blogs, 'toArray')) {
            $blogs = $blogs->toArray();
        }

        if (!is_array($blogs)) {
            return [];
        }

        // Fetch articles for each blog
        $blogsWithArticles = [];
        foreach ($blogs as $blog) {
            $blogId = $blog['id'] ?? null;
            if (!$blogId) {
                continue;
            }

            try {
                $articlesResult = $api->rest('GET', "/admin/api/2024-10/blogs/{$blogId}/articles.json", [
                    'limit' => 250,
                    'fields' => 'id,title,handle,body_html,updated_at,published_at,author',
                ]);

                $articles = [];
                if (!isset($articlesResult['errors']) || $articlesResult['errors'] !== true) {
                    $articles = $articlesResult['body']['articles'] ?? [];
                    
                    // Convert ResponseAccess to array if needed
                    if (is_object($articles) && method_exists($articles, 'toArray')) {
                        $articles = $articles->toArray();
                    }
                }

                $blogsWithArticles[] = [
                    'blog' => $blog,
                    'articles' => is_array($articles) ? $articles : [],
                ];
            } catch (\Exception $e) {
                Log::warning('Error fetching articles for blog', [
                    'blog_id' => $blogId,
                    'error' => $e->getMessage(),
                ]);
                $blogsWithArticles[] = [
                    'blog' => $blog,
                    'articles' => [],
                ];
            }
        }

        return $blogsWithArticles;
    }

    /**
     * Save content counts to the user model.
     */
    protected function saveContentCounts($shop, array $products, array $collections, array $pages, array $blogs)
    {
        try {
            $productsCount = count($products);
            $collectionsCount = count($collections);
            $pagesCount = count($pages);
            
            // Count total articles across all blogs
            $blogsCount = 0;
            foreach ($blogs as $blogData) {
                $blogsCount += count($blogData['articles'] ?? []);
            }

            $shop->products_count = $productsCount;
            $shop->collections_count = $collectionsCount;
            $shop->pages_count = $pagesCount;
            $shop->blogs_count = $blogsCount;
            $shop->save();

            Log::info('Content counts saved', [
                'shop_id' => $shop->getId()->toNative(),
                'products' => $productsCount,
                'collections' => $collectionsCount,
                'pages' => $pagesCount,
                'blogs' => $blogsCount,
            ]);
        } catch (\Exception $e) {
            Log::error('Error saving content counts', [
                'shop_id' => $shop->getId()->toNative() ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
        }
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
    protected function buildMarkdown(array $shopData, array $products, array $collections, array $pages, array $blogs, string $shopUrl): string
    {
        $lines = [];
        
        $lines = array_merge($lines, $this->buildShopHeader($shopData, $shopUrl));
        $lines = array_merge($lines, $this->buildShopMetadata($shopData, $shopUrl));
        $lines = array_merge($lines, $this->buildProductsSection($products, $shopUrl, $shopData));
        $lines = array_merge($lines, $this->buildCollectionsSection($collections, $shopUrl));
        $lines = array_merge($lines, $this->buildPagesSection($pages, $shopUrl));
        $lines = array_merge($lines, $this->buildBlogsSection($blogs, $shopUrl));

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
     * Build collections section.
     */
    protected function buildCollectionsSection(array $collections, string $shopUrl): array
    {
        if (empty($collections)) {
            return [];
        }

        $lines = ['## Collections', ''];

        foreach ($collections as $collection) {
            $title = $collection['title'] ?? '';
            $handle = $collection['handle'] ?? '';
            $collectionUrl = sprintf('%s/collections/%s', $shopUrl, $handle);
            $description = isset($collection['body_html']) ? strip_tags($collection['body_html']) : '';
            $updatedAt = $collection['updated_at'] ?? '';
            $publishedAt = $collection['published_at'] ?? '';

            $collectionLine = sprintf('- [%s](%s)', $title, $collectionUrl);
            if (!empty($description)) {
                $collectionLine .= ': ' . $description;
            }
            $lines[] = $collectionLine;

            if (!empty($updatedAt)) {
                $lines[] = sprintf('  Updated: %s', $updatedAt);
            }
            if (!empty($publishedAt)) {
                $lines[] = sprintf('  Published: %s', $publishedAt);
            }
        }

        $lines[] = '';
        return $lines;
    }

    /**
     * Build pages section.
     */
    protected function buildPagesSection(array $pages, string $shopUrl): array
    {
        if (empty($pages)) {
            return [];
        }

        $lines = ['## Pages', ''];

        foreach ($pages as $page) {
            $title = $page['title'] ?? '';
            $handle = $page['handle'] ?? '';
            $pageUrl = sprintf('%s/pages/%s', $shopUrl, $handle);
            $description = isset($page['body_html']) ? strip_tags($page['body_html']) : '';
            $updatedAt = $page['updated_at'] ?? '';
            $publishedAt = $page['published_at'] ?? '';

            $pageLine = sprintf('- [%s](%s)', $title, $pageUrl);
            if (!empty($description)) {
                // Truncate long descriptions
                $description = mb_strlen($description) > 200 ? mb_substr($description, 0, 200) . '...' : $description;
                $pageLine .= ': ' . $description;
            }
            $lines[] = $pageLine;

            if (!empty($updatedAt)) {
                $lines[] = sprintf('  Updated: %s', $updatedAt);
            }
            if (!empty($publishedAt)) {
                $lines[] = sprintf('  Published: %s', $publishedAt);
            }
        }

        $lines[] = '';
        return $lines;
    }

    /**
     * Build blogs section.
     */
    protected function buildBlogsSection(array $blogs, string $shopUrl): array
    {
        if (empty($blogs)) {
            return [];
        }

        $lines = ['## Blog Posts', ''];

        foreach ($blogs as $blogData) {
            $blog = $blogData['blog'] ?? [];
            $articles = $blogData['articles'] ?? [];
            $blogHandle = $blog['handle'] ?? '';

            foreach ($articles as $article) {
                $title = $article['title'] ?? '';
                $handle = $article['handle'] ?? '';
                $articleUrl = sprintf('%s/blogs/%s/%s', $shopUrl, $blogHandle, $handle);
                $description = isset($article['body_html']) ? strip_tags($article['body_html']) : '';
                $updatedAt = $article['updated_at'] ?? '';
                $publishedAt = $article['published_at'] ?? '';
                $author = $article['author'] ?? '';

                $articleLine = sprintf('- [%s](%s)', $title, $articleUrl);
                if (!empty($description)) {
                    // Truncate long descriptions
                    $description = mb_strlen($description) > 200 ? mb_substr($description, 0, 200) . '...' : $description;
                    $articleLine .= ': ' . $description;
                }
                $lines[] = $articleLine;

                if (!empty($author)) {
                    $lines[] = sprintf('  Author: %s', $author);
                }
                if (!empty($updatedAt)) {
                    $lines[] = sprintf('  Updated: %s', $updatedAt);
                }
                if (!empty($publishedAt)) {
                    $lines[] = sprintf('  Published: %s', $publishedAt);
                }
            }
        }

        $lines[] = '';
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
