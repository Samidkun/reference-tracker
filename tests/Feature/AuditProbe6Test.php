<?php

namespace Tests\Feature;

use App\Models\Reference;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditProbe6Test extends TestCase
{
    use RefreshDatabase;

    public function test_index_query_count(): void
    {
        $me = User::factory()->create();
        foreach (range(1, 15) as $i) {
            $ref = Reference::factory()->for($me)->create();
            $ref->tags()->sync([Tag::factory()->for($me)->create()->id]);
        }
        DB::enableQueryLog();
        $this->actingAs($me)->get('/references')->assertOk();
        $log = DB::getQueryLog();
        fwrite(STDERR, "\n[Q] index queries for 15 refs+tags: " . count($log) . "\n");
        foreach (array_slice($log, 0, 6) as $q) {
            fwrite(STDERR, "    " . substr($q['query'], 0, 110) . "\n");
        }
        $this->assertTrue(true);
    }

    public function test_export_query_count(): void
    {
        $me = User::factory()->create();
        foreach (range(1, 10) as $i) {
            $ref = Reference::factory()->for($me)->create();
            $ref->tags()->sync([Tag::factory()->for($me)->create()->id]);
        }
        DB::enableQueryLog();
        $this->actingAs($me)->get('/references/export');
        $log = DB::getQueryLog();
        fwrite(STDERR, "\n[Q] export queries for 10 refs: " . count($log) . "\n");
        $this->assertTrue(true);
    }

    /** Storage routes: verify behaviour with the test client. */
    public function test_storage_routes(): void
    {
        $get = $this->get('/storage/anything.txt');
        fwrite(STDERR, "\n[STORAGE] unsigned GET /storage/anything.txt -> " . $get->getStatusCode() . "\n");

        $put = $this->put('/storage/shell.php', ['x' => 1]);
        fwrite(STDERR, "[STORAGE] unsigned PUT /storage/shell.php -> " . $put->getStatusCode() . "\n");

        $trav = $this->get('/storage/../.env');
        fwrite(STDERR, "[STORAGE] GET /storage/../.env -> " . $trav->getStatusCode() . "\n");

        $exists = file_exists(storage_path('app/private/shell.php'));
        fwrite(STDERR, "[STORAGE] shell.php on disk? " . var_export($exists, true) . "\n");

        fwrite(STDERR, "[STORAGE] local disk visibility config: " . var_export(config('filesystems.disks.local.visibility'), true)
            . " root=" . config('filesystems.disks.local.root')
            . " serve=" . var_export(config('filesystems.disks.local.serve'), true) . "\n");
        $this->assertTrue(true);
    }

    /** Mass assignment: does a client-supplied user_id survive any path? */
    public function test_mass_assignment_surface(): void
    {
        $me = User::factory()->create();
        $victim = User::factory()->create();
        $this->actingAs($me)->post('/references', [
            'title' => 'M', 'authors' => ['A, B'], 'type' => 'journal',
            'user_id' => $victim->id,
        ]);
        $r = Reference::where('title', 'M')->first();
        fwrite(STDERR, "\n[MASS] created user_id=" . ($r?->user_id) . " (me=" . $me->id . ", victim=" . $victim->id . ")\n");
        fwrite(STDERR, "[MASS] Reference::\$fillable = " . implode(',', (new Reference)->getFillable()) . "\n");
        fwrite(STDERR, "[MASS] User uses #[Fillable] attribute; guard = " . var_export((new User)->getFillable(), true) . "\n");
        $this->assertTrue(true);
    }
}
