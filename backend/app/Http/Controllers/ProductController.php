<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function search()
{
    $query = request('query');

     if (!$query || strlen($query) < 2) {
        return response()->json([]);
    }
    
    $products = Product::with(['stocks' => function($q) {
        $q->where('quantity', '>', 0)
          ->orderBy('created_at', 'asc');
    }])
    ->where(function($q) use ($query) {
        $q->where('name', 'like', "%{$query}%")
          ->orWhere('barcode', 'like', "%{$query}%");
    })
    ->whereHas('stocks', function($q) {
        $q->where('quantity', '>', 0);
    })
    ->get();

    return response()->json($products, 200);
}
}
