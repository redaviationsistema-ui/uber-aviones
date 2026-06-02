<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('reservation_contracts')) {
            return;
        }

        Schema::table('reservation_contracts', function (Blueprint $table) {
            if (! Schema::hasColumn('reservation_contracts', 'docusign_envelope_id')) {
                $table->string('docusign_envelope_id')->nullable()->index()->after('document_url');
            }

            if (! Schema::hasColumn('reservation_contracts', 'docusign_status')) {
                $table->string('docusign_status', 40)->default('draft')->index()->after('docusign_envelope_id');
            }

            if (! Schema::hasColumn('reservation_contracts', 'signer_name')) {
                $table->string('signer_name')->nullable()->after('docusign_status');
            }

            if (! Schema::hasColumn('reservation_contracts', 'signer_email')) {
                $table->string('signer_email')->nullable()->after('signer_name');
            }

            if (! Schema::hasColumn('reservation_contracts', 'client_user_id')) {
                $table->string('client_user_id', 120)->nullable()->after('signer_email');
            }

            if (! Schema::hasColumn('reservation_contracts', 'contract_pdf_path')) {
                $table->string('contract_pdf_path')->nullable()->after('client_user_id');
            }

            if (! Schema::hasColumn('reservation_contracts', 'signed_pdf_path')) {
                $table->string('signed_pdf_path')->nullable()->after('contract_pdf_path');
            }

            if (! Schema::hasColumn('reservation_contracts', 'sent_at')) {
                $table->timestamp('sent_at')->nullable()->index()->after('generated_at');
            }

            if (! Schema::hasColumn('reservation_contracts', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->index()->after('signed_at');
            }

            if (! Schema::hasColumn('reservation_contracts', 'last_webhook_payload')) {
                $table->json('last_webhook_payload')->nullable()->after('completed_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('reservation_contracts')) {
            return;
        }

        Schema::table('reservation_contracts', function (Blueprint $table) {
            $columns = [
                'docusign_envelope_id',
                'docusign_status',
                'signer_name',
                'signer_email',
                'client_user_id',
                'contract_pdf_path',
                'signed_pdf_path',
                'sent_at',
                'completed_at',
                'last_webhook_payload',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('reservation_contracts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
