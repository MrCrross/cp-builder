<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditEntry;
use App\Support\Audit\Audit;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    use AuthorizesRequests;

    /**
     * Read API журнала аудита (план §9.6): только administrator,
     * фильтры по действию и автору, пагинация.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', AuditEntry::class);

        $action = $request->string('action')->toString();
        $actorId = $request->integer('actor_id') ?: null;

        $entries = AuditEntry::query()
            ->with('actor:id,name,email')
            ->when(in_array($action, Audit::actions(), true), function ($query) use ($action) {
                $query->where('action', $action);
            })
            ->when($actorId !== null, function ($query) use ($actorId) {
                $query->where('actor_id', $actorId);
            })
            ->latest('created_at')
            ->latest('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (AuditEntry $entry) => [
                'id' => $entry->id,
                'action' => $entry->action,
                'actor' => $entry->actor?->only('id', 'name', 'email'),
                'subject_type' => $entry->subject_type !== null
                    ? class_basename($entry->subject_type)
                    : null,
                'subject_id' => $entry->subject_id,
                'request_id' => $entry->request_id,
                'before' => $entry->before,
                'after' => $entry->after,
                'created_at' => $entry->created_at,
            ]);

        return Inertia::render('admin-audit/AuditLogPage', [
            'entries' => $entries,
            'filters' => [
                'action' => in_array($action, Audit::actions(), true) ? $action : null,
                'actor_id' => $actorId,
            ],
            'actions' => Audit::actions(),
        ]);
    }
}
