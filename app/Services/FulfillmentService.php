<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\StockStatus;
use App\Enums\SupplierOrderStatus;
use App\Exceptions\StoreException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
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
 *   1. our own stock reserved at checkout (from .txt file / StockItem vault)
 *   2. any newly added own stock (Available in StockItem vault)
 *   3. if product delivery_type is 'manual_admin' or 'whatsapp_handover', holds for Admin / WhatsApp handover
 *   4. supplier APIs, cheapest profitable source first, automatic failover to the next one
 *
 * Safety rules:
 *   - NEVER marks an order Completed if credentials are empty or supplier failed!
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

        // 1 + 2: own stock (reserved for this item, then any free units from .txt / StockItem vault).
        $this->deliverOwnStock($item);

        if ($item->remainingQty() === 0 || ! $item->product) {
            return $problems;
        }

        $deliveryType = $item->product->delivery_type ?: 'auto';

        if ($deliveryType === 'manual_admin') {
            return ['Payment confirmed, awaiting Admin manual credential delivery.'];
        }

        if ($deliveryType === 'whatsapp_handover') {
            return ['Payment confirmed, customer directed to WhatsApp for order handover.'];
        }

        // 3: suppliers (for 'auto' products).
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
                $problems[] = "Unknown result from {$source->supplier->name}, check supplier panel before retrying.";
                break;
            }

            if ($outcome !== 'ok') {
                $problems[] = "{$source->supplier->name}: {$outcome}";
            }
        }

        if ($item->remainingQty() > 0 && $problems === []) {
            $problems[] = 'No own stock or supplier source available, awaiting manual delivery.';
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

            $deliveredCount = 0;
            $extraDeliveredCount = 0;

            foreach ($reserved->concat($extra) as $stock) {
                $content = trim((string) $stock->content);
                if ($content === '') {
                    // Never deliver an empty stock row!
                    $stock->delete();

                    continue;
                }

                $wasExtra = $extra->contains('id', $stock->id);
                $stock->update(['status' => StockStatus::Sold, 'order_item_id' => $item->id]);
                $item->deliveries()->create([
                    'content' => $content,
                    'source' => 'stock',
                    'stock_item_id' => $stock->id,
                ]);
                $deliveredCount++;
                if ($wasExtra) {
                    $extraDeliveredCount++;
                }
            }

            if ($extraDeliveredCount > 0) {
                // Extra own units replace units we expected to buy from suppliers.
                $item->reserved_supplier_qty = max(0, $item->reserved_supplier_qty - $extraDeliveredCount);
            }

            $item->delivered_qty += $deliveredCount;
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

        $neededCost = round((float) $source->cost * $qty, 4);

        // If local cached balance looks too low, try a quick live balance check first in case Admin just topped up!
        if ($supplier->balance !== null && (float) $supplier->balance < $neededCost) {
            try {
                $liveBalance = $this->suppliers->driver($supplier)->balance();
                $this->sync->recordBalance($supplier, $liveBalance->balance);
                $supplier->refresh();
            } catch (Throwable) {
                // Keep cached balance if live balance endpoint is unreachable
            }
        }

        // If supplier balance is still below required cost, record the failed SupplierOrder attempt so Admin sees it in Admin Panel!
        if ($supplier->balance !== null && (float) $supplier->balance < $neededCost) {
            SupplierOrder::create([
                'supplier_id' => $supplier->id,
                'order_item_id' => $item->id,
                'supplier_product_id' => $source->id,
                'request_id' => $requestId,
                'quantity' => $qty,
                'status' => SupplierOrderStatus::Failed,
                'error' => "Insufficient supplier balance (Balance: {$supplier->balance}, Needed: {$neededCost})",
            ]);

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

            return 'insufficient supplier balance';
        } catch (SupplierAuthException $e) {
            $attempt->update(['status' => SupplierOrderStatus::Failed, 'error' => $e->getMessage()]);

            return 'API key rejected';
        } catch (SupplierException $e) {
            $attempt->update(['status' => SupplierOrderStatus::Failed, 'error' => $e->getMessage(), 'response' => $e->context]);

            return $e->getMessage();
        } catch (Throwable $e) {
            // Unexpected error after the request may have been sent, treat as unknown.
            $attempt->update(['status' => SupplierOrderStatus::Unknown, 'error' => $e->getMessage()]);
            report($e);

            return 'unknown';
        }

        // Strict Empty Credential Guard: filter out any blank/empty items returned by supplier
        $cleanItems = array_values(array_filter(
            array_map(fn ($v): string => is_scalar($v) ? trim((string) $v) : '', $result->items),
            fn (string $line): bool => $line !== ''
        ));
        $delivered = array_slice($cleanItems, 0, $qty);

        if ($delivered === []) {
            $attempt->update([
                'status' => SupplierOrderStatus::Failed,
                'error' => 'Supplier returned empty credentials, order held for safety.',
                'response' => $result->raw,
            ]);

            return 'supplier returned empty credentials';
        }

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
        $items = $order->items()->with(['deliveries', 'product'])->get();

        foreach ($items as $item) {
            // Ensure delivered_qty matches actual non-empty delivery records so an empty order can NEVER be marked delivered!
            $validDeliveriesCount = $item->deliveries
                ->filter(fn ($d): bool => trim((string) $d->content) !== '')
                ->count();

            if ($item->delivered_qty > $validDeliveriesCount) {
                $item->delivered_qty = $validDeliveriesCount;
            }

            $item->status = match (true) {
                $item->delivered_qty >= $item->quantity && $item->quantity > 0 => 'delivered',
                $item->delivered_qty > 0 => 'partial',
                default => 'failed',
            };
            $item->save();
        }

        $complete = $items->isNotEmpty() && $items->every(fn (OrderItem $i): bool => $i->delivered_qty >= $i->quantity && $i->quantity > 0);

        if ($complete) {
            $order->update([
                'status' => OrderStatus::Completed,
                'completed_at' => now(),
                'total_cost' => $items->sum(fn ($i) => (float) $i->cost),
                'admin_note' => null,
            ]);

            foreach ($items as $item) {
                $item->product?->increment('sold_count', $item->quantity);
            }

            $profit = round((float) $order->total - (float) $order->total_cost, 2);
            $notifyDelivered = function () use ($order, $items, $profit): void {
                $this->telegram->notifyAdmin(
                    "🎉✅ <b>ORDER DELIVERED SUCCESSFULLY!</b>\n".
                    "━━━━━━━━━━━━━━━━━━\n".
                    "🧾 <b>Order:</b> <code>{$order->number}</code>\n".
                    $items->map(fn ($i) => '📦 '.T::e($i->product_name)." × {$i->quantity}")->implode("\n")."\n".
                    "💵 <b>Sale:</b> Rs {$order->total} · <b>Cost:</b> Rs {$order->total_cost} · 💰 <b>Profit: Rs {$profit}</b>\n".
                    '👤 <b>Customer:</b> '.T::e($order->email)
                );
                app(WhatsAppNotifier::class)->notifyOrderDelivered($order);
                app(EmailNotifier::class)->sendOrderDelivered($order);
            };

            if (app()->runningUnitTests()) {
                $notifyDelivered();
            } else {
                defer($notifyDelivered);
            }
        } else {
            $reasonText = implode(' | ', array_unique($problems)) ?: 'Order on hold, awaiting admin stock dispatch';
            $order->update([
                'status' => OrderStatus::NeedsAttention,
                'admin_note' => $reasonText,
            ]);

            $adminOrderUrl = route('filament.admin.resources.orders.view', ['record' => $order->number]);
            $quickControlUrl = route('webhooks.order.quick', ['order' => $order->number, 'do' => 'approve']);

            $notifyHold = function () use ($order, $items, $reasonText, $adminOrderUrl, $quickControlUrl, $problems): void {
                $this->telegram->notifyAdmin(
                    "⏸️⚠️ <b>PAYMENT CONFIRMED: DELIVERY PENDING / FAILED!</b>\n".
                    "━━━━━━━━━━━━━━━━━━\n".
                    "✅ <b>Payment Status:</b> CONFIRMED (Rs {$order->total})\n".
                    "🧾 <b>Order:</b> <code>{$order->number}</code>\n".
                    $items->map(fn ($i) => '📦 '.T::e($i->product_name)." ({$i->delivered_qty}/{$i->quantity} delivered)")->implode("\n")."\n".
                    '⚠️ <b>Reason:</b> '.T::e($reasonText)."\n".
                    '👤 <b>Customer:</b> '.T::e($order->email).($order->phone ? ' (+'.T::e($order->phone).')' : '')."\n".
                    "🔗 <a href=\"{$adminOrderUrl}\">Open Order in Admin Panel</a> · <a href=\"{$quickControlUrl}\">1-Tap Manual Deliver</a>"
                );
                app(WhatsAppNotifier::class)->notifyOrderOnHoldLowBalance($order, $problems);
            };

            if (app()->runningUnitTests()) {
                $notifyHold();
            } else {
                defer($notifyHold);
            }
        }

        $this->inventory->refreshMany($items->pluck('product')->filter());

        return $order->refresh();
    }

    /**
     * Admin manual delivery: accepts an array of credential units (supports multi-line units separated by `---` or single lines).
     *
     * @param  string[]|string  $lines
     */
    public function deliverManually(OrderItem $item, array|string $lines, string $separatorMode = 'auto'): Order
    {
        if (is_string($lines)) {
            $units = Product::parseStockText($lines, $separatorMode);
        } else {
            // If a single string element contains `---` separator, parse it with parseStockText
            if (count($lines) === 1 && is_string($lines[0] ?? null)) {
                $units = Product::parseStockText($lines[0], $separatorMode);
            } else {
                $units = array_values(array_filter(array_map(fn ($l): string => trim((string) $l), $lines), fn (string $l): bool => $l !== ''));
            }
        }

        if ($units === []) {
            throw new StoreException('Please provide valid non-empty credentials before delivering.');
        }

        DB::transaction(function () use ($item, $units) {
            $item->refresh();
            foreach (array_slice($units, 0, max(1, $item->remainingQty())) as $unitContent) {
                $item->deliveries()->create(['content' => $unitContent, 'source' => 'manual']);
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

    /**
     * Marks an order as delivered directly via WhatsApp handover (when Admin sends the product to the customer on WhatsApp).
     */
    public function markDeliveredViaWhatsApp(Order $order, ?string $note = null): Order
    {
        $text = 'Delivered to customer on WhatsApp'.(filled($note) ? ': '.trim($note) : '');

        DB::transaction(function () use ($order, $text) {
            foreach ($order->items()->get() as $item) {
                while ($item->remainingQty() > 0) {
                    $item->deliveries()->create([
                        'content' => $text,
                        'source' => 'whatsapp',
                    ]);
                    $item->delivered_qty++;
                }
                $item->reserved_supplier_qty = 0;
                $item->status = 'delivered';
                $item->save();
            }
        });

        $order->refresh();
        if ($order->status !== OrderStatus::Completed) {
            $order->update(['status' => OrderStatus::Processing]);
        }

        return $this->fulfill($order);
    }
}
