<?php

namespace App\Modules\Imports\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Imports\Application\Actions\CancelImportAction;
use App\Modules\Imports\Application\Actions\ConfirmImportAction;
use App\Modules\Imports\Application\Actions\UploadImportFilesAction;
use App\Modules\Imports\Domain\ImportProfile;
use App\Modules\Imports\Domain\ImportRowAction;
use App\Modules\Imports\Http\Requests\UploadImportRequest;
use App\Modules\Imports\Infrastructure\Persistence\ImportJob;
use App\Modules\Imports\Infrastructure\Persistence\ImportRow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Импорты номенклатуры (план §13.1): реестр job'ов, upload, preview
 * staging-строк с фильтром по action, подтверждение apply, отмена,
 * скачиваемый error report. Контроллер тонкий — вся логика в Actions.
 */
class ImportsController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', ImportJob::class);

        $jobs = ImportJob::query()
            ->with('user:id,name')
            ->latest('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (ImportJob $job): array => $this->jobSummary($job));

        return Inertia::render('imports/ImportsPage', [
            'jobs' => $jobs,
            'profiles' => collect(ImportProfile::cases())
                ->map(fn (ImportProfile $profile): array => [
                    'value' => $profile->value,
                    'label' => $profile->label(),
                ])
                ->values(),
        ]);
    }

    public function store(UploadImportRequest $request, UploadImportFilesAction $action): RedirectResponse
    {
        /** @var array{profile: string} $validated */
        $validated = $request->validated();

        $job = $action->execute(
            $request->user(),
            ImportProfile::from($validated['profile']),
            $request->file('files', []),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Файл принят в обработку. Импорт появится в списке.')]);

        return redirect()->route('imports.show', $job);
    }

    public function show(Request $request, ImportJob $job): Response
    {
        Gate::authorize('view', $job);

        $action = $request->query('action');
        $actionFilter = is_string($action) && ImportRowAction::tryFrom($action) !== null
            ? ImportRowAction::from($action)
            : null;

        $rowsQuery = ImportRow::query()
            ->where('job_id', $job->id)
            ->when($actionFilter !== null, fn ($query) => $query->where('action', $actionFilter->value))
            ->orderBy('external_id');

        $errorsOnly = $request->query('errors') === '1';

        if ($errorsOnly) {
            $rowsQuery->whereNotNull('errors');
        }

        return Inertia::render('imports/ImportShowPage', [
            'job' => $this->jobDetail($job),
            'rows' => $rowsQuery
                ->paginate(50)
                ->withQueryString()
                ->through(fn (ImportRow $row): array => $this->rowData($row)),
            'filters' => [
                'action' => $actionFilter?->value,
                'errors' => $errorsOnly ? '1' : null,
            ],
        ]);
    }

    public function confirm(Request $request, ImportJob $job, ConfirmImportAction $action): RedirectResponse
    {
        Gate::authorize('confirm', $job);

        $action->execute($request->user(), $job);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Импорт применяется в фоне.')]);

        return back();
    }

    public function cancel(Request $request, ImportJob $job, CancelImportAction $action): RedirectResponse
    {
        Gate::authorize('cancel', $job);

        $action->execute($request->user(), $job);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Импорт отменён.')]);

        return back();
    }

    /**
     * Скачиваемый error report (план §13.1 п.5): только строки с ошибками.
     */
    public function errors(ImportJob $job): StreamedResponse
    {
        Gate::authorize('view', $job);

        $callback = function () use ($job): void {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            fputcsv($out, ['Внешний ID', 'Ошибки'], ';');

            ImportRow::query()
                ->where('job_id', $job->id)
                ->whereNotNull('errors')
                ->orderBy('external_id')
                ->chunkById(1000, function ($rows) use ($out): void {
                    foreach ($rows as $row) {
                        /** @var ImportRow $row */
                        fputcsv($out, [$row->external_id, implode(' ', $row->errors ?? [])], ';');
                    }
                });

            fclose($out);
        };

        return response()->streamDownload($callback, "import-{$job->id}-errors.csv", [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function jobSummary(ImportJob $job): array
    {
        return [
            'id' => $job->id,
            'profile' => $job->profile,
            'status' => $job->status,
            'error_message' => $job->error_message,
            'rows_total' => $job->rows_total,
            'rows_add' => $job->rows_add,
            'rows_change' => $job->rows_change,
            'rows_skip' => $job->rows_skip,
            'rows_delete' => $job->rows_delete,
            'rows_error' => $job->rows_error,
            'user' => $job->user ? ['id' => $job->user->id, 'name' => $job->user->name] : null,
            'created_at' => $job->created_at->toISOString(),
            'applied_at' => $job->applied_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function jobDetail(ImportJob $job): array
    {
        return $this->jobSummary($job) + [
            'rows_processed' => $job->rows_processed,
            'applied_created' => $job->applied_created,
            'applied_updated' => $job->applied_updated,
            'applied_deactivated' => $job->applied_deactivated,
            'files' => $job->files()
                ->get(['id', 'original_name', 'size_bytes', 'detected_kind', 'parsed_count', 'error'])
                ->map(fn ($file): array => [
                    'id' => $file->id,
                    'original_name' => $file->original_name,
                    'size_bytes' => $file->size_bytes,
                    'detected_kind' => $file->detected_kind,
                    'parsed_count' => $file->parsed_count,
                    'error' => $file->error,
                ])
                ->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rowData(ImportRow $row): array
    {
        $payload = $row->payload;

        return [
            'id' => $row->id,
            'external_id' => $row->external_id,
            'action' => $row->action,
            'name' => $payload['name'] ?? null,
            'article' => $payload['article'] ?? null,
            'properties' => $payload['properties'] ?? [],
            'image' => $payload['image'] ?? null,
            'source_url' => $payload['source_url'] ?? null,
            'unit' => $payload['unit'] ?? null,
            'price' => $payload['price'] ?? null,
            'currency' => $payload['currency'] ?? null,
            'delete' => (bool) ($payload['delete'] ?? false),
            'errors' => $row->errors,
        ];
    }
}
