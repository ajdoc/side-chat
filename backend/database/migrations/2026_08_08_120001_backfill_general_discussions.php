<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give every existing channel a "General" discussion and move the channel's contents onto it.
 *
 * After this runs, no top-level channel owns a message, a map, a desk or a participant —
 * containers hold identity and access, children hold everything else. That uniformity is the
 * point: without it, every list, breadcrumb, unread count and permission check downstream
 * would have to keep asking "is this row a container or a conversation?", forever.
 *
 * `down()` is deliberately not the inverse. Folding children back into their parents is only
 * lossless while each container still has exactly one child, and by the time anyone rolls
 * this back that will not be true — so it undoes the backfill for single-child containers and
 * refuses rather than silently merging several discussions' history into one timeline.
 */
return new class extends Migration
{
    /**
     * Every table keyed by `channel_id` that describes a *conversation* rather than a channel's
     * identity. `channel_user` is the deliberate omission: the allow-list is access, access
     * stays on the container, and a private channel must keep hiding its whole subtree.
     */
    private const CONTENT_TABLES = [
        'messages',
        'threads',
        'side_chats',
        'side_chat_forums',
        'widgets',
        'whiteboard_strokes',
        'space_notes',
        'canvas_items',
        'calendar_events',
        'space_documents',
        'channel_reads',
        'voice_participants',
        'voice_effect_assignments',
        'side_space_maps',
        'space_games',
        'giveaways',
    ];

    /**
     * Columns elsewhere that name a channel somebody *posts into*. They have to follow the
     * timeline onto the child, or every bot in every server starts posting into a container
     * that has no timeline to receive it.
     */
    private const POINTER_COLUMNS = [
        ['bot_settings', 'mod_log_channel_id'],
        ['bot_settings', 'announcement_channel_id'],
        ['bot_settings', 'reminder_channel_id'],
        ['bot_schedules', 'channel_id'],
    ];

    public function up(): void
    {
        DB::table('channels')->whereNull('parent_id')->orderBy('id')
            ->each(function (object $channel) {
                $childId = DB::table('channels')->insertGetId([
                    'server_id' => $channel->server_id,
                    'conversation_id' => $channel->conversation_id,
                    'parent_id' => $channel->id,
                    'name' => 'General',
                    'type' => $channel->type,
                    'position' => 0,
                    // The desk's tab strip describes the conversation, so it travels with it.
                    'desk_apps' => $channel->desk_apps,
                    // Entrance/exit effects belong to the call, and the call is now the child's.
                    'join_effect' => $channel->join_effect,
                    'leave_effect' => $channel->leave_effect,
                    // Privacy stays on the container, which hides the subtree. A child that
                    // copied it would be a second lock quietly drifting from the first.
                    'is_private' => false,
                    'created_at' => $channel->created_at,
                    'updated_at' => now(),
                ]);

                foreach (self::CONTENT_TABLES as $table) {
                    DB::table($table)->where('channel_id', $channel->id)->update(['channel_id' => $childId]);
                }

                foreach (self::POINTER_COLUMNS as [$table, $column]) {
                    DB::table($table)->where($column, $channel->id)->update([$column => $childId]);
                }

                // Cleared rather than left behind, so a container never looks like it has a desk
                // or a call of its own for some later reader to believe.
                DB::table('channels')->where('id', $channel->id)->update([
                    'desk_apps' => null,
                    'join_effect' => null,
                    'leave_effect' => null,
                ]);
            });
    }

    public function down(): void
    {
        $crowded = DB::table('channels')->whereNotNull('parent_id')
            ->select('parent_id')->groupBy('parent_id')
            ->havingRaw('count(*) > 1')->exists();

        if ($crowded) {
            throw new RuntimeException(
                'Cannot roll back: some channels have more than one discussion, and merging '
                .'them would splice separate timelines together. Delete the extra discussions first.'
            );
        }

        DB::table('channels')->whereNotNull('parent_id')->orderBy('id')
            ->each(function (object $child) {
                foreach (self::CONTENT_TABLES as $table) {
                    DB::table($table)->where('channel_id', $child->id)->update(['channel_id' => $child->parent_id]);
                }

                foreach (self::POINTER_COLUMNS as [$table, $column]) {
                    DB::table($table)->where($column, $child->id)->update([$column => $child->parent_id]);
                }

                DB::table('channels')->where('id', $child->parent_id)->update([
                    'desk_apps' => $child->desk_apps,
                    'join_effect' => $child->join_effect,
                    'leave_effect' => $child->leave_effect,
                ]);

                DB::table('channels')->where('id', $child->id)->delete();
            });
    }
};
