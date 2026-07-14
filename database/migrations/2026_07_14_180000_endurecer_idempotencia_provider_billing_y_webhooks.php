<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('webhook_events')) {
            $this->deduplicateWebhookEvents();
            DB::statement('create unique index if not exists webhook_events_provider_event_unique on webhook_events (provider, event_id)');
        }

        if (Schema::hasTable('aircraft_billing_payments')) {
            $this->deduplicateAircraftBillingPayments();
            DB::statement('create unique index if not exists aircraft_billing_payments_period_unique on aircraft_billing_payments (provider_id, aircraft_id, billing_plan_id, billing_period_start, billing_period_end, provider)');
        }

        if (Schema::hasTable('aircraft_subscriptions')) {
            $this->deduplicateAircraftSubscriptions();
            DB::statement('create unique index if not exists aircraft_subscriptions_aircraft_plan_unique on aircraft_subscriptions (aircraft_id, plan_id)');
        }
    }

    public function down(): void
    {
        DB::statement('drop index if exists webhook_events_provider_event_unique');
        DB::statement('drop index if exists aircraft_billing_payments_period_unique');
        DB::statement('drop index if exists aircraft_subscriptions_aircraft_plan_unique');
    }

    private function deduplicateWebhookEvents(): void
    {
        DB::statement(<<<'SQL'
            delete from webhook_events
            where id in (
                select id
                from (
                    select id,
                           row_number() over (
                               partition by provider, event_id
                               order by
                                   case status
                                       when 'processed' then 0
                                       when 'received' then 1
                                       when 'failed' then 2
                                       else 3
                                   end,
                                   processed_at desc nulls last,
                                   updated_at desc nulls last,
                                   created_at desc nulls last,
                                   id desc
                           ) as duplicate_rank
                    from webhook_events
                    where event_id is not null
                ) ranked_duplicates
                where duplicate_rank > 1
            )
        SQL);
    }

    private function deduplicateAircraftBillingPayments(): void
    {
        DB::statement(<<<'SQL'
            delete from aircraft_billing_payments
            where id in (
                select id
                from (
                    select id,
                           row_number() over (
                               partition by provider_id, aircraft_id, billing_plan_id, billing_period_start, billing_period_end, provider
                               order by
                                   case status
                                       when 'paid' then 0
                                       when 'succeeded' then 1
                                       when 'completed' then 2
                                       when 'pending' then 3
                                       else 4
                                   end,
                                   paid_at desc nulls last,
                                   updated_at desc nulls last,
                                   created_at desc nulls last,
                                   id desc
                           ) as duplicate_rank
                    from aircraft_billing_payments
                    where billing_period_start is not null
                      and billing_period_end is not null
                ) ranked_duplicates
                where duplicate_rank > 1
            )
        SQL);
    }

    private function deduplicateAircraftSubscriptions(): void
    {
        DB::statement(<<<'SQL'
            delete from aircraft_subscriptions
            where id in (
                select id
                from (
                    select id,
                           row_number() over (
                               partition by aircraft_id, plan_id
                               order by
                                   case status
                                       when 'active' then 0
                                       when 'paid' then 1
                                       when 'pending' then 2
                                       when 'cancelled' then 3
                                       else 4
                                   end,
                                   ends_at desc nulls last,
                                   updated_at desc nulls last,
                                   created_at desc nulls last,
                                   id desc
                           ) as duplicate_rank
                    from aircraft_subscriptions
                ) ranked_duplicates
                where duplicate_rank > 1
            )
        SQL);
    }
};
