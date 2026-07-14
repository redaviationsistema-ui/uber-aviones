<?php

namespace App\Http\Controladores\RedAviation;

use App\Http\Controladores\ControladorBase;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminInventoryControlador extends ControladorBase
{
    private const TABLES = [
        'aircraft_fleet',
        'airports_geo',
        'aeropuertos_mexico',
        'aviation_parts',
        'bulk_email_campaigns',
        'bulk_email_deliveries',
        'bulk_email_recipients',
        'bulk_email_unsubscribes',
        'company_address',
        'customers',
        'flight_quote_legs',
        'flight_quotes',
        'lookbooks',
        'pilatus_pc12_qualification_forms',
        'quote_routes',
        'quotes',
        'responsables',
        'ventas',
    ];

    private const WRITE_PROTECTED_COLUMNS = [
        'id',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    public function authMe(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $request->user(),
            'session' => [
                'access_token' => (string) $request->bearerToken(),
                'user' => $request->user(),
            ],
        ]);
    }

    public function query(Request $request)
    {
        $payload = $request->validate([
            'table' => ['required', 'string'],
            'columns' => ['nullable'],
            'filters' => ['nullable', 'array'],
            'or_filters' => ['nullable', 'array'],
            'orders' => ['nullable', 'array'],
            'range' => ['nullable', 'array'],
            'range.from' => ['nullable', 'integer', 'min:0'],
            'range.to' => ['nullable', 'integer', 'min:0'],
            'count' => ['nullable', 'string'],
            'head' => ['nullable', 'boolean'],
            'single' => ['nullable', 'boolean'],
            'maybe_single' => ['nullable', 'boolean'],
        ]);

        $table = $this->resolveAllowedTable($payload['table']);
        $columns = $this->resolveSelectedColumns($table, $payload['columns'] ?? '*');
        $query = DB::table($table)->select($columns);

        $this->applyFilters($query, $table, $payload['filters'] ?? []);
        $this->applyOrFilters($query, $table, $payload['or_filters'] ?? []);
        $this->applyOrders($query, $table, $payload['orders'] ?? []);

        $count = null;
        if (($payload['count'] ?? null) === 'exact') {
            $countQuery = clone $query;
            $count = $countQuery->count();
        }

        if (($payload['head'] ?? false) === true) {
            return response()->json([
                'data' => [],
                'count' => $count,
                'error' => null,
            ]);
        }

        if (isset($payload['range']['from'], $payload['range']['to'])) {
            $from = (int) $payload['range']['from'];
            $to = (int) $payload['range']['to'];
            $query->offset($from)->limit(max(0, $to - $from + 1));
        }

        $rows = $query->get();

        if (($payload['single'] ?? false) === true) {
            if ($rows->count() !== 1) {
                throw ValidationException::withMessages([
                    'table' => ['La consulta no devolvió exactamente un registro.'],
                ]);
            }

            return response()->json([
                'data' => (array) $rows->first(),
                'count' => $count,
                'error' => null,
            ]);
        }

        if (($payload['maybe_single'] ?? false) === true) {
            return response()->json([
                'data' => $rows->first() ? (array) $rows->first() : null,
                'count' => $count,
                'error' => null,
            ]);
        }

        return response()->json([
            'data' => $rows->map(fn ($row) => (array) $row)->values()->all(),
            'count' => $count,
            'error' => null,
        ]);
    }

    public function insert(Request $request)
    {
        $payload = $request->validate([
            'table' => ['required', 'string'],
            'rows' => ['required', 'array', 'min:1'],
            'returning' => ['nullable'],
            'single' => ['nullable', 'boolean'],
        ]);

        $table = $this->resolveAllowedTable($payload['table']);
        $rows = collect($payload['rows'])->map(function ($row) use ($table) {
            return $this->sanitizePayloadRow($table, is_array($row) ? $row : []);
        })->values();

        $inserted = [];

        DB::transaction(function () use ($table, $rows, &$inserted) {
            foreach ($rows as $row) {
                $id = DB::table($table)->insertGetId($row);
                $record = $this->fetchInsertedRecord($table, $id, $row);
                $inserted[] = $record;
            }
        });

        $this->writeAudit(
            $request,
            'admin_inventory_insert',
            'admin_inventory',
            'Inserción administrativa realizada vía Laravel.',
            [
                'entity' => $table,
                'entity_id' => collect($inserted)->pluck('id')->filter()->implode(','),
                'after' => ['rows' => $inserted],
            ],
        );

        return response()->json([
            'data' => ($payload['single'] ?? false) === true ? ($inserted[0] ?? null) : $inserted,
            'error' => null,
        ], 201);
    }

    public function update(Request $request)
    {
        $payload = $request->validate([
            'table' => ['required', 'string'],
            'values' => ['required', 'array'],
            'filters' => ['nullable', 'array'],
            'returning' => ['nullable'],
            'single' => ['nullable', 'boolean'],
        ]);

        $table = $this->resolveAllowedTable($payload['table']);
        $values = $this->sanitizePayloadRow($table, $payload['values']);
        $query = DB::table($table);
        $this->applyFilters($query, $table, $payload['filters'] ?? []);
        $before = $query->get()->map(fn ($row) => (array) $row)->values()->all();

        if (! count($before)) {
            return response()->json([
                'data' => ($payload['single'] ?? false) === true ? null : [],
                'error' => null,
            ]);
        }

        $query->update($values);

        $afterQuery = DB::table($table);
        $this->applyFilters($afterQuery, $table, $payload['filters'] ?? []);
        $after = $afterQuery->get()->map(fn ($row) => (array) $row)->values()->all();

        $this->writeAudit(
            $request,
            'admin_inventory_update',
            'admin_inventory',
            'Actualización administrativa realizada vía Laravel.',
            [
                'entity' => $table,
                'entity_id' => collect($after)->pluck('id')->filter()->implode(','),
                'before' => ['rows' => $before],
                'after' => ['rows' => $after],
            ],
        );

        return response()->json([
            'data' => ($payload['single'] ?? false) === true ? ($after[0] ?? null) : $after,
            'error' => null,
        ]);
    }

    public function delete(Request $request)
    {
        $payload = $request->validate([
            'table' => ['required', 'string'],
            'filters' => ['nullable', 'array'],
        ]);

        $table = $this->resolveAllowedTable($payload['table']);
        $query = DB::table($table);
        $this->applyFilters($query, $table, $payload['filters'] ?? []);
        $before = $query->get()->map(fn ($row) => (array) $row)->values()->all();

        if (count($before)) {
            $query->delete();
        }

        $this->writeAudit(
            $request,
            'admin_inventory_delete',
            'admin_inventory',
            'Eliminación administrativa realizada vía Laravel.',
            [
                'entity' => $table,
                'entity_id' => collect($before)->pluck('id')->filter()->implode(','),
                'before' => ['rows' => $before],
            ],
        );

        return response()->json([
            'data' => null,
            'error' => null,
        ]);
    }

    public function upsert(Request $request)
    {
        $payload = $request->validate([
            'table' => ['required', 'string'],
            'rows' => ['required', 'array', 'min:1'],
            'unique_by' => ['required', 'array', 'min:1'],
            'single' => ['nullable', 'boolean'],
        ]);

        $table = $this->resolveAllowedTable($payload['table']);
        $rows = collect($payload['rows'])->map(function ($row) use ($table) {
            return $this->sanitizePayloadRow($table, is_array($row) ? $row : []);
        })->values()->all();
        $uniqueBy = array_values(array_filter($payload['unique_by'], 'is_string'));
        $updateColumns = array_values(array_filter(array_keys($rows[0] ?? []), fn ($column) => ! in_array($column, $uniqueBy, true)));

        DB::table($table)->upsert($rows, $uniqueBy, $updateColumns);

        $resolved = collect($rows)->map(function (array $row) use ($table, $uniqueBy) {
            $query = DB::table($table);
            foreach ($uniqueBy as $column) {
                $query->where($column, $row[$column] ?? null);
            }

            return (array) ($query->first() ?? $row);
        })->values()->all();

        $this->writeAudit(
            $request,
            'admin_inventory_upsert',
            'admin_inventory',
            'Upsert administrativo realizado vía Laravel.',
            [
                'entity' => $table,
                'entity_id' => collect($resolved)->pluck('id')->filter()->implode(','),
                'after' => ['rows' => $resolved],
            ],
        );

        return response()->json([
            'data' => ($payload['single'] ?? false) === true ? ($resolved[0] ?? null) : $resolved,
            'error' => null,
        ]);
    }

    public function uploadStorage(Request $request)
    {
        $payload = $request->validate([
            'bucket' => ['required', 'string'],
            'path' => ['required', 'string'],
            'file' => ['required', 'file', 'max:10240'],
            'upsert' => ['nullable', 'boolean'],
        ]);

        $disk = $this->resolveStorageDisk();
        $path = $this->resolveBucketPath($payload['bucket'], $payload['path']);

        if (($payload['upsert'] ?? false) !== true && Storage::disk($disk)->exists($path)) {
            throw ValidationException::withMessages([
                'path' => ['El archivo ya existe en almacenamiento.'],
            ]);
        }

        Storage::disk($disk)->put($path, file_get_contents($payload['file']->getRealPath()));

        return response()->json([
            'data' => [
                'path' => $path,
            ],
            'error' => null,
        ], 201);
    }

    public function removeStorage(Request $request)
    {
        $payload = $request->validate([
            'bucket' => ['required', 'string'],
            'paths' => ['required', 'array', 'min:1'],
        ]);

        $disk = $this->resolveStorageDisk();
        $paths = collect($payload['paths'])
            ->filter(fn ($path) => is_string($path) && trim($path) !== '')
            ->map(fn (string $path) => $this->resolveBucketPath($payload['bucket'], $path))
            ->values()
            ->all();

        if ($paths) {
            Storage::disk($disk)->delete($paths);
        }

        return response()->json([
            'data' => null,
            'error' => null,
        ]);
    }

    public function storageUrl(Request $request)
    {
        $payload = $request->validate([
            'bucket' => ['required', 'string'],
            'path' => ['required', 'string'],
        ]);

        $disk = $this->resolveStorageDisk();
        $path = $this->resolveBucketPath($payload['bucket'], $payload['path']);

        return response()->json([
            'data' => [
                'signedUrl' => Storage::disk($disk)->url($path),
                'publicUrl' => Storage::disk($disk)->url($path),
            ],
            'error' => null,
        ]);
    }

    public function sendTestEmail(Request $request)
    {
        $payload = $request->validate([
            'email' => ['required', 'email'],
            'subject' => ['nullable', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
            'content' => ['nullable', 'string'],
            'button_text' => ['nullable', 'string', 'max:255'],
            'button_url' => ['nullable', 'string', 'max:2000'],
            'sender_name' => ['nullable', 'string', 'max:255'],
            'sender_email' => ['nullable', 'email'],
            'reply_to' => ['nullable', 'email'],
            'image_url' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->dispatchBulkEmail($payload['email'], $payload);

        return response()->json([
            'success' => true,
            'message' => 'Correo de prueba enviado correctamente.',
        ]);
    }

    public function sendCampaignEmail(Request $request)
    {
        $payload = $request->validate([
            'email' => ['required', 'email'],
            'name' => ['nullable', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'subject' => ['nullable', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
            'content' => ['nullable', 'string'],
            'button_text' => ['nullable', 'string', 'max:255'],
            'button_url' => ['nullable', 'string', 'max:2000'],
            'sender_name' => ['nullable', 'string', 'max:255'],
            'sender_email' => ['nullable', 'email'],
            'reply_to' => ['nullable', 'email'],
            'image_url' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->dispatchBulkEmail($payload['email'], $payload);

        return response()->json([
            'success' => true,
            'message_id' => (string) Str::uuid(),
        ]);
    }

    public function campaignAction(Request $request)
    {
        $payload = $request->validate([
            'campaign_id' => ['required'],
            'action' => ['required', 'string'],
        ]);

        $campaignId = $payload['campaign_id'];
        $action = Str::lower(trim($payload['action']));
        $status = match ($action) {
            'start_campaign', 'process_campaign', 'resume_campaign' => 'processing',
            'pause_campaign' => 'paused',
            'cancel_campaign' => 'cancelled',
            'get_progress' => null,
            default => throw ValidationException::withMessages([
                'action' => ['La acción de campaña no está soportada.'],
            ]),
        };

        if ($action === 'get_progress') {
            $campaign = DB::table('bulk_email_campaigns')->where('id', $campaignId)->first();

            return response()->json([
                'success' => true,
                'campaign_id' => $campaignId,
                'status' => $campaign?->status ?? 'draft',
                'sent_count' => (int) ($campaign?->sent_count ?? 0),
                'failed_count' => (int) ($campaign?->failed_count ?? 0),
                'total_recipients' => (int) ($campaign?->total_recipients ?? 0),
            ]);
        }

        DB::table('bulk_email_campaigns')
            ->where('id', $campaignId)
            ->update([
                'status' => $status,
                'updated_at' => now(),
                'started_at' => in_array($action, ['start_campaign', 'process_campaign', 'resume_campaign'], true) ? now() : DB::raw('started_at'),
            ]);

        return response()->json([
            'success' => true,
            'campaign_id' => $campaignId,
            'status' => $status,
        ]);
    }

    private function resolveAllowedTable(string $table): string
    {
        $normalized = trim($table);
        abort_unless(in_array($normalized, self::TABLES, true), 422, 'La tabla administrativa solicitada no está permitida.');

        return $normalized;
    }

    private function resolveSelectedColumns(string $table, mixed $columns): array|string
    {
        if ($columns === '*' || $columns === null || $columns === '') {
            return '*';
        }

        $availableColumns = Schema::getColumnListing($table);

        return collect(is_string($columns) ? explode(',', $columns) : (array) $columns)
            ->map(fn ($column) => trim((string) $column))
            ->filter(fn ($column) => $column !== '' && in_array($column, $availableColumns, true))
            ->values()
            ->all() ?: '*';
    }

    private function sanitizePayloadRow(string $table, array $row): array
    {
        $availableColumns = Schema::getColumnListing($table);

        return collect($row)
            ->reject(fn ($_value, $column) => in_array($column, self::WRITE_PROTECTED_COLUMNS, true))
            ->filter(fn ($_value, $column) => in_array((string) $column, $availableColumns, true))
            ->all();
    }

    private function applyFilters(Builder $query, string $table, array $filters): void
    {
        $availableColumns = Schema::getColumnListing($table);

        foreach ($filters as $filter) {
            $column = (string) ($filter['column'] ?? '');
            $operator = Str::lower((string) ($filter['operator'] ?? 'eq'));
            $value = $filter['value'] ?? null;

            if ($column === '' || ! in_array($column, $availableColumns, true)) {
                continue;
            }

            match ($operator) {
                'eq' => $query->where($column, '=', $value),
                'neq' => $query->where($column, '!=', $value),
                'gt' => $query->where($column, '>', $value),
                'gte' => $query->where($column, '>=', $value),
                'lt' => $query->where($column, '<', $value),
                'lte' => $query->where($column, '<=', $value),
                'like' => $query->where($column, 'like', (string) $value),
                'ilike' => $query->whereRaw('LOWER('.DB::getQueryGrammar()->wrap($column).') LIKE ?', ['%'.Str::lower(trim((string) $value, '%')).'%']),
                'in' => $query->whereIn($column, is_array($value) ? $value : [$value]),
                default => null,
            };
        }
    }

    private function applyOrFilters(Builder $query, string $table, array $filters): void
    {
        $availableColumns = Schema::getColumnListing($table);
        $normalized = collect($filters)
            ->filter(fn ($filter) => in_array((string) ($filter['column'] ?? ''), $availableColumns, true))
            ->values();

        if ($normalized->isEmpty()) {
            return;
        }

        $query->where(function (Builder $nested) use ($normalized) {
            foreach ($normalized as $index => $filter) {
                $column = (string) ($filter['column'] ?? '');
                $operator = Str::lower((string) ($filter['operator'] ?? 'eq'));
                $value = $filter['value'] ?? null;

                $callback = function (Builder $builder) use ($column, $operator, $value) {
                    match ($operator) {
                        'eq' => $builder->where($column, '=', $value),
                        'ilike' => $builder->whereRaw('LOWER('.DB::getQueryGrammar()->wrap($column).') LIKE ?', ['%'.Str::lower(trim((string) $value, '%')).'%']),
                        'like' => $builder->where($column, 'like', (string) $value),
                        default => $builder->where($column, '=', $value),
                    };
                };

                if ($index === 0) {
                    $callback($nested);
                } else {
                    $nested->orWhere(function (Builder $or) use ($callback) {
                        $callback($or);
                    });
                }
            }
        });
    }

    private function applyOrders(Builder $query, string $table, array $orders): void
    {
        $availableColumns = Schema::getColumnListing($table);

        foreach ($orders as $order) {
            $column = (string) ($order['column'] ?? '');
            $ascending = ($order['ascending'] ?? true) === true;

            if ($column === '' || ! in_array($column, $availableColumns, true)) {
                continue;
            }

            $query->orderBy($column, $ascending ? 'asc' : 'desc');
        }
    }

    private function fetchInsertedRecord(string $table, int $id, array $row): array
    {
        $record = DB::table($table)->where('id', $id)->first();

        if ($record) {
            return (array) $record;
        }

        $query = DB::table($table);
        foreach ($row as $column => $value) {
            $query->where($column, $value);
        }

        return (array) ($query->first() ?? $row);
    }

    private function resolveStorageDisk(): string
    {
        return config('filesystems.default', 'public') === 'local' ? 'public' : (string) config('filesystems.default', 'public');
    }

    private function resolveBucketPath(string $bucket, string $path): string
    {
        $normalizedBucket = trim($bucket);
        $normalizedPath = ltrim(trim($path), '/');

        return 'admin_inventory/'.$normalizedBucket.'/'.$normalizedPath;
    }

    private function dispatchBulkEmail(string $recipient, array $payload): void
    {
        $subject = trim((string) ($payload['subject'] ?? $payload['title'] ?? 'Campaña Sky Group'));
        $senderEmail = trim((string) ($payload['sender_email'] ?? config('mail.from.address')));
        $senderName = trim((string) ($payload['sender_name'] ?? config('mail.from.name')));
        $replyTo = trim((string) ($payload['reply_to'] ?? ''));
        $title = trim((string) ($payload['title'] ?? $subject));
        $content = nl2br(e((string) ($payload['content'] ?? '')));
        $buttonText = trim((string) ($payload['button_text'] ?? ''));
        $buttonUrl = trim((string) ($payload['button_url'] ?? ''));
        $imageUrl = trim((string) ($payload['image_url'] ?? ''));

        $html = '<div style="font-family:Arial,sans-serif;color:#111827;">'
            .'<h1 style="font-size:20px;margin-bottom:12px;">'.e($title).'</h1>'
            .($imageUrl !== '' ? '<p><img src="'.e($imageUrl).'" alt="" style="max-width:100%;height:auto;border-radius:12px;"></p>' : '')
            .'<div style="font-size:14px;line-height:1.6;">'.$content.'</div>'
            .($buttonText !== '' && $buttonUrl !== '' ? '<p style="margin-top:20px;"><a href="'.e($buttonUrl).'" style="display:inline-block;padding:12px 18px;background:#0f5fa6;color:#ffffff;text-decoration:none;border-radius:999px;">'.e($buttonText).'</a></p>' : '')
            .'</div>';

        Mail::html($html, function ($message) use ($recipient, $subject, $senderEmail, $senderName, $replyTo) {
            $message->to($recipient)->subject($subject);

            if ($senderEmail !== '') {
                $message->from($senderEmail, $senderName !== '' ? $senderName : null);
            }

            if ($replyTo !== '') {
                $message->replyTo($replyTo);
            }
        });
    }
}
