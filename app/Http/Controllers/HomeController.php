<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function index() {
        $shop = auth()->user();

        if (! $shop) {
            return response('Unauthorized', 401);
        }
        
        return view("welcome", [
            'shop' => $shop,
            'llm_generated' => !empty($shop->llm_generated_at),
            'llm_generated_at' => $shop->llm_generated_at,
        ]);
    }
    function show() {
        $shop = auth()->user();
        $productsResponse = $shop->api()->rest('GET', '/admin/api/2026-01/products.json');
        logger()->info('Products: ' . json_encode($productsResponse));
        $products = $productsResponse['body']['products'] ?? [];
        $error = $productsResponse['errors'] ? $productsResponse['body']: '';
        return view('welcome', compact('products', 'error'));
    }
}
