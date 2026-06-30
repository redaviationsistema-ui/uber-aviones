<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('providers')) {
            return;
        }

        Schema::table('providers', function (Blueprint $table) {
            if (! Schema::hasColumn('providers', 'legal_name')) {
                $table->string('legal_name', 255)->nullable()->after('commercial_name');
            }
            if (! Schema::hasColumn('providers', 'rfc')) {
                $table->string('rfc', 50)->nullable()->after('legal_name');
            }
            if (! Schema::hasColumn('providers', 'company_phone')) {
                $table->string('company_phone', 50)->nullable()->after('rfc');
            }
            if (! Schema::hasColumn('providers', 'company_email')) {
                $table->string('company_email', 255)->nullable()->after('company_phone');
            }
            if (! Schema::hasColumn('providers', 'base_airport')) {
                $table->string('base_airport', 20)->nullable()->after('company_email');
            }
            if (! Schema::hasColumn('providers', 'status')) {
                $table->string('status', 50)->nullable()->after('base_airport');
            }
            if (! Schema::hasColumn('providers', 'representative_name')) {
                $table->string('representative_name', 255)->nullable()->after('status');
            }
            if (! Schema::hasColumn('providers', 'representative_phone')) {
                $table->string('representative_phone', 50)->nullable()->after('representative_name');
            }
            if (! Schema::hasColumn('providers', 'birth_date')) {
                $table->date('birth_date')->nullable()->after('representative_phone');
            }
            if (! Schema::hasColumn('providers', 'curp')) {
                $table->string('curp', 32)->nullable()->after('birth_date');
            }
            if (! Schema::hasColumn('providers', 'nationality')) {
                $table->string('nationality', 120)->nullable()->after('curp');
            }
            if (! Schema::hasColumn('providers', 'document_type')) {
                $table->string('document_type', 50)->nullable()->after('nationality');
            }
            if (! Schema::hasColumn('providers', 'document_number')) {
                $table->string('document_number', 120)->nullable()->after('document_type');
            }
            if (! Schema::hasColumn('providers', 'document_expiration')) {
                $table->date('document_expiration')->nullable()->after('document_number');
            }
        });

        $providers = \App\Modelos\Proveedor::query()
            ->with(['user.profile'])
            ->get();

        foreach ($providers as $provider) {
            $user = $provider->user;
            $profile = $user?->profile;
            $taxData = is_array($profile?->tax_data) ? $profile->tax_data : [];

            $provider->forceFill([
                'legal_name' => $provider->legal_name ?: ($taxData['legal_name'] ?? $provider->company_name),
                'rfc' => $provider->rfc ?: ($taxData['rfc'] ?? null),
                'company_phone' => $provider->company_phone ?: $user?->phone,
                'company_email' => $provider->company_email ?: $user?->email,
                'base_airport' => $provider->base_airport ?: $profile?->base_airport,
                'status' => $provider->status ?: ($provider->approval_status ?: 'pending'),
                'representative_name' => $provider->representative_name ?: ($taxData['legal_representative'] ?? $user?->name),
                'representative_phone' => $provider->representative_phone ?: $user?->phone,
                'birth_date' => $provider->birth_date ?: $profile?->birth_date,
                'curp' => $provider->curp ?: $profile?->ine_curp,
                'nationality' => $provider->nationality ?: $profile?->nationality,
                'document_type' => $provider->document_type ?: $profile?->document_type,
                'document_number' => $provider->document_number ?: $profile?->document_number,
                'document_expiration' => $provider->document_expiration ?: $profile?->document_expiration,
            ])->saveQuietly();
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('providers')) {
            return;
        }

        Schema::table('providers', function (Blueprint $table) {
            foreach ([
                'legal_name',
                'rfc',
                'company_phone',
                'company_email',
                'base_airport',
                'status',
                'representative_name',
                'representative_phone',
                'birth_date',
                'curp',
                'nationality',
                'document_type',
                'document_number',
                'document_expiration',
            ] as $column) {
                if (Schema::hasColumn('providers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
