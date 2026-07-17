<?php

namespace App\Http\Controladores;

use App\Modelos\Aeronave;
use App\Modelos\AircraftChecklist;
use App\Modelos\AircraftChecklistItem;
use App\Modelos\DocumentoAeronave;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class AdminAircraftChecklistController extends ControladorBase
{
    private const ALLOWED_STATUSES = ['pending', 'approved', 'rejected', 'missing'];

    private const DEFINITIONS = [
        [
            'key' => 'airworthiness_certificate',
            'label' => 'Certificado de aeronavegabilidad',
            'aliases' => ['airworthiness', 'aeronavegabilidad', 'airworthiness_certificate', 'certificado_aeronavegabilidad'],
        ],
        [
            'key' => 'registration',
            'label' => 'Matrícula',
            'aliases' => ['registration', 'registro', 'matricula', 'aircraft_registration'],
        ],
        [
            'key' => 'insurance',
            'label' => 'Seguro',
            'aliases' => ['insurance', 'seguro', 'insurance_policy', 'poliza', 'poliza_seguro'],
        ],
        [
            'key' => 'maintenance',
            'label' => 'Programa o evidencia de mantenimiento',
            'aliases' => ['maintenance', 'mantenimiento', 'maintenance_sticker', 'logbook', 'bitacora', 'bitacora_vuelo'],
        ],
        [
            'key' => 'exterior_photos',
            'label' => 'Fotografías exteriores',
            'aliases' => ['exterior', 'photo', 'photos', 'fotografias', 'gallery', 'image'],
        ],
    ];

    public function show(Aeronave $aircraft)
    {
        $checklist = $this->resolveChecklist($aircraft);
        $items = $this->buildResponseItems($aircraft, $checklist);

        return $this->ok([
            'data' => [
                'aircraft_id' => $aircraft->id,
                'progress' => $this->buildProgress($items),
                'items' => $items,
            ],
        ]);
    }

    public function update(Request $request, Aeronave $aircraft)
    {
        if (! $this->checklistTablesReady()) {
            return response()->json([
                'success' => false,
                'message' => 'El checklist administrativo de aeronaves aun no esta listo para persistencia en este ambiente.',
            ], 503);
        }

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.key' => ['required', 'string', Rule::in(array_column(self::DEFINITIONS, 'key'))],
            'items.*.status' => ['required', 'string', Rule::in(self::ALLOWED_STATUSES)],
            'items.*.notes' => ['nullable', 'string'],
        ]);

        $checklist = AircraftChecklist::query()->firstOrCreate(
            ['aircraft_id' => $aircraft->id],
            [
                'created_by' => $request->user()?->id,
                'updated_by' => $request->user()?->id,
            ],
        );

        foreach ($validated['items'] as $payloadItem) {
            $definition = collect(self::DEFINITIONS)->firstWhere('key', $payloadItem['key']);

            AircraftChecklistItem::query()->updateOrCreate(
                [
                    'checklist_id' => $checklist->id,
                    'item_key' => $payloadItem['key'],
                ],
                [
                    'label' => $definition['label'],
                    'status' => $payloadItem['status'],
                    'notes' => $payloadItem['notes'] ?? null,
                ],
            );
        }

        $checklist->forceFill([
            'updated_by' => $request->user()?->id,
        ])->save();

        $checklist->load('items');
        $items = $this->buildResponseItems($aircraft, $checklist);

        return $this->ok([
            'data' => [
                'aircraft_id' => $aircraft->id,
                'progress' => $this->buildProgress($items),
                'items' => $items,
            ],
        ]);
    }

    private function resolveChecklist(Aeronave $aircraft): ?AircraftChecklist
    {
        if (! $this->checklistTablesReady()) {
            return null;
        }

        return AircraftChecklist::query()
            ->with('items')
            ->where('aircraft_id', $aircraft->id)
            ->first();
    }

    private function buildResponseItems(Aeronave $aircraft, ?AircraftChecklist $checklist): array
    {
        $documents = $aircraft->documents()->get();
        $images = $aircraft->images()->get();
        $persistedItems = $checklist?->items?->keyBy('item_key') ?? collect();

        return collect(self::DEFINITIONS)
            ->map(function (array $definition) use ($documents, $images, $persistedItems) {
                /** @var AircraftChecklistItem|null $persisted */
                $persisted = $persistedItems->get($definition['key']);

                return [
                    'key' => $definition['key'],
                    'label' => $definition['label'],
                    'status' => $persisted?->status ?: $this->inferStatus($definition, $documents, $images),
                    'notes' => $persisted?->notes,
                  ];
            })
            ->values()
            ->all();
    }

    private function buildProgress(array $items): array
    {
        $total = count($items);
        $completed = collect($items)->where('status', 'approved')->count();

        return [
            'completed' => $completed,
            'total' => $total,
            'percentage' => $total > 0 ? (int) round(($completed / $total) * 100) : 0,
        ];
    }

    private function inferStatus(array $definition, Collection $documents, Collection $images): string
    {
        if ($definition['key'] === 'exterior_photos') {
            return $images->isNotEmpty() ? 'approved' : 'missing';
        }

        $matchingDocuments = $documents->filter(function (DocumentoAeronave $document) use ($definition) {
            $tokens = collect([
                $document->document_type,
                $document->type,
                $document->file_type,
                $document->document_name,
            ])
                ->filter()
                ->map(fn ($value) => $this->normalizeToken($value));

            return collect($definition['aliases'])->contains(function (string $alias) use ($tokens) {
                $normalizedAlias = $this->normalizeToken($alias);
                return $tokens->contains(fn (string $token) => str_contains($token, $normalizedAlias));
            });
        });

        if ($matchingDocuments->isEmpty()) {
            return 'missing';
        }

        if ($matchingDocuments->contains(fn (DocumentoAeronave $document) => $this->normalizeDocumentStatus($document->status) === 'rejected')) {
            return 'rejected';
        }

        if ($matchingDocuments->every(fn (DocumentoAeronave $document) => $this->normalizeDocumentStatus($document->status) === 'approved')) {
            return 'approved';
        }

        return 'pending';
    }

    private function normalizeDocumentStatus(?string $status): string
    {
        $normalized = $this->normalizeToken($status);

        return match (true) {
            in_array($normalized, ['approved', 'aprobado', 'aprobada', 'vigente', 'validado'], true) => 'approved',
            in_array($normalized, ['rejected', 'rechazado', 'rechazada'], true) => 'rejected',
            in_array($normalized, ['missing', 'faltante'], true) => 'missing',
            default => 'pending',
        };
    }

    private function normalizeToken(mixed $value): string
    {
        return strtolower(trim((string) $value));
    }

    private function checklistTablesReady(): bool
    {
        return Schema::hasTable('aircraft_checklists') && Schema::hasTable('aircraft_checklist_items');
    }
}
