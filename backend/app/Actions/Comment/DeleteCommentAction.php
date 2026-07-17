<?php

namespace App\Actions\Comment;

use App\Events\CommentPosted;
use App\Models\Comment;
use App\Models\Message;

final class DeleteCommentAction
{
    /** Remove one specific comment (from the full list) and refresh the summary. */
    public function handle(Comment $comment): Message
    {
        $message = $comment->message;
        $comment->delete();

        $message->load('comments.user');

        broadcast(new CommentPosted($message));

        return $message;
    }
}
