<?php
/**
 * Finds and removes everything the load generator created, based solely on the
 * `_rsvp_loadgen_generated` marker meta — never touches untagged (real) content.
 */

namespace RSVP_Loadgen;

class Cleanup {

	/**
	 * Post types deleted by cleanup, in deletion order (children before parents).
	 *
	 * @var string[]
	 */
	const POST_TYPES_IN_DELETE_ORDER = [ 'tribe_rsvp_attendees', 'tribe_rsvp_tickets', 'tribe_events', 'page', 'post', 'tribe_venue', 'tribe_organizer' ];

	/**
	 * Deletes up to $limit generated records this call, processing post types in
	 * order (attendees, then tickets, then container posts) so a repeated
	 * "keep calling" loop naturally goes leaf-to-root.
	 *
	 * @return int Number of posts actually deleted this call.
	 */
	public function cleanup_batch( int $limit, ?string $run_id = null ): int {
		$removed = 0;

		foreach ( self::POST_TYPES_IN_DELETE_ORDER as $post_type ) {
			if ( $removed >= $limit ) {
				break;
			}

			$ids = $this->query_ids( $post_type, $limit - $removed, $run_id );

			foreach ( $ids as $id ) {
				if ( wp_delete_post( $id, true ) ) {
					$removed++;
				}
			}
		}

		if ( $removed < $limit ) {
			$ids = $this->query_ids( 'any', $limit - $removed, $run_id, self::POST_TYPES_IN_DELETE_ORDER );

			foreach ( $ids as $id ) {
				if ( wp_delete_post( $id, true ) ) {
					$removed++;
				}
			}
		}

		return $removed;
	}

	/**
	 * Force-deletes exactly the given container posts (Events/Pages/Posts), leaving any RSVP
	 * tickets/attendees generated under them in place — they're already tagged, so they remain
	 * reachable by a later `cleanup_batch()`, but now point at a post ID that no longer exists.
	 * Simulates the "event deleted, RSVP data left behind" edge case.
	 *
	 * Only ever deletes IDs that still carry the generated-marker meta, as a safety net against
	 * being handed the wrong list — this never queries for posts to delete, only acts on the
	 * exact IDs passed in.
	 *
	 * @param int[] $post_ids
	 *
	 * @return int Number of posts actually deleted.
	 */
	public function orphan_posts( array $post_ids ): int {
		$removed = 0;

		foreach ( $post_ids as $post_id ) {
			if ( ! get_post_meta( $post_id, Data::GENERATED_META_KEY, true ) ) {
				continue;
			}

			if ( wp_delete_post( $post_id, true ) ) {
				$removed++;
			}
		}

		return $removed;
	}

	/**
	 * Total number of generated records left to clean up, across all types.
	 */
	public function count_all( ?string $run_id = null ): int {
		$total = 0;

		foreach ( self::POST_TYPES_IN_DELETE_ORDER as $post_type ) {
			$total += $this->count_type( $post_type, $run_id );
		}

		$total += $this->count_type( 'any', $run_id, self::POST_TYPES_IN_DELETE_ORDER );

		return $total;
	}

	/**
	 * Counts of generated records by human-readable kind, for the admin stats block.
	 *
	 * @return array<string,int>
	 */
	public function count_by_type( ?string $run_id = null ): array {
		$labels = [
			'tribe_events'         => 'event',
			'page'                 => 'page',
			'post'                 => 'post',
			'tribe_rsvp_tickets'   => 'ticket',
			'tribe_rsvp_attendees' => 'attendee',
			'tribe_venue'          => 'venue',
			'tribe_organizer'      => 'organizer',
		];

		$out = [];

		foreach ( $labels as $post_type => $label ) {
			$out[ $label ] = $this->count_type( $post_type, $run_id );
		}

		return $out;
	}

	/**
	 * @param string[] $exclude_post_types When $post_type is 'any', post types to exclude (the
	 *                                     ones already covered by their own explicit pass).
	 *
	 * @return int[] Post IDs.
	 */
	private function query_ids( string $post_type, int $limit, ?string $run_id, array $exclude_post_types = [] ): array {
		$args = [
			'post_type'        => $post_type,
			'post_status'      => 'any',
			'posts_per_page'   => $limit,
			'fields'           => 'ids',
			'meta_query'       => $this->meta_query( $run_id ),
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'no_found_rows'    => true,
			'suppress_filters' => true,
		];

		if ( $exclude_post_types ) {
			// `$post_type` is already 'any' for the catch-all pass; this just narrows it to
			// "any post type except the ones already covered by their own explicit pass above".
			$args['post_type__not_in'] = $exclude_post_types;
		}

		return get_posts( $args );
	}

	private function count_type( string $post_type, ?string $run_id, array $exclude_post_types = [] ): int {
		$args = [
			'post_type'        => $post_type,
			'post_status'      => 'any',
			'posts_per_page'   => 1,
			'fields'           => 'ids',
			'meta_query'       => $this->meta_query( $run_id ),
			'suppress_filters' => true,
		];

		if ( $exclude_post_types ) {
			$args['post_type__not_in'] = $exclude_post_types;
		}

		$query = new \WP_Query( $args );

		return (int) $query->found_posts;
	}

	private function meta_query( ?string $run_id ): array {
		$meta_query = [
			[
				'key'   => Data::GENERATED_META_KEY,
				'value' => '1',
			],
		];

		if ( $run_id ) {
			$meta_query[] = [
				'key'   => Data::RUN_ID_META_KEY,
				'value' => $run_id,
			];
		}

		return $meta_query;
	}
}
