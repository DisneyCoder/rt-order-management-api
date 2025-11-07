<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Stock;
use App\Models\StockLog;
use Illuminate\Support\Str;
use App\Models\OrderProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function index()
    {
        $orders = Order::with('items.product','items.stock')->orderBy('ordered_at','desc')->paginate(20);
        return response()->json($orders);
    }

    public function show($id)
    {
        $order = Order::with('items.product','items.stock')->findOrFail($id);
        return response()->json($order);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'customer_name' => 'nullable|string',
            'status' => 'nullable|in:Pending,Processing,Delivered,Cancelled',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
        ]);

        DB::beginTransaction();
        try {
            $order = Order::create([
                'invoice_number' => $this->generateInvoiceNumber(),
                'created_at' => Carbon::now(),
                'customer_name' => $data['customer_name'] ?? null,
                'status' => $data['status'] ?? 'Pending',
                'total_amount' => 0,
            ]);

            $totalAmount = 0;

            foreach ($data['items'] as $item) {
                $productId = $item['product_id'];
                $needQty = $item['quantity'];

                $stocks = Stock::where('product_id', $productId)
                               ->where('quantity', '>', 0)
                               ->orderBy('created_at', 'asc')
                               ->lockForUpdate()
                               ->get();

                if ($stocks->sum('quantity') < $needQty) {
                    DB::rollBack();
                    return response()->json([
                        'message' => "Insufficient stock for product id {$productId}"
                    ]);
                }

                foreach ($stocks as $stock) {
                    if ($needQty <= 0) break;

                    $take = min($stock->quantity, $needQty);

                    $previous = $stock->quantity;
                    $stock->quantity = $stock->quantity - $take;
                    $stock->save();

                    // record order_product for this chunk
                    $subTotal = $take * $stock->sale_price;
                    $profitPercent = null;
                    if ($stock->purchase_price > 0) {
                        $profitPercent = (($stock->sale_price - $stock->purchase_price) / $stock->purchase_price) * 100;
                    }

                    OrderProduct::create([
                        'order_id' => $order->id,
                        'product_id' => $productId,
                        'stock_id' => $stock->id,
                        'sale_price' => $stock->sale_price,
                        
                        'sub_total' => $subTotal,
                        'profit' => $profitPercent,
                    ]);

                    StockLog::create([
                        'type' => 'Order-create',
                        'stock_id' => $stock->id,
                        'product_id' => $productId,
                        'previous_quantity' => $previous,
                        'change_quantity' => -$take,
                        'current_quantity' => $stock->quantity,
                    ]);

                    $totalAmount += $subTotal;
                    $needQty -= $take;
                }
            }

            $order->total_amount = $totalAmount;
            $order->save();

            DB::commit();
            return response()->json($order->load('items.product','items.stock'), 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Order creation fail']);
        }
    }

    public function update(Request $request, $id)
    {
        $data = $request->validate([
            'customer_name' => 'nullable|string',
            'status' => 'nullable|in:Pending,Processing,Delivered,Cancelled',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
        ]);

        DB::beginTransaction();
        try {
            $order = Order::with('items')->findOrFail($id);

            foreach ($order->items as $item) {
                $stock = Stock::lockForUpdate()->find($item->stock_id);
                if ($stock) {
                    $previous = $stock->quantity;
                    $stock->quantity += $item->quantity;
                    $stock->save();
                    StockLog::create([
                        'type' => 'Order-update',
                        'stock_id' => $stock->id,
                        'product_id' => $item->product_id,
                        'previous_quantity' => $previous,
                        'change_quantity' => $item->quantity,
                        'current_quantity' => $stock->quantity,
                    ]);
                }
            }

            $order->items()->delete();

            $totalAmount = 0;

            foreach ($data['items'] as $item) {
                $productId = $item['product_id'];
                $needQty = $item['quantity'];

                $stocks = Stock::where('product_id', $productId)
                               ->where('quantity', '>', 0)
                               ->orderBy('created_at', 'asc')
                               ->lockForUpdate()
                               ->get();

                if ($stocks->sum('quantity') < $needQty) {
                    DB::rollBack();
                    return response()->json([
                        'message' => "Insufficient stock for product id {$productId}"
                    ], 422);
                }

                foreach ($stocks as $stock) {
                    if ($needQty <= 0) break;

                    $take = min($stock->quantity, $needQty);

                    $previous = $stock->quantity;
                    $stock->quantity = $stock->quantity - $take;
                    $stock->save();

                    $subTotal = $take * $stock->sale_price;
                    $profitPercent = null;
                    if ($stock->purchase_price > 0) {
                        $profitPercent = (($stock->sale_price - $stock->purchase_price) / $stock->purchase_price) * 100;
                    }

                    OrderProduct::create([
                        'order_id' => $order->id,
                        'product_id' => $productId,
                        'stock_id' => $stock->id,
                        'sale_price' => $stock->sale_price,
                        'quantity' => $take,
                        'sub_total' => $subTotal,
                        'profit' => $profitPercent,
                    ]);

                    StockLog::create([
                        'type' => 'Order-update',
                        'stock_id' => $stock->id,
                        'product_id' => $productId,
                        'previous_quantity' => $previous,
                        'change_quantity' => -$take,
                        'current_quantity' => $stock->quantity,
                    ]);

                    $totalAmount += $subTotal;
                    $needQty -= $take;
                }
            }

            $order->customer_name = $data['customer_name'] ?? $order->customer_name;
            $order->status = $data['status'] ?? $order->status;
            $order->total_amount = $totalAmount;
            $order->save();

            DB::commit();
            return response()->json($order->load('items.product','items.stock'));
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message'=>'updating order fail',$e->getMessage()]);
        }
    }

    public function destroy($id)
    {
        DB::beginTransaction();
        try {
            $order = Order::with('items')->findOrFail($id);

            foreach ($order->items as $item) {
                $stock = Stock::lockForUpdate()->find($item->stock_id);
                if ($stock) {
                    $previous = $stock->quantity;
                    $stock->quantity += $item->quantity;
                    $stock->save();

                    StockLog::create([
                        'type' => 'Order-delete',
                        'stock_id' => $stock->id,
                        'product_id' => $item->product_id,
                        'previous_quantity' => $previous,
                        'change_quantity' => $item->quantity,
                        'current_quantity' => $stock->quantity,
                    ]);
                }
            }

            $order->delete();
            DB::commit();

            return response()->json(['message' => 'Order deleted']);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message'=>'Deleting order fail',$e->getMessage()],);
        }
    }


    protected function generateInvoiceNumber()
    {
        return strtoupper('INV-'.date('Ymd').'-'.Str::upper(Str::random(6)));
    }
}
