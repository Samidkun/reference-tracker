<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Laravel 13 registers public routes for any disk with 'serve' => true:
 *
 *   GET  storage/{path}   storage.local
 *   PUT  storage/{path}   storage.local.upload
 *
 * These have NO middleware - no auth, no CSRF. They look alarming, and a
 * reviewer flagged them as an arbitrary file upload. They are in fact safe,
 * but ONLY because of two config properties that are easy to change by
 * accident:
 *
 *   1. `visibility` is not set on the disk, so ServeFile falls back to
 *      'private' and therefore requires a signed URL.
 *   2. `root` points at storage/app/private, not a web-served directory.
 *
 * If someone later adds 'visibility' => 'public' to that disk to "make
 * uploads easier", ServeFile stops requiring a signature and every file
 * under storage/app/private becomes world-readable and world-writable.
 * These tests fail loudly in that case.
 */
class StorageRouteSecurityTest extends TestCase
{
    public function test_the_local_disk_does_not_declare_public_visibility(): void
    {
        $visibility = config('filesystems.disks.local.visibility');

        $this->assertNotSame(
            'public',
            $visibility,
            'The local disk must stay private: ServeFile only requires a signed '
            . 'URL when visibility is not "public". Setting it to "public" makes '
            . 'storage/app/private world-readable and writable via /storage/{path}.'
        );
    }

    public function test_the_local_disk_root_is_outside_the_public_directory(): void
    {
        $root = config('filesystems.disks.local.root');

        $this->assertStringNotContainsString('public', basename(dirname($root)));
        $this->assertSame('private', basename($root));
    }

    public function test_an_unsigned_get_on_the_storage_route_is_rejected(): void
    {
        $this->get('/storage/anything.txt')->assertStatus(403);
    }

    public function test_an_unsigned_upload_to_the_storage_route_is_rejected(): void
    {
        $this->put('/storage/pwned.txt', ['contents' => 'owned'])->assertStatus(403);
    }

    public function test_path_traversal_does_not_escape_the_storage_root(): void
    {
        // even with a signed URL this must not reach outside the disk root
        $this->get('/storage/../.env')->assertStatus(403);
    }

    public function test_a_php_file_cannot_be_uploaded_to_the_storage_route(): void
    {
        $this->put('/storage/shell.php', ['contents' => '<?php echo 1; ?>'])->assertStatus(403);
    }
}
