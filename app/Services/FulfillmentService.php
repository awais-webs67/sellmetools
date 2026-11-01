<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\StockStatus;
use App\Enums\SupplierOrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StockItem;
use App\Models\SupplierOrder;
use App\Models\SupplierProduct;
use App\Services\Suppliers\Exceptions\SupplierAuthException;
use App\Services\Suppliers\Exceptions\SupplierException;
use App\Services\Suppliers\Exceptions\SupplierInsufficientBalanceException;
use App\Services\Suppliers\Exceptions\SupplierOutOfStockException;
use App\Services\Suppliers\Exceptions\SupplierUnknownResultException;
use App\Services\Suppliers\SupplierManager;
use App\Services\TelegramNotifier as T;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Delivers paid orders.
 *
 * Order of preference per item:
 *   1. our own stock reserved at checkout
 *   2. any newly added own stock
 *   3. supplier APIs – cheapest profitable source first, automatic failover to the next one
 *
 * Safety rules:
 *   - a cache lock prevents two workers fulfilling the same order concurrently
 *   - each supplier attempt has a deterministic, unique request_id, so the same
 *     (order item, source) pair is never charged twice
 *   - an "unknown" supplier result stops automation for that item (manual review)
 *   - a source is skipped if its current cost would make the sale a loss
 */
class FulfillmentService
{
    public function __construct(
        protected SupplierManager $suppliers,
        protected SupplierSyncService $sync,
        protected PriceCalculator $pricing,
        protected InventoryService $inventory,
        protected T $telegram,
    ) {}

    public function fulfill(Order $order): Order
    {
        $lock = Cache::lock("fulfill-order-{$order->id}", 300);

        if (! $lock->get()) {
            return $order;
        }

        try {
            $order->refresh();

            if (! in_array($order->status, [OrderStatus::Processing, OrderStatus::NeedsAttention], true)) {
                return $order;
            }

            $notes = [];
            foreach ($order->items()->with('product')->get() as $item) {
                $notes = array_merge($notes, $this->fulfillItem($order, $item));
            }

            return $this->finalize($order->refresh(), $notes);
        } finally {
            $lock->release();
        }
    }

    /** @return string[] problems encountered (for admin alert) */
    protected function fulfillItem(Order $order, OrderItem $item): array
    {
        $problems = [];

        // 1 + 2: own stock (reserved for this item, then any free units).
        $this->deliverOwnStock($item);

        if ($item->remainingQty() === 0 || ! $item->product) {
            return $problems;
        }

        // 3: suppliers.
        foreach ($this->pricing->rankedSources($item->product) as $source) {
            if ($item->remainingQty() === 0) {
                break;
            }

            /** @var SupplierProduct $source */
            if ($source->costPkr() > (float) $item->unit_price) {
                $problems[] = "Skipped {$source->supplier->name}: cost Rs ".round($source->costPkr(), 2)." > sold at Rs {$item->unit_price}";

                continue;
            }

            $outcome = $this->orderFromSupplier($order, $item, $source);

            if ($outcome === 'unknown') {
                $problems[] = "Unknown result from {$source->supplier->name} – check supplier panel before retrying.";
                break;
            }

            if ($outcome !== 'ok') {
                $problems[] = "{$source->supplier->name}: {$outcome}";
            }
        }

        if ($item->remainingQty() > 0 && $problems === []) {
            $problems[] = 'No supplier source available.';
        }

        return $problems;
    }

    protected function deliverOwnStock(OrderItem $item): void
    {
        DB::transaction(function () use ($item) {
            $item->refresh();
            $need = $item->remainingQty();
            if ($need === 0) {
                return;
            }

            $reserved = StockItem::query()
                ->where('order_item_id', $item->id)
                ->where('status', StockStatus::Reserved)
                ->lockForUpdate()
                ->limit($need)
                ->get();

            $extra = collect();
            if ($reserved->count() < $need && $item->product_id) {
                $extra = StockItem::query()
                    ->where('product_id', $item->product_id)
                    ->where('status', StockStatus::Available)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->limit($need - $reserved->count())
                    ->get();
            }

            foreach ($reserved->concat($extra) as $stock) {
                $stock->update(['status' => StockStatus::Sold, 'order_item_id' => $item->id]);
                $item->deliveries()->create([
                    'content' => $stock->content,
                    'source' => 'stock',
                    'stock_item_id' => $stock->id,
                ]);
            }

            if ($extra->isNotEmpty()) {
                // Extra own units replace units we expected to buy from suppliers.
                $item->reserved_supplier_qty = max(0, $item->reserved_supplier_qty - $extra->count());
            }

            $item->delivered_qty += $reserved->count() + $extra->count();
            $item->save();
        });
    }

    /** @return string 'ok' | 'unknown' | error description */
    protected function orderFromSupplier(Order $order, OrderItem $item, SupplierProduct $source): string
    {
        $supplier = $source->supplier;

        $previous = SupplierOrder::where('order_item_id', $item->id)
            ->where('supplier_product_id', $source->id)
            ->get();

        // Never send again while an earlier request has an unclear outcome.
        if ($previous->contains(fn ($a) => in_array($a->status, [SupplierOrderStatus::Unknown, SupplierOrderStatus::Pending], true))) {
            return 'unknown';
        }

        // Deterministic id: retries after a definitive failure get a new attempt number.
        $attemptNo = $previous->count() + 1;
        $requestId = "{$order->number}-{$item->id}-{$source->id}-{$attemptNo}";

        $units = $source->availableUnits();
        $qty = $units === null ? $item->remainingQty() : min($item->remainingQty(), $units);
        if ($qty < 1) {
            return 'out of stock';
        }

        // Pre-check supplier balance before placing API order: if supplier balance is low, pause order for manual WhatsApp confirmation!
        if ($supplier->balance !== null && (float) $supplier->balance < ((float) $source->cost * $qty)) {
            $this->telegram->notifyAdmin(
                "⏸️ <b>Order Paused (Supplier Balance Low)</b>\n".
                "Order: <b>{$order->number}</b>\n".
                'Supplier: '.T::e($supplier->name).' (Balance: '.$supplier->balance.', Needed: '.((float) $source->cost * $qty).")\n".
                'Customer is being prompted to confirm manually via WhatsApp.'
            );

            return 'insufficient supplier balance';
        }

        $attempt = SupplierOrder::create([
            'supplier_id' => $supplier->id,
            'order_item_id' => $item->id,
            'supplier_product_id' => $source->id,
            'request_id' => $requestId,
            'quantity' => $qty,
            'status' => SupplierOrderStatus::Pending,
        ]);

        try {
            $result = $this->suppliers->driver($supplier)->placeOrder($source->external_id, $qty, $requestId);
        } catch (SupplierUnknownResultException $e) {
            $attempt->update(['status' => SupplierOrderStatus::Unknown, 'error' => $e->getMessage(), 'response' => $e->context]);

            return 'unknown';
        } catch (SupplierOutOfStockException $e) {
            $attempt->update(['status' => SupplierOrderStatus::Failed, 'error' => $e->getMessage()]);
            $source->update(['stock' => 0, 'is_available' => false]);

            return 'out of stock';
        } catch (SupplierInsufficientBalanceException $e) {
            $attempt->update(['status' => SupplierOrderStatus::Failed, 'error' => $e->getMessage()]);
            $this->telegram->notifyAdmin("💸 <b>Supplier balance too low</b>\n".T::e($supplier->name)." could not fulfil order {$order->number}. Top up now.");

            return 'insufficient supplier balance';
        } catch (SupplierAuthException $e) {
            $attempt->update(['status' => SupplierOrderStatus::Failed, 'error' => $e->getMessage()]);

            return 'API key rejected';
        } catch (SupplierException $e) {
            $attempt->update(['status' => SupplierOrderStatus::Failed, 'error' => $e->getMessage(), 'response' => $e->context]);

            return $e->getMessage();
        } catch (Throwable $e) {
            // Unexpected error after the request may have been sent – treat as unknown.
            $attempt->update(['status' => SupplierOrderStatus::Unknown, 'error' => $e->getMessage()]);
            report($e);

            return 'unknown';
        }

        $delivered = array_slice($result->items, 0, $qty);
        $cost = $result->total ?? ((float) $source->cost * count($delivered));

        DB::transaction(function () use ($attempt, $result, $item, $delivered, $cost, $supplier) {
            $attempt->update([
                'status' => SupplierOrderStatus::Success,
                'cost' => $cost,
                'external_order_id' => $result->externalOrderId,
                'response' => collect($result->raw)->except('order.items')->all(),
            ]);

            foreach ($delivered as $line) {
                $item->deliveries()->create([
                    'content' => $line,
                    'source' => 'supplier',
                    'supplier_order_id' => $attempt->id,
                ]);
            }

            $item->delivered_qty += count($delivered);
            $item->reserved_supplier_qty = max(0, $item->reserved_supplier_qty - count($delivered));
            $item->cost = round((float) $item->cost + $supplier->toPkr($cost), 2);
            $item->save();
        });

        if ($result->balanceAfter !== null) {
            $this->sync->recordBalance($supplier, $result->balanceAfter);
        }

        if ($source->stock !== null && ! $source->unlimited_stock) {
            $source->decrement('stock', min($source->stock, count($delivered)));
        }

        return count($delivered) < $qty ? 'delivered fewer items than requested' : 'ok';
    }

    /** @param string[] $problems */
    protected function finalize(Order $order, array $problems): Order
    {
        $items = $order->items()->get();

        foreach ($items as $item) {
            $item->status = match (true) {
                $item->delivered_qty >= $item->quantity => 'delivered',
                $item->delivered_qty > 0 => 'partial',
                default => 'failed',
            };
            $item->save();
        }

        $complete = $items->every(fn (OrderItem $i) => $i->delivered_qty >= $i->quantity);

        if ($complete) {
            $order->update([
                'status' => OrderStatus::Completed,
                'completed_at' => now(),
                'total_cost' => $items->sum(fn ($i) => (float) $i->cost),
            ]);

            foreach ($items as $item) {
                $item->product?->increment('sold_count', $item->quantity);
            }

            $profit = round((float) $order->total - (float) $order->total_cost, 2);
            $this->telegram->notifyAdmin(
                "🎉✅ <b>ORDER DELIVERED SUCCESSFULLY!</b>\n".
                "━━━━━━━━━━━━━━━━━━\n".
                "🧾 <b>Order:</b> <code>{$order->number}</code>\n".
                $items->map(fn ($i) => '📦 '.T::e($i->product_name)." × {$i->quantity}")->implode("\n")."\n".
                "💵 <b>Sale:</b> Rs {$order->total} · <b>Cost:</b> Rs {$order->total_cost} · 💰 <b>Profit: Rs {$profit}</b>\n".
                '👤 <b>Customer:</b> '.T::e($order->email)
            );
            app(WhatsAppNotifier::class)->notifyOrderDelivered($order);
        } else {
            $order->update([
                'status' => OrderStatus::NeedsAttention,
                'admin_note' => implode(' | ', array_unique($problems)) ?: 'Order on hold — awaiting admin stock dispatch',
            ]);
            $this->telegram->notifyAdmin(
                "⏸️⚠️ <b>ORDER ON HOLD — SUPPLIER FUNDS / STOCK ISSUE!</b>\n".
                "━━━━━━━━━━━━━━━━━━\n".
                "✅ <b>Payment Status:</b> VERIFIED (Rs {$order->total})\n".
                "🧾 <b>Order:</b> <code>{$order->number}</code>\n".
                $items->map(fn ($i) => '📦 '.T::e($i->product_name)." × {$i->quantity}")->implode("\n")."\n".
                '⚠️ <b>Reason:</b> '.T::e(implode(' | ', array_unique($problems)) ?: 'Supplier balance is low.')."\n".
                '👤 <b>Customer:</b> '.T::e($order->email)."\n".
                "ℹ️ <i>Customer screen shows: 'Order On Hold — Admin is checking your order.'</i>"
            );
            app(WhatsAppNotifier::class)->notifyOrderOnHoldLowBalance($order, $problems);
        }

        $this->inventory->refreshMany($items->pluck('product')->filter());

        return $order->refresh();
    }

    /** Admin manual delivery: one line per unit. */
    public function deliverManually(OrderItem $item, array $lines): Order
    {
        $lines = array_values(array_filter(array_map('trim', $lines)));

        DB::transaction(function () use ($item, $lines) {
            foreach (array_slice($lines, 0, $item->remainingQty()) as $line) {
                $item->deliveries()->create(['content' => $line, 'source' => 'manual']);
                $item->delivered_qty++;
            }
            $item->reserved_supplier_qty = max(0, $item->quantity - $item->delivered_qty - $item->stockItems()->where('status', StockStatus::Reserved)->count());
            $item->save();
        });

        $order = $item->order->refresh();
        if ($order->status !== OrderStatus::Completed) {
            $order->update(['status' => OrderStatus::Processing]);
        }

        return $this->fulfill($order);
    }
}
