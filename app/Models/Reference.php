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
     * Search by title, author, or year.
     * Kept on the model so both the controller and tests use one definition.
     */
    public function scopeSearch($query, ?string $term)
    {
        if ($term === null || trim($term) === '') {
            return $query;
        }

        $like = '%' . trim($term) . '%';

        return $query->where(function ($q) use ($like) {
            $q->where('title', 'like', $like)
              ->orWhere('authors', 'like', $like)
              ->orWhere('year', 'like', $like)
              ->orWhere('doi', 'like', $like);
        });
    }
}
