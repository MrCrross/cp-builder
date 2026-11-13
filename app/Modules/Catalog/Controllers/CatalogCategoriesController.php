<?php

namespace App\Modules\Catalog\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Actions\CreateCategoryAction;
use App\Modules\Catalog\Actions\DeleteCategoryAction;
use App\Modules\Catalog\Actions\UpdateCategoryAction;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Catalog\Requests\StoreCatalogCategoryRequest;
use App\Modules\Catalog\Requests\UpdateCatalogCategoryRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class CatalogCategoriesController extends Controller
{
    public function store(StoreCatalogCategoryRequest $request, CreateCategoryAction $action): RedirectResponse
    {
        /** @var array{name: string} $validated */
        $validated = $request->validated();

        $action->execute($request->user(), $validated);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Категория создана.')]);

        return back();
    }

    public function update(UpdateCatalogCategoryRequest $request, CatalogCategory $category, UpdateCategoryAction $action): RedirectResponse
    {
        /** @var array{name: string} $validated */
        $validated = $request->validated();

        $action->execute($request->user(), $category, $validated);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Категория обновлена.')]);

        return back();
    }

    public function destroy(Request $request, CatalogCategory $category, DeleteCategoryAction $action): RedirectResponse
    {
        Gate::authorize('delete', $category);

        $action->execute($request->user(), $category);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Категория удалена.')]);

        return back();
    }
}
