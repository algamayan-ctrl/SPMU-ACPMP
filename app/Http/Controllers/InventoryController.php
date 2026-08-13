<?php

namespace App\Http\Controllers;

use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\UnitOfMeasure;
use App\Services\AuditService;
use App\Services\InventoryService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class InventoryController extends Controller
{
    public function index(Request $request, InventoryService $inventory): View
    {
        $from = Carbon::parse($request->input('from', now()->addDay()->format('Y-m-d').' 08:00'));
        $to = Carbon::parse($request->input('to', now()->addDays(7)->format('Y-m-d').' 17:00'));
        if ($to->lte($from)) {
            $to = $from->copy()->addDay();
        }
        $items = InventoryItem::query()->with(['category', 'unit'])->where('active', true)->orderBy('unique_description')->get();
        $balances = $items->mapWithKeys(fn (InventoryItem $item) => [$item->id => $inventory->availability($item, $from, $to)]);

        return view('inventory.index', compact('items', 'balances', 'from', 'to'));
    }

    public function create(): View
    {
        return view('inventory.form', ['item' => new InventoryItem, 'categories' => InventoryCategory::where('active', true)->get(), 'units' => UnitOfMeasure::where('active', true)->get()]);
    }

    public function availabilityData(Request $request, InventoryService $inventory): JsonResponse
    {
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after:from']]);
        $from = Carbon::parse($data['from']);
        $to = Carbon::parse($data['to']);
        $items = InventoryItem::query()->where('active', true)->where('borrowable', true)->get();

        return response()->json($items->mapWithKeys(fn (InventoryItem $item) => [$item->id => $inventory->availability($item, $from, $to)]));
    }

    public function store(Request $request, AuditService $audit): RedirectResponse
    {
        $data = $this->validated($request);
        $item = InventoryItem::query()->create($data);
        $audit->record('INVENTORY_ITEM_CREATED', $item, reason: $request->input('change_reason'), after: $item->toArray());

        return redirect()->route('inventory.index')->with('status', 'Inventory item created.');
    }

    public function edit(InventoryItem $inventory): View
    {
        return view('inventory.form', ['item' => $inventory, 'categories' => InventoryCategory::where('active', true)->get(), 'units' => UnitOfMeasure::where('active', true)->get()]);
    }

    public function update(Request $request, InventoryItem $inventory, InventoryService $service, AuditService $audit): RedirectResponse
    {
        $data = $this->validated($request, $inventory);
        $balance = $service->availability($inventory, now()->subYears(10), now()->addYears(10));
        $committed = $balance['allocated'] + $balance['borrowed'] + $balance['laundry'] + $balance['incident'];
        if ((float) $data['total_quantity'] < $committed) {
            throw ValidationException::withMessages(['total_quantity' => "Total quantity cannot be reduced below the active commitment of {$committed}."]);
        }
        $before = $inventory->toArray();
        $inventory->update($data);
        $audit->record('INVENTORY_ITEM_UPDATED', $inventory, reason: $request->input('change_reason'), before: $before, after: $inventory->fresh()->toArray());

        return redirect()->route('inventory.index')->with('status', 'Inventory item updated with an audit record.');
    }

    private function validated(Request $request, ?InventoryItem $item = null): array
    {
        $data = $request->validate([
            'category_id' => ['required', 'exists:inventory_categories,id'],
            'unit_id' => ['required', 'exists:units_of_measure,id'],
            'unique_description' => ['required', 'string', 'max:255', Rule::unique('inventory_items')->where(fn ($query) => $query->where('category_id', $request->integer('category_id')))->ignore($item?->id)],
            'specification' => ['nullable', 'string'],
            'total_quantity' => ['required', 'numeric', 'min:0'],
            'condition_code' => ['required', Rule::in(['SERVICEABLE', 'DAMAGED_MAINTENANCE', 'CONDEMNED'])],
            'change_reason' => ['required', 'string', 'max:1000'],
        ]);
        $data['borrowable'] = $request->boolean('borrowable');
        $data['off_campus_allowed'] = $request->boolean('off_campus_allowed');
        $data['laundry_required'] = $request->boolean('laundry_required');
        $data['provisional'] = $request->boolean('provisional');
        $data['active'] = $request->boolean('active', true);
        unset($data['change_reason']);

        if ($data['off_campus_allowed'] && strcasecmp($data['unique_description'], 'Barricade') !== 0) {
            throw ValidationException::withMessages(['off_campus_allowed' => 'Current policy permits off-campus use only for Barricade.']);
        }

        return $data;
    }
}
