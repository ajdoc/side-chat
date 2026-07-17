<?php

namespace App\Http\Controllers;

use App\Actions\Comment\DeleteCommentAction;
use App\Actions\Comment\ToggleCommentAction;
use App\DTOs\Comment\AddCommentData;
use App\Http\Requests\Comment\DeleteCommentRequest;
use App\Http\Requests\Comment\IndexCommentRequest;
use App\Http\Requests\Comment\StoreCommentRequest;
use App\Http\Resources\CommentResource;
use App\Http\Resources\MessageResource;
use App\Models\Comment;
use App\Models\Message;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CommentController extends Controller
{
    /** The full comment list for a message — the "see all" behind the chips. */
    public function index(IndexCommentRequest $request, Message $message): AnonymousResourceCollection
    {
        return CommentResource::collection(
            $message->comments()->with('user')->orderBy('id')->get()
        );
    }

    /** Leave a comment, or take it back if it's the same phrase you already left. */
    public function store(StoreCommentRequest $request, Message $message, ToggleCommentAction $action): MessageResource
    {
        return new MessageResource(
            $action->handle($message, $request->user(), AddCommentData::fromArray($request->validated()))
        );
    }

    /** Remove one of your own comments from the list. */
    public function destroy(DeleteCommentRequest $request, Comment $comment, DeleteCommentAction $action): MessageResource
    {
        return new MessageResource($action->handle($comment));
    }
}
