<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Reference extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'title', 'authors', 'year', 'type',
        'doi', 'url', 'notes', 'cite_key',
    ];

    protected function casts(): array
    {
        return [
            'authors' => 'array',
            'year' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    /**
     * Search by title, author, year, or DOI.
     *
     * LOWER() on BOTH sides, deliberately. A plain LIKE relied on the column
     * collation being case-insensitive, which held for the string columns but
     * NOT for `authors` (a JSON column, compared byte-wise) — so searching
     * "vaswani" returned nothing while "Vaswani" worked. Forcing both sides
     * through LOWER() makes behaviour identical across every column type and
     * independent of collation.
     *
     * Kept on the model so the controller and the tests share one definition.
     */
    public function scopeSearch($query, ?string $term)
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $like = '%' . mb_strtolower($term) . '%';

        return $query->where(function ($q) use ($like) {
            $q->whereRaw('LOWER(title) LIKE ?', [$like])
              ->orWhereRaw('LOWER(authors) LIKE ?', [$like])
              ->orWhereRaw('LOWER(doi) LIKE ?', [$like])
              ->orWhereRaw('CAST(year AS CHAR) LIKE ?', [$like]);
        });
    }
}
