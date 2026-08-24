<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Someone named in a comment.
 *
 * The only push signal in the product. Everything else — a deadline, an
 * assignment, an agent waiting on approval — is state you have to remember to
 * go and look at, which is the same as no signal at all.
 */
class CommentMention extends Model
{
    use HasUlids;

    protected $fillable = ['comment_id', 'mentioned_user_id', 'notified_at', 'read_at'];

    protected function casts(): array
    {
        return [
            'notified_at' => 'datetime',
            'read_at'     => 'datetime',
        ];
    }

    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'mentioned_user_id');
    }

    public function isUnread(): bool
    {
        return $this->read_at === null;
    }
}
