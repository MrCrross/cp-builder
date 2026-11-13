<?php

namespace App\Modules\Catalog\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Actions\CreateCatalogItemAction;
use App\Modules\Catalog\Actions\DeleteCatalogItemAction;
use App\Modules\Catalog\Actions\UpdateCatalogItemAction;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Catalog\Models\CatalogItem;
use App\Modules\Catalog\Requests\StoreCatalogItemRequest;
use App\Modules\Catalog\Requests\UpdateCatalogItemRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class CatalogItemsController extends Controller
{
    /**
     * Список позиций: поиск по названию, фильтр по категории и активности,
     * пагинация (план §11.4: поиск/пагинация справочника).
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', CatalogItem::class);

        $search = $request->query('search');
        $categoryId = $request->query('category_id');
        $active = $request->query('active');

        $query = CatalogItem::query()
            ->with('category:id,name')
            ->orderBy('name');

        if (is_string($search) && $search !== '') {
            $query->where('name', 'like', "%{$search}%");
        }

        if (is_numeric($categoryId)) {
            $query->where('category_id', (int) $categoryId);
        }

        if ($active === '0' || $active === '1') {
            $query->where('active', $active === '1');
        }

        $items = $query
            ->paginate(20)
            ->withQueryString()
            ->through(fn (CatalogItem $item): array => [
                'id' => $item->id,
                'name' => $item->name,
                'description' => $item->description,
                'unit' => $item->unit,
                'price' => $item->price,
                'active' => $item->active,
                'category' => $item->category ? ['id' => $item->category->id, 'name' => $item->category->name] : null,
                'properties' => $item->properties ?? [],
                'image' => $item->image,
                'source_url' => $item->source_url,
            ]);

        return Inertia::render('catalog/CatalogPage', [
            'categories' => CatalogCategory::query()
                ->withCount('items')
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (CatalogCategory $category): array => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'items_count' => $category->items_count,
                ])
                ->values(),
            'items' => $items,
            'filters' => [
                'search' => $request->query('search'),
                'category_id' => $request->query('category_id'),
                'active' => $request->query('active'),
            ],
        ]);
    }

    /**
     * JSON-поиск активных позиций для picker'а редактора КП.
     * Только чтение; выбор позиции копирует её значения в draft.
     */
    public function search(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', CatalogItem::class);

        $q = (string) $request->query('q', '');

        $items = CatalogItem::query()
            ->with('category:id,name')
            ->where('active', true)
            ->when($q !== '', fn ($query) => $query->where('name', 'like', "%{$q}%"))
            ->orderBy('name')
            ->limit(20)
            ->get()
            ->map(fn (CatalogItem $item): array => [
                'id' => $item->id,
                'name' => $item->name,
                'description' => $item->description,
                'unit' => $item->unit,
                'price' => $item->price,
                'category' => $item->category?->name,
            ]);

        return response()->json(['items' => $items]);
    }

    /**
     * Отдача картинок импорта из storage (план §13.1): только пути вида
     * imports/<source>/<sha256>.<ext>, авторизованным пользователям каталога.
     */
    public function image(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        Gate::authorize('viewAny', CatalogItem::class);

        $path = (string) $request->query('path', '');

        abort_unless(preg_match('#^imports/[a-z0-9]+/[a-f0-9]{64}\.[a-z0-9]+$#i', $path) === 1, 404);
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path);
    }

    public function store(StoreCatalogItemRequest $request, CreateCatalogItemAction $action): RedirectResponse
    {
        /** @var array{name: string, category_id?: ?int, description?: ?string, unit?: ?string, price: string, active?: bool} $validated */
        $validated = $request->validated();

        $action->execute($request->user(), $validated);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Позиция создана.')]);

        return back();
    }

    public function update(UpdateCatalogItemRequest $request, CatalogItem $item, UpdateCatalogItemAction $action): RedirectResponse
    {
        /** @var array{name: string, category_id?: ?int, description?: ?string, unit?: ?string, price: string, active?: bool} $validated */
        $validated = $request->validated();

        $action->execute($request->user(), $item, $validated);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Позиция обновлена.')]);

        return back();
    }

    public function destroy(Request $request, CatalogItem $item, DeleteCatalogItemAction $action): RedirectResponse
    {
        Gate::authorize('delete', $item);

        $action->execute($request->user(), $item);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Позиция удалена.')]);

        return back();
    }
}
