<?php
/**
 * Finds and removes everything the load generator created, based solely on the
 * `_tec_data_generator_generated` marker meta — never touches untagged (real) content.
 */

namespace TEC\DataGenerator;

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
			// WP_Query's post_type => 'any' silently excludes post types registered with
			// exclude_from_search = true (e.g. ticket CPTs like tec_tc_ticket) — enumerate every
			// registered post type explicitly instead, so paid tickets of any provider are found.
			$catch_all_types = array_values( array_diff( get_post_types( [], 'names' ), self::POST_TYPES_IN_DELETE_ORDER ) );
			$ids             = $this->query_ids( $catch_all_types, $limit - $removed, $run_id );

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

		// WP_Query's post_type => 'any' silently excludes post types registered with
		// exclude_from_search = true (e.g. ticket CPTs like tec_tc_ticket) — enumerate every
		// registered post type explicitly instead, so paid tickets of any provider are counted.
		$catch_all_types = array_values( array_diff( get_post_types( [], 'names' ), self::POST_TYPES_IN_DELETE_ORDER ) );
		$total          += $this->count_type( $catch_all_types, $run_id );

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
	 * @param string|string[] $post_type
	 *
	 * @return int[] Post IDs.
	 */
	private function query_ids( $post_type, int $limit, ?string $run_id ): array {
		return get_posts( [
			'post_type'        => $post_type,
			'post_status'      => 'any',
			'posts_per_page'   => $limit,
			'fields'           => 'ids',
			'meta_query'       => $this->meta_query( $run_id ),
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'no_found_rows'    => true,
			'suppress_filters' => true,
			// Keep TEC Custom Tables from swapping tribe_events results for provisional
			// occurrence IDs (no wp_posts row, so wp_delete_post() fails on them forever).
			'tec_events_ignore' => true,
		] );
	}

	/**
	 * @param string|string[] $post_type
	 */
	private function count_type( $post_type, ?string $run_id ): int {
		$query = new \WP_Query( [
			'post_type'        => $post_type,
			'post_status'      => 'any',
			'posts_per_page'   => 1,
			'fields'           => 'ids',
			'meta_query'       => $this->meta_query( $run_id ),
			'suppress_filters' => true,
			// Same as query_ids(): count real rows, not provisional occurrence IDs.
			'tec_events_ignore' => true,
		] );

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
