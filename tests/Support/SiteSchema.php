<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Kadi site's tables GMS reads, built on the test `kadi_site` connection
 * (sqlite :memory:, set in Tests\TestCase). Mirrors the site's migrations
 * (kadi database/migrations, up to 2026_11_15) for the granted tables only.
 */
final class SiteSchema
{
    public static function create(): void
    {
        $schema = Schema::connection('kadi_site');

        $schema->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->integer('linked_id')->nullable();
        });

        $schema->create('sb_sports', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug');
            $table->unsignedSmallInteger('sort_order')->default(100);
            $table->timestamps();
        });

        $schema->create('sb_competitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sport_id');
            $table->string('odds_api_key');
            $table->string('slug');
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('is_active_in_feed')->default(true);
            $table->boolean('is_synced')->default(false);
            $table->boolean('auto_settle')->default(true);
            $table->timestamp('scores_checked_at')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(100);
            $table->timestamps();
        });

        $schema->create('sb_fixtures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('competition_id');
            $table->string('external_id');
            $table->string('home_team');
            $table->string('away_team');
            $table->timestamp('kicks_off_at');
            $table->string('status')->default('scheduled');
            $table->timestamp('last_seen_in_feed_at')->nullable();
            $table->unsignedSmallInteger('home_score')->nullable();
            $table->unsignedSmallInteger('away_score')->nullable();
            $table->timestamp('score_updated_at')->nullable();
            $table->timestamp('score_changed_at')->nullable();
            $table->timestamp('feed_completed_at')->nullable();
            $table->timestamp('extras_synced_at')->nullable();
            $table->timestamps();
        });

        $schema->create('sb_markets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fixture_id');
            $table->string('type');
            $table->decimal('line', 5, 2)->nullable();
            $table->string('key');
            $table->string('source')->default('feed');
            $table->string('status')->default('suspended');
            $table->string('suspension_reason')->nullable();
            $table->unsignedSmallInteger('bookmaker_count')->default(0);
            $table->timestamp('priced_at')->nullable();
            $table->timestamps();
        });

        $schema->create('sb_selections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('market_id');
            $table->string('outcome');
            $table->string('name');
            $table->decimal('fair_probability', 9, 6)->nullable();
            $table->decimal('feed_price', 8, 2)->nullable();
            $table->decimal('override_price', 8, 2)->nullable();
            $table->string('override_reason')->nullable();
            $table->foreignId('override_by_admin_id')->nullable();
            $table->string('override_by_staff', 150)->nullable();
            $table->decimal('previous_price', 8, 2)->nullable();
            $table->timestamp('price_changed_at')->nullable();
            $table->timestamps();
        });

        $schema->create('sb_fixture_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fixture_id');
            $table->unsignedSmallInteger('home_score')->nullable();
            $table->unsignedSmallInteger('away_score')->nullable();
            $table->unsignedSmallInteger('half_time_home_score')->nullable();
            $table->unsignedSmallInteger('half_time_away_score')->nullable();
            $table->boolean('is_void')->default(false);
            $table->string('source', 10);
            $table->foreignId('recorded_by_admin_id')->nullable();
            $table->string('recorded_by_staff', 150)->nullable();
            $table->string('note')->nullable();
            $table->timestamps();
        });

        $schema->create('sb_free_bets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->bigInteger('amount');
            $table->decimal('min_odds', 8, 2);
            $table->timestamp('expires_at');
            $table->string('status', 12);
            $table->string('source', 12);
            $table->string('note')->nullable();
            $table->foreignId('admin_id')->nullable();
            $table->unsignedBigInteger('replaces_bet_id')->nullable();
            $table->string('void_reason')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();
        });

        $schema->create('sb_bets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->foreignId('free_bet_id')->nullable();
            $table->string('reference', 12);
            $table->uuid('wager_reference')->nullable();
            $table->string('type', 10);
            $table->string('status', 12);
            $table->string('reject_reason', 40)->nullable();
            $table->string('product', 40)->nullable();
            $table->bigInteger('stake');
            $table->decimal('total_odds', 14, 2);
            $table->bigInteger('potential_payout');
            $table->unsignedTinyInteger('bonus_percent')->default(0);
            $table->bigInteger('payout')->nullable();
            $table->bigInteger('bonus')->nullable();
            $table->bigInteger('tax')->nullable();
            $table->string('odds_change_policy', 10);
            $table->string('client_key', 64)->nullable();
            $table->unsignedSmallInteger('settlement_version')->default(0);
            $table->timestamp('placed_at');
            $table->timestamp('accept_after')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
        });

        $schema->create('sb_bet_legs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bet_id');
            $table->foreignId('selection_id')->nullable();
            $table->foreignId('market_id')->nullable();
            $table->foreignId('fixture_id');
            $table->string('fixture_name');
            $table->timestamp('kicks_off_at');
            $table->string('market_type', 20);
            $table->decimal('market_line', 5, 2)->nullable();
            $table->string('market_name');
            $table->string('outcome', 20);
            $table->string('selection_name');
            $table->decimal('price', 8, 2);
            $table->string('status', 10);
            $table->string('void_reason')->nullable();
            $table->boolean('in_play')->default(false);
            $table->string('score_at_placement', 9)->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
        });

        $schema->create('sb_jackpots', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('status', 12);
            $table->bigInteger('entry_price');
            $table->json('prize_tiers');
            $table->unsignedTinyInteger('void_limit');
            $table->unsignedInteger('settlement_version')->default(1);
            $table->string('cancel_reason')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
        });

        $schema->create('sb_jackpot_fixtures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('jackpot_id');
            $table->foreignId('fixture_id');
            $table->unsignedTinyInteger('position');
            $table->string('fixture_name');
            $table->timestamp('kicks_off_at');
            $table->string('outcome', 1)->nullable();
            $table->boolean('is_void')->default(false);
            $table->string('void_reason')->nullable();
            $table->timestamps();
        });

        $schema->create('sb_jackpot_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('jackpot_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('reference', 12);
            $table->uuid('wager_reference')->nullable();
            $table->string('status', 12);
            $table->string('reject_reason', 40)->nullable();
            $table->bigInteger('stake');
            $table->unsignedTinyInteger('correct_count')->nullable();
            $table->bigInteger('prize')->nullable();
            $table->bigInteger('tax')->nullable();
            $table->string('client_key', 64)->nullable();
            $table->timestamp('placed_at');
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
        });

        $schema->create('sb_wager_ops', function (Blueprint $table): void {
            $table->id();
            $table->string('product', 50);
            $table->uuid('wager_reference');
            $table->string('subject_type', 20);
            $table->unsignedBigInteger('subject_id');
            $table->string('operation', 10);
            $table->unsignedInteger('version');
            $table->json('payload');
            $table->char('fingerprint', 64);
            $table->string('status', 10)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->unsignedSmallInteger('last_status')->nullable();
            $table->string('last_error', 60)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });

        $schema->create('sb_odds_api_calls', function (Blueprint $table): void {
            $table->id();
            $table->string('endpoint');
            $table->string('sport_key')->nullable();
            $table->string('purpose', 20)->nullable();
            $table->unsignedInteger('credits_spent')->default(0);
            $table->unsignedInteger('credits_remaining')->nullable();
            $table->unsignedInteger('credits_used')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->boolean('succeeded');
            $table->string('error')->nullable();
            $table->timestamp('created_at');
        });

        $schema->create('activity_log', function (Blueprint $table): void {
            $table->id();
            $table->string('log_name')->nullable();
            $table->text('description');
            $table->nullableMorphs('subject', 'subject');
            $table->string('event')->nullable();
            $table->nullableMorphs('causer', 'causer');
            $table->json('attribute_changes')->nullable();
            $table->json('properties')->nullable();
            $table->timestamps();
        });
    }
}
