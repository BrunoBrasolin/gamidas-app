<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A complementary activity now has any number of attachments, each marked
     * as certificate, report or other, and the Markdown report becomes a
     * "report filled in" flag (the college hands out a standard form, which
     * is attached). The current certificate of each activity becomes its first
     * attachment; a report already written is kept as a Markdown attachment of
     * the report kind, and its activity is marked as having the report.
     */
    public function up(): void
    {
        Schema::create('complementary_activity_attachments', function (Blueprint $table) {
            $table->id();
            // Named by hand: the generated name would pass MySQL's 64 characters.
            $table->foreignId('complementary_activity_id')
                ->constrained('complementary_activities', indexName: 'complementary_attachments_activity_foreign')
                ->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('disk', 40);
            $table->string('path');
            $table->string('filename', 200);
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->timestamps();

            $table->index(['complementary_activity_id', 'kind'], 'complementary_attachments_activity_kind_index');
        });

        Schema::table('complementary_activities', function (Blueprint $table) {
            $table->boolean('report_done')->default(false)->after('minutes');
        });

        DB::table('complementary_activities')->orderBy('id')->each(function (object $activity) {
            $now = now();

            DB::table('complementary_activity_attachments')->insert([
                'complementary_activity_id' => $activity->id,
                'kind' => 'certificate',
                'disk' => $activity->certificate_disk,
                'path' => $activity->certificate_path,
                'filename' => $activity->certificate_filename,
                'mime' => $activity->certificate_mime,
                'size' => $activity->certificate_size,
                'created_at' => $activity->created_at ?? $now,
                'updated_at' => $activity->updated_at ?? $now,
            ]);

            $report = (string) $activity->report;

            if (trim($report) === '') {
                return;
            }

            $path = 'complementary-hours/'.$activity->user_id.'/'.Str::ulid()->toBase32().'.md';
            Storage::disk($activity->certificate_disk)->put($path, $report);
            $slug = Str::limit(Str::slug((string) $activity->title), 80, '');

            DB::table('complementary_activity_attachments')->insert([
                'complementary_activity_id' => $activity->id,
                'kind' => 'report',
                'disk' => $activity->certificate_disk,
                'path' => $path,
                'filename' => 'relatorio-'.($slug === '' ? $activity->id : $slug).'.md',
                'mime' => 'text/markdown',
                'size' => strlen($report),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('complementary_activities')->where('id', $activity->id)->update(['report_done' => true]);
        });

        Schema::table('complementary_activities', function (Blueprint $table) {
            $table->dropColumn(['certificate_disk', 'certificate_path', 'certificate_filename', 'certificate_mime', 'certificate_size', 'report']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * Each activity gets back its first certificate (or first attachment) and
     * the text of a Markdown report attachment; the other files stay on disk.
     */
    public function down(): void
    {
        Schema::table('complementary_activities', function (Blueprint $table) {
            $table->string('certificate_disk', 40)->nullable();
            $table->string('certificate_path')->nullable();
            $table->string('certificate_filename', 200)->nullable();
            $table->string('certificate_mime', 100)->nullable();
            $table->unsignedBigInteger('certificate_size')->nullable();
            $table->mediumText('report')->nullable();
        });

        DB::table('complementary_activities')->orderBy('id')->each(function (object $activity) {
            $attachments = DB::table('complementary_activity_attachments')
                ->where('complementary_activity_id', $activity->id)
                ->orderBy('id')
                ->get();

            $certificate = $attachments->firstWhere('kind', 'certificate') ?? $attachments->first();
            $report = $attachments->first(fn (object $row) => $row->kind === 'report' && $row->mime === 'text/markdown');

            DB::table('complementary_activities')->where('id', $activity->id)->update([
                'certificate_disk' => $certificate?->disk,
                'certificate_path' => $certificate?->path,
                'certificate_filename' => $certificate?->filename,
                'certificate_mime' => $certificate?->mime,
                'certificate_size' => $certificate?->size,
                'report' => $report === null ? null : Storage::disk($report->disk)->get($report->path),
            ]);
        });

        Schema::table('complementary_activities', function (Blueprint $table) {
            $table->dropColumn('report_done');
        });

        Schema::dropIfExists('complementary_activity_attachments');
    }
};
