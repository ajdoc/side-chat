<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\BanUserAction;
use App\Actions\Admin\DeleteUserAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BanUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Requests\Admin\UpdateUserRoleRequest;
use App\Http\Resources\Admin\AdminUserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Accounts, from the instance operator's side of the glass.
 *
 * Three verbs that look similar and aren't. **Edit** changes a name or an address.
 * **Block** is the moderation tool: reversible, and it carries the sentence the person reads
 * when they try to sign in. **Delete** is neither — it cascades through everything they own
 * (see DeleteUserAction) and exists for spam and erasure requests, not for punishment.
 *
 * Two things this controller will not let a super admin do, both guarding against the same
 * accident: act on themselves, and act on another super admin. An instance where the last
 * administrator locked themselves out has no way back in that doesn't involve a database
 * console, so the panel refuses rather than warns.
 */
class AdminUserController extends Controller
{
    /**
     * The user table: search, filter, newest first.
     *
     * Paginated rather than complete — an instance's user list is the one table here that
     * has no ceiling, and the screen scrolls.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $users = User::query()
            ->with('bannedBy:id,name')
            ->withCount(['servers', 'ownedServers', 'messages'])
            ->when($request->string('q')->trim()->value(), function ($query, string $term) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
                $query->where(fn ($q) => $q->where('name', 'ilike', $like)->orWhere('email', 'ilike', $like));
            })
            // The three filters the screen offers as tabs. Anything else is ignored rather
            // than rejected: a stale bookmark should show the list, not an error.
            ->when($request->string('filter')->value() === 'banned', fn ($q) => $q->whereNotNull('banned_at'))
            ->when($request->string('filter')->value() === 'admins', fn ($q) => $q->whereNotNull('role'))
            ->when($request->string('filter')->value() === 'bots', fn ($q) => $q->where('is_bot', true))
            ->latest('id')
            ->paginate(min((int) $request->integer('per_page', 25) ?: 25, 100))
            ->withQueryString();

        return AdminUserResource::collection($users);
    }

    /** One account, with the counts the detail panel shows. */
    public function show(User $user): AdminUserResource
    {
        $user->load('bannedBy:id,name')->loadCount(['servers', 'ownedServers', 'messages']);

        return new AdminUserResource($user);
    }

    /** Rename, re-address, or verify. Nothing here touches standing — that's updateRole. */
    public function update(UpdateUserRequest $request, User $user): AdminUserResource
    {
        $user->fill($request->validated())->save();

        return new AdminUserResource($user->fresh()->loadCount(['servers', 'ownedServers', 'messages']));
    }

    /**
     * Grant or revoke a site role.
     *
     * You cannot change your own — the only way to end up with no super admins at all is to
     * demote the last one, and the last one is always somebody's own account.
     */
    public function updateRole(UpdateUserRoleRequest $request, User $user): AdminUserResource
    {
        $this->refuseSelf($request, $user, 'You cannot change your own role.');
        abort_if($user->is_bot, 422, 'A bot cannot hold a site role.');

        $user->forceFill(['role' => $request->validated('role')])->save();

        return new AdminUserResource($user->fresh()->loadCount(['servers', 'ownedServers', 'messages']));
    }

    /** Block, with the reason the person will be shown. */
    public function ban(BanUserRequest $request, User $user, BanUserAction $action): AdminUserResource
    {
        $this->refuseSelf($request, $user, 'You cannot block yourself.');
        $this->refuseAdmin($user, 'Remove their Super Admin role before blocking them.');

        $banned = $action->handle($user, $request->user(), $request->validated('reason'));

        return new AdminUserResource($banned->load('bannedBy:id,name'));
    }

    /** Lift the block. */
    public function unban(User $user, BanUserAction $action): AdminUserResource
    {
        return new AdminUserResource($action->lift($user));
    }

    /** Delete the account and everything that cascades from it. */
    public function destroy(Request $request, User $user, DeleteUserAction $action): Response
    {
        $this->refuseSelf($request, $user, 'You cannot delete your own account here.');
        $this->refuseAdmin($user, 'Remove their Super Admin role before deleting the account.');

        $action->handle($user);

        return response()->noContent();
    }

    private function refuseSelf(Request $request, User $user, string $message): void
    {
        abort_if($request->user()->is($user), 422, $message);
    }

    private function refuseAdmin(User $user, string $message): void
    {
        abort_if($user->isSuperAdmin(), 422, $message);
    }
}
