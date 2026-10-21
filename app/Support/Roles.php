<?php

namespace App\Support;

use App\Models\User;

/**
 * Staff roles and which parts of the admin each one opens. Admins can do everything.
 */
class Roles
{
    public const ROLES = [
        'moderator' => 'Moderator: the queue, appeals, people, spam',
        'editor' => 'Editor: content, the quiz, moments, the house bots',
        'sales' => 'Sales: sponsorships, ads and places',
        'admin' => 'Admin: everything, plus maintenance and roles',
    ];

    /** Web admin pages by route name. Missing means any staff role. */
    public const PAGES = [
        'admin.users' => 'moderator', 'moderation' => 'moderator', 'admin.spam' => 'moderator', 'admin.moderation-insights' => 'moderator',
        'admin.content' => 'editor', 'admin.quiz' => 'editor', 'admin.moments' => 'editor', 'admin.bots' => 'editor', 'admin.magazine' => 'editor', 'admin.history' => 'editor', 'admin.editorial' => 'editor', 'admin.anc' => 'editor', 'admin.tips' => 'editor', 'admin.radar' => 'editor', 'admin.callouts' => 'editor', 'admin.archive' => 'editor', 'admin.issues' => 'editor', 'admin.hunts' => 'editor', 'admin.readers-picks' => 'editor',
        'admin.challenges' => 'editor', 'admin.challenges.csv' => 'editor', 'admin.magazine-events' => 'editor',
        'admin.places' => 'sales', 'admin.sponsorships' => 'sales', 'admin.real-estate' => 'sales', 'admin.insights' => 'sales', 'admin.insights.csv' => 'sales', 'admin.insights.print' => 'sales', 'admin.ads' => 'sales', 'admin.ads.csv' => 'sales', 'admin.ads.report' => 'sales',
        'admin.business' => 'sales',
        'admin.roles' => 'admin',
    ];

    /** Admin API paths (after /api/admin/) by prefix. Missing means any staff role. */
    public const API = [
        'queue' => 'moderator', 'dispatches/' => 'moderator', 'appeals' => 'moderator', 'message-reports' => 'moderator',
        'penalties' => 'moderator', 'users' => 'moderator', 'spam-clusters' => 'moderator', 'ai/' => 'moderator', 'sentinel' => 'moderator',
        'bots/' => 'editor', 'radar' => 'editor', 'tips' => 'editor', 'editors' => 'editor', 'callouts' => 'editor',
        'ads' => 'sales',
        'health' => 'admin', 'maintenance/' => 'admin', 'log' => 'admin', 'failed-jobs' => 'admin', 'roles' => 'admin',
    ];

    public static function forApiPath(string $path): ?string
    {
        foreach (self::API as $prefix => $role) {
            if (str_starts_with($path, $prefix)) {
                return $role;
            }
        }

        return null;
    }

    /**
     * Give someone exactly these roles. No roles means no staff access at all.
     *
     * @param  array<int, string>  $roles
     */
    public static function set(User $user, array $roles, User $by): void
    {
        $roles = array_values(array_intersect(array_keys(self::ROLES), $roles));
        abort_if($user->is($by) && ! in_array('admin', $roles, true), 422, 'You can’t take away your own admin role.');
        abort_if($user->is_bot, 422);

        $user->forceFill(['roles' => $roles ?: null, 'is_staff' => (bool) $roles])->save();
        Audit::log('user.roles_set', $user, ['roles' => $roles]);
    }
}
