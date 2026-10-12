<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Preferred products, profile fields and the email engagement score (stars) of each contact.
 *
 * Contacts now have only two states, Activo and Desinscrito: everyone already in the CRM accepted the privacy
 * policy when they registered, so whoever has not unsubscribed becomes Activo. People who signed up on the website
 * and never confirmed their email stay unconfirmed (double opt-in).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->json('preferred_products')->nullable()->after('organization');
            $table->boolean('preferred_products_manual')->default(false)->after('preferred_products');
            $table->string('profile', 60)->nullable()->after('preferred_products_manual');
            $table->string('language', 30)->nullable()->after('profile');
            $table->string('referral_source', 80)->nullable()->after('language');
            $table->unsignedTinyInteger('engagement_stars')->nullable()->index()->after('referral_source');
            $table->unsignedSmallInteger('emails_sent')->default(0)->after('engagement_stars');
            $table->unsignedSmallInteger('emails_opened')->default(0)->after('emails_sent');
            $table->unsignedSmallInteger('emails_clicked')->default(0)->after('emails_opened');
        });

        DB::table('contacts')
            ->whereNull('unsubscribed_at')
            ->where('marketing_consent', false)
            ->where(fn ($q) => $q->where('source', '!=', 'web')->orWhere('is_subscriber', true))
            ->update([
                'marketing_consent' => true,
                'consent_at' => DB::raw('COALESCE(consent_at, created_at)'),
                'consent_source' => DB::raw("COALESCE(consent_source, 'Aceptó la política de privacidad al inscribirse')"),
            ]);
    }

    public function down(): void
    {
        Schema::table('contacts', fn (Blueprint $table) => $table->dropColumn([
            'preferred_products', 'preferred_products_manual', 'profile', 'language', 'referral_source',
            'engagement_stars', 'emails_sent', 'emails_opened', 'emails_clicked',
        ]));
    }
};
