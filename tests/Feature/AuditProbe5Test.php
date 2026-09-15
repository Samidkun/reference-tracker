<?php

namespace Tests\Feature;

use App\Models\Reference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditProbe5Test extends TestCase
{
    use RefreshDatabase;

    /** notes max:20000 chars but column is TEXT (65535 BYTES). 4-byte chars overflow. */
    public function test_notes_multibyte_overflow(): void
    {
        $me = User::factory()->create();
        $notes = str_repeat('😀', 20000); // 20000 * 4 bytes = 80000 bytes > 65535
        $res = $this->actingAs($me)->post('/references', [
            'title' => 'Notes test',
            'authors' => ['A, B'],
            'type' => 'journal',
            'notes' => $notes,
        ]);
        fwrite(STDERR, "\n[NOTES] 20000x emoji status=" . $res->getStatusCode()
            . " refs=" . Reference::count() . "\n");

        // exactly at the validation boundary, 3-byte chars
        $res2 = $this->actingAs($me)->post('/references', [
            'title' => 'Notes test2',
            'authors' => ['A, B'],
            'type' => 'journal',
            'notes' => str_repeat('あ', 20000), // 3 bytes * 20000 = 60000 < 65535 ok
        ]);
        fwrite(STDERR, "[NOTES] 20000x 3-byte status=" . $res2->getStatusCode() . "\n");
        $this->assertTrue(true);
    }
}
