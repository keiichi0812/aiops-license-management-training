<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\License;
use App\Models\Product;
use App\Models\User;
use App\Services\LicenseQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class LicenseController extends Controller
{
    public function index(Request $request, LicenseQuery $licenses): Response|JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'], 'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'user_id' => ['nullable', 'integer'], 'status' => ['nullable', Rule::in(['before', 'active', 'expired', 'stopped'])],
            'ending_from' => ['nullable', 'date_format:Y-m-d'], 'ending_to' => ['nullable', 'date_format:Y-m-d'],
            'expiring' => ['nullable', 'boolean'],
        ]);
        $query = License::visibleTo($request->user())->with(['customer', 'product', 'user']);
        if ($request->filled('q')) {
            $term = '%'.$request->string('q')->toString().'%';
            $query->where(fn ($q) => $q->where('number', 'like', $term)->orWhereHas('customer', fn ($q) => $q->where('name', 'like', $term)));
        }
        foreach (['product_id', 'user_id'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->integer($field));
            }
        }
        if ($request->filled('status')) {
            $query->status($request->string('status')->toString());
        }
        if ($request->filled('ending_from')) {
            $query->whereDate('ends_on', '>=', $request->input('ending_from'));
        }
        if ($request->filled('ending_to')) {
            $query->whereDate('ends_on', '<=', $request->input('ending_to'));
        }
        if ($request->boolean('expiring')) {
            $licenses->expiring($query);
        }
        $summary = (clone $query)->get()->groupBy(fn (License $license): string => $license->product->name.' / '.$license->user->name)
            ->map(fn ($items, string $label): array => ['label' => $label, 'count' => $items->count(), 'quantity' => $items->sum('quantity')])->values();
        $props = [
            'title' => 'ライセンス管理', 'baseUrl' => route('licenses.index'), 'fields' => config('training.fields'),
            'columns' => config('training.columns'), 'filters' => $filters, 'canManage' => true,
            'records' => $query->orderBy('ends_on')->orderBy('id')->paginate(30)->withQueryString()->through(fn (License $license): array => $this->row($license)),
            'options' => ['customer_id' => Customer::orderBy('name')->get(['id', 'name']), 'product_id' => Product::orderBy('name')->get(['id', 'name']),
                'user_id' => User::when(! $request->user()->isAdmin(), fn ($q) => $q->whereKey($request->user()->id))->get(['id', 'name'])],
            'summary' => $summary, 'topic' => 'license', 'today' => today()->toDateString(),
        ];

        return $request->expectsJson() ? response()->json($props) : Inertia::render('training/index', $props);
    }

    /** @return array<string, mixed> */
    private function row(License $license): array
    {
        return [...$license->only(['id', 'number', 'customer_id', 'product_id', 'user_id', 'quantity', 'stopped', 'notes']),
            'starts_on' => $license->starts_on->toDateString(), 'ends_on' => $license->ends_on->toDateString(),
            'customer' => $license->customer->name, 'product' => $license->product->name, 'owner' => $license->user->name, 'status' => $license->statusLabel()];
    }

    public function show(Request $request, int $id): Response|JsonResponse
    {
        $license = License::visibleTo($request->user())->with(['customer', 'product', 'user'])->findOrFail($id);
        $props = ['title' => $license->number, 'record' => $this->row($license), 'backUrl' => route('licenses.index')];

        return $request->expectsJson() ? response()->json($props) : Inertia::render('training/show', $props);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['user_id'] = $request->user()->isAdmin() ? ($data['user_id'] ?? $request->user()->id) : $request->user()->id;
        $data['number'] = 'LIC-'.Str::uuid()->toString();
        License::create($data);

        return to_route('licenses.index');
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $license = License::visibleTo($request->user())->findOrFail($id);
        $data = $this->validated($request);
        if (! $request->user()->isAdmin()) {
            unset($data['user_id']);
        }
        $license->update($data);

        return to_route('licenses.index');
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        License::visibleTo($request->user())->findOrFail($id)->delete();

        return to_route('licenses.index');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'], 'product_id' => ['required', 'integer', 'exists:products,id'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'], 'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'starts_on' => ['required', 'date_format:Y-m-d'], 'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'stopped' => ['required', 'boolean'], 'notes' => ['nullable', 'string', 'max:2000'],
        ], ['ends_on.after_or_equal' => '終了日は開始日以降にしてください。', 'quantity.min' => '契約数は1以上にしてください。']);
    }
}
