<?php

namespace App\Http\Controllers;

use App\Http\Requests\Message\ViewMessageInfoRequest;
use App\Models\Message;
use App\Services\MessageInfoService;
use Illuminate\Http\JsonResponse;

class MessageInfoController extends Controller
{
    public function __construct(private readonly MessageInfoService $info) {}

    /** Who saw this message, who hasn't, and who reacted. */
    public function show(ViewMessageInfoRequest $request, Message $message): JsonResponse
    {
        return response()->json(['data' => $this->info->for($message)]);
    }
}
