<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bill lines (unlike Invoice lines) carry a per-line expense_account_id — Database
        // Design "bill_line". A Debit Note corrects a specific Bill line and must credit the
        // exact same account back on reversal, so it needs the same reference; Credit Notes
        // don't, since Invoice recognition posts to one shared revenue account.
        Schema::table('payables_debit_note_lines', function (Blueprint $table): void {
            $table->uuid('expense_account_id')->nullable()->after('source_line_id');
        });
    }

    public function down(): void
    {
        Schema::table('payables_debit_note_lines', function (Blueprint $table): void {
            $table->dropColumn('expense_account_id');
        });
    }
};
