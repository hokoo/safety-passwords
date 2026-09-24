<?php

namespace iTRON\SafetyPasswords;

/** Select accounts governed by the current site's password policy. */
class UserScope {
	/**
	 * Accept supplied IDs so callers can filter a bounded batch without querying all users.
	 *
	 * @param array|null $candidates User IDs, or null to query all candidates.
	 * @return array User IDs in their original order and type.
	 */
	public static function userIds( ?array $candidates = null ): array {
		if ( null === $candidates ) {
			$candidates = get_users( [ 'fields' => 'ids', 'blog_id' => is_multisite() ? 0 : get_current_blog_id() ] );
		}

		// A single network owns the global user table, including unassigned accounts.
		if ( ! is_multisite() || count( get_networks( [ 'number' => 2, 'fields' => 'ids' ] ) ) < 2 ) {
			return $candidates;
		}

		$network_id = get_current_network_id();
		$users = [];
		foreach ( $candidates as $user_id ) {
			// Include memberships on inactive sites; WordPress omits them by default.
			foreach ( get_blogs_of_user( $user_id, true ) as $site ) {
				if ( (int) $site->site_id === $network_id ) {
					$users[] = $user_id;
					break;
				}
			}
		}

		return $users;
	}
}
