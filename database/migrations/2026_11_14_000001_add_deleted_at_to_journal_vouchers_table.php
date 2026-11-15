<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('journal_vouchers') && !Schema::hasColumn('journal_vouchers', 'deleted_at')) {
            Schema::table('journal_vouchers', function (Blueprint $table): void {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        throw new \LogicException('Voucher deletion history must be preserved; use a reviewed forward migration.');
    }
};
