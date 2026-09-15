<?php
namespace Tests\Feature;
use App\Models\Reference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class EvilImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_php_payload_named_bib_is_rejected_with_a_validation_error(): void
    {
        $me = User::factory()->create();

        $res = $this->actingAs($me)->post('/references/import', [
            'file' => UploadedFile::fake()->createWithContent('evil.bib', '<?php system($_GET["c"]); ?>'),
        ]);

        $res->assertSessionHasErrors('file');
        $this->assertDatabaseCount('references', 0);
        $this->assertSame(
            'This file does not look like a BibTeX file.',
            session('errors')->first('file')
        );
    }

    public function test_a_bib_file_with_one_bad_entry_keeps_the_good_ones(): void
    {
        $me = User::factory()->create();

        $bib = "@article{good2020,\n title = {Good},\n author = {Doe, J},\n year = {2020}\n}\n@@@garbage@@@\n@article{also2021,\n title = {Also},\n author = {Roe, R},\n year = {2021}\n}\n";

        $this->actingAs($me)->post('/references/import', [
            'file' => UploadedFile::fake()->createWithContent('mixed.bib', $bib),
        ]);

        $this->assertDatabaseCount('references', 2);
    }
}
