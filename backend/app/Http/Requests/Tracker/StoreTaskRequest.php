<?php

namespace App\Http\Requests\Tracker;

use App\Http\Requests\MemberRequest;
use App\Support\Tracker\TrackerFields;

/**
 * Creating or editing a task.
 *
 * `sometimes` on everything for the PATCH case, because the detail pane saves one field at a
 * time — changing the status must not require re-sending the title, and an absent field means
 * "leave it" rather than "clear it". Clearing is done by sending null, which the nullable
 * rules allow.
 */
class StoreTaskRequest extends MemberRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:20000'],
            'status' => ['sometimes', 'string', 'in:'.implode(',', TrackerFields::STATUSES)],
            'priority' => ['sometimes', 'string', 'in:'.implode(',', TrackerFields::PRIORITIES)],
            // Constrained to the channel's own members: an assignee who can't see the channel
            // couldn't open the task, and picking one would silently do nothing.
            'assignee_id' => ['nullable', 'integer', 'exists:users,id'],
            'due_date' => ['nullable', 'date'],
            'position' => ['sometimes', 'integer', 'min:0'],
            // Which project it goes in — required on create, and refused on edit: moving a task
            // between projects would have to renumber it, and a task whose key changes is a
            // reference that breaks everywhere it was quoted.
            'project_id' => [$this->isMethod('POST') ? 'required' : 'prohibited', 'integer'],
            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => ['integer'],
        ];
    }
}
