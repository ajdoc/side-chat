<?php

namespace App\Http\Requests\Automation;

use App\Http\Requests\ServerStaffRequest;
use App\Services\Automation\ActionRegistry;
use App\Services\Automation\TriggerRegistry;
use App\Support\Automation\ConditionEvaluator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Creating or replacing a rule.
 *
 * The registries do the validating. A trigger or action name is checked against what the
 * code actually has rather than against a list repeated here, so adding a handler doesn't
 * mean remembering to widen a rule in a request class — and a rule naming something that
 * doesn't exist is refused at the point somebody can still be told.
 *
 * Actions arrive as a whole ordered list and replace the previous one; there is no
 * per-action endpoint. A rule is read as a sequence, and editing it a step at a time would
 * mean a half-saved rule was a state the engine could pick up and run.
 */
class StoreAutomationRequest extends ServerStaffRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'trigger' => ['required', 'string', Rule::in(app(TriggerRegistry::class)->names())],
            'trigger_config' => ['nullable', 'array'],
            'enabled' => ['sometimes', 'boolean'],

            // Whether the filters below are ANDed or ORed. One connective per rule — see
            // ConditionEvaluator for why there's no nesting.
            'condition_match' => ['sometimes', Rule::in(ConditionEvaluator::MATCHES)],
            'conditions' => ['nullable', 'array', 'max:10'],
            'conditions.*.field' => ['required', 'string', 'max:64'],
            'conditions.*.operator' => ['required', Rule::in(array_keys(ConditionEvaluator::OPERATORS))],
            'conditions.*.value' => ['nullable'],

            // At least one: a rule that does nothing is not a rule, it's a rule somebody
            // hasn't finished writing, and saving it would mean a row that fires and has no
            // visible effect.
            'actions' => ['required', 'array', 'min:1', 'max:10'],
            'actions.*.type' => ['required', Rule::in(app(ActionRegistry::class)->names())],
            'actions.*.config' => ['nullable', 'array'],
        ];
    }

    /**
     * The one thing an admin may not write.
     *
     * `set_role` grants authority, and who is an admin is the owner's alone everywhere else
     * in the app (see ServerOwnerRequest). Without this, an admin could write "when anyone
     * reacts 👑, make them an admin" and hand out their own standing.
     *
     * Checked on the payload rather than by making the whole screen owner-only, so an admin
     * can still write the ordinary rules — which is nearly all of them.
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $server = $this->resolveServer();
            $user = $this->user();

            if ($server === null || $user === null || $server->isOwner($user)) {
                return;
            }

            foreach ((array) $this->input('actions', []) as $index => $action) {
                if (($action['type'] ?? null) === 'set_role') {
                    $validator->errors()->add(
                        "actions.{$index}.type",
                        'Only the server’s owner can create a rule that changes roles.',
                    );
                }
            }
        }];
    }
}
