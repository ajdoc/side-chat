<?php

namespace App\Actions\Comment;

use App\DTOs\Comment\AddCommentData;
use App\Events\CommentPosted;
use App\Models\Comment;
use App\Models\Message;
use App\Models\User;

final class ToggleCommentAction
{
    /**
     * Leave a comment, or take it back if this user already left that exact phrase.
     *
     * The toggle is what lets a chip work like a reaction: clicking `✓ Looks good (18)`
     * when it's already yours removes your co-sign; clicking one that isn't adds it. A
     * freshly typed comment simply never matches, so it's always an add. Grouping is by
     * the normalized body (+emoji), so casing and stray spaces don't fork the count.
     *
     * Broadcast goes to everyone, the commenter included — the payload is the whole
     * summary, so re-applying it is a harmless no-op for whoever already had it.
     */
    public function handle(Message $message, User $user, AddCommentData $data): Message
    {
        $bodyKey = Comment::normalize($data->body);

        $existing = $message->comments()
            ->where('user_id', $user->id)
            ->where('body_key', $bodyKey)
            ->where('emoji', $data->emoji)
            ->first();

        if ($existing) {
            $existing->delete();
        } else {
            $message->comments()->create([
                'user_id' => $user->id,
                'body' => trim($data->body),
                'body_key' => $bodyKey,
                'emoji' => $data->emoji,
            ]);
        }

        $message->load('comments.user');

        broadcast(new CommentPosted($message));

        return $message;
    }
}
