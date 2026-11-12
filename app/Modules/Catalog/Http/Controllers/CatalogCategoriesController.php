<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Application\Actions\CreateCategoryAction;
use App\Modules\Catalog\Application\Actions\DeleteCategoryAction;
use App\Modules\Catalog\Application\Actions\UpdateCategoryAction;
use App\Modules\Catalog\Http\Requests\StoreCatalogCategoryRequest;
use App\Modules\Catalog\Http\Requests\UpdateCatalogCategoryRequest;
use App\Modules\Catalog\Infrastructure\Persistence\CatalogCategory;
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
