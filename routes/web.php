<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\UsersController;
use App\Modules\Catalog\Http\Controllers\CatalogCategoriesController;
use App\Modules\Catalog\Http\Controllers\CatalogItemsController;
use App\Modules\CommercialProposals\Http\Controllers\ProposalsController;
use App\Modules\CommercialProposals\Http\Controllers\ProposalTemplatesController;
use App\Modules\CommercialProposals\Http\Controllers\PublicProposalController;
use App\Modules\CompanySettings\Http\Controllers\BrandingAssetController;
use App\Modules\CompanySettings\Http\Controllers\OrganizationSettingsController;
use App\Modules\Imports\Http\Controllers\ImportsController;
use App\Modules\Projects\Http\Controllers\ProjectsController;
use App\Modules\Statistics\Http\Controllers\StatisticsController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

// Публичный документ: только issued и не archived (§9.3). Новые token —
// base64url ≥20 символов; всё остальное — legacy slugs через aliases.
Route::get('kp/{token}', [PublicProposalController::class, 'show'])
    ->where('token', '[A-Za-z0-9_-]{20,}')
    ->name('kp.show');
Route::get('kp/{token}/logo/{kind}', [PublicProposalController::class, 'logo'])
    ->where('token', '[A-Za-z0-9_-]{20,}')
    ->name('kp.logo');
Route::get('kp/{slug}', [PublicProposalController::class, 'showBySlug'])->name('kp.slug');

Route::middleware(['auth'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    // Коммерческие предложения (план §11.2): реестр, редактор, жизненный цикл.
    Route::get('proposals', [ProposalsController::class, 'index'])->name('proposals.index');
    Route::get('proposals/create', [ProposalsController::class, 'create'])->name('proposals.create');
    Route::post('proposals', [ProposalsController::class, 'store'])->name('proposals.store');
    Route::get('proposals/{proposal}', [ProposalsController::class, 'edit'])->name('proposals.edit');
    Route::get('proposals/{proposal}/preview', [ProposalsController::class, 'preview'])->name('proposals.preview');
    Route::put('proposals/{proposal}/draft', [ProposalsController::class, 'update'])->name('proposals.update');
    Route::get('proposals/{proposal}/catalog-diff', [ProposalsController::class, 'catalogDiff'])->name('proposals.catalog-diff');
    Route::post('proposals/{proposal}/refresh-catalog', [ProposalsController::class, 'refreshCatalog'])->name('proposals.refresh-catalog');
    Route::post('proposals/{proposal}/issue', [ProposalsController::class, 'issue'])
        ->middleware('throttle:proposals.publish')
        ->name('proposals.issue');
    Route::post('proposals/{proposal}/new-draft', [ProposalsController::class, 'newDraft'])->name('proposals.new-draft');
    Route::post('proposals/{proposal}/archive', [ProposalsController::class, 'archive'])->name('proposals.archive');
    Route::post('proposals/{proposal}/restore', [ProposalsController::class, 'restore'])->name('proposals.restore');
    Route::post('proposals/{proposal}/rotate-link', [ProposalsController::class, 'rotateLink'])
        ->middleware('throttle:proposals.publish')
        ->name('proposals.rotate-link');
    Route::delete('proposals/{proposal}', [ProposalsController::class, 'destroy'])->name('proposals.destroy');

    // Шаблоны КП.
    Route::get('templates', [ProposalTemplatesController::class, 'index'])->name('templates.index');
    Route::post('templates', [ProposalTemplatesController::class, 'store'])->name('templates.store');
    Route::put('templates/{template}', [ProposalTemplatesController::class, 'update'])->name('templates.update');
    Route::delete('templates/{template}', [ProposalTemplatesController::class, 'destroy'])->name('templates.destroy');

    // Проекты — группировка КП (план §8.2).
    Route::get('projects', [ProjectsController::class, 'index'])->name('projects.index');
    Route::post('projects', [ProjectsController::class, 'store'])->name('projects.store');
    Route::get('projects/{project}', [ProjectsController::class, 'show'])->name('projects.show');
    Route::put('projects/{project}', [ProjectsController::class, 'update'])->name('projects.update');
    Route::delete('projects/{project}', [ProjectsController::class, 'destroy'])->name('projects.destroy');

    // Номенклатура: плоские категории и позиции (план §8.2).
    Route::get('catalog', [CatalogItemsController::class, 'index'])->name('catalog.index');
    Route::get('catalog/items/search', [CatalogItemsController::class, 'search'])->name('catalog.items.search');
    Route::get('catalog/items/image', [CatalogItemsController::class, 'image'])->name('catalog.items.image');
    Route::post('catalog/categories', [CatalogCategoriesController::class, 'store'])->name('catalog.categories.store');
    Route::put('catalog/categories/{category}', [CatalogCategoriesController::class, 'update'])->name('catalog.categories.update');
    Route::delete('catalog/categories/{category}', [CatalogCategoriesController::class, 'destroy'])->name('catalog.categories.destroy');
    Route::post('catalog/items', [CatalogItemsController::class, 'store'])->name('catalog.items.store');
    Route::put('catalog/items/{item}', [CatalogItemsController::class, 'update'])->name('catalog.items.update');
    Route::delete('catalog/items/{item}', [CatalogItemsController::class, 'destroy'])->name('catalog.items.destroy');

    // Статистика просмотров КП (план §13.5): administrator/manager, viewer — нет.
    Route::get('statistics', [StatisticsController::class, 'index'])->name('statistics.index');

    // Импорты номенклатуры (план §13.1): permission imports.run.
    Route::get('imports', [ImportsController::class, 'index'])->name('imports.index');
    Route::post('imports', [ImportsController::class, 'store'])->name('imports.store');
    Route::get('imports/{job}', [ImportsController::class, 'show'])->name('imports.show');
    Route::post('imports/{job}/confirm', [ImportsController::class, 'confirm'])->name('imports.confirm');
    Route::post('imports/{job}/cancel', [ImportsController::class, 'cancel'])->name('imports.cancel');
    Route::get('imports/{job}/errors.csv', [ImportsController::class, 'errors'])->name('imports.errors');

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::get('users', [UsersController::class, 'index'])->name('users.index');
        Route::post('users', [UsersController::class, 'store'])->name('users.store');
        Route::put('users/{user}', [UsersController::class, 'update'])->name('users.update');
        Route::post('users/{user}/deactivate', [UsersController::class, 'deactivate'])->name('users.deactivate');
        Route::post('users/{user}/activate', [UsersController::class, 'activate'])->name('users.activate');
        Route::post('users/{user}/resend-invite', [UsersController::class, 'resendInvite'])->name('users.resend-invite');

        Route::get('audit', [AuditLogController::class, 'index'])->name('audit.index');
    });
});

// Настройки организации и брендинг — только administrator (Policy §9.2).
Route::middleware(['auth'])->prefix('settings')->name('settings.')->group(function () {
    Route::get('branding', [OrganizationSettingsController::class, 'edit'])->name('branding.edit');
    Route::put('branding', [OrganizationSettingsController::class, 'update'])->name('branding.update');
    Route::get('branding/logos/{kind}', [BrandingAssetController::class, 'show'])->name('branding.logo');
    Route::post('branding/logos/{kind}', [BrandingAssetController::class, 'store'])
        ->middleware('throttle:branding.upload')
        ->name('branding.logo.store');
    Route::delete('branding/logos/{kind}', [BrandingAssetController::class, 'destroy'])->name('branding.logo.destroy');
});

require __DIR__.'/settings.php';
