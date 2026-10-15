<?php
/**
 * Private, mutually exclusive entry categories and explicit human review.
 *
 * @package EasyFormly
 */

namespace EasyFormly\Review;

use EasyFormly\Plugin;
use EasyFormly\Submissions\State;
use EasyFormly\Submissions\Submission;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Anti-spam categories of archived entries and the human review that overrides them.
 */
final class Review {
	public const TAXONOMY = 'easyformly_safety';

	/**
	 * Category labels, keyed by term slug.
	 *
	 * @return array<string, string>
	 */
	public static function labels(): array {
		return array(
			'accepted' => __( 'Accepted', 'easyformly' ),
			'suspect'  => __( 'Likely spam', 'easyformly' ),
			'spam'     => __( 'Spam', 'easyformly' ),
		);
	}

	/**
	 * Register the private taxonomy.
	 */
	public static function register(): void {
		register_taxonomy(
			self::TAXONOMY,
			Plugin::ENTRY,
			array(
				'public'                => false,
				'publicly_queryable'    => false,
				'show_ui'               => false,
				'show_in_rest'          => false,
				'show_in_nav_menus'     => false,
				'rewrite'               => false,
				'query_var'             => false,
				'hierarchical'          => false,
				'capabilities'          => array_fill_keys( array( 'manage_terms', 'edit_terms', 'delete_terms', 'assign_terms' ), 'manage_options' ),
				'update_count_callback' => '_update_generic_term_count',
			)
		);
	}

	/**
	 * Create the three categories once; called by the plugin setup, never on an ordinary request.
	 *
	 * @return bool Whether every category exists.
	 */
	public static function create_terms(): bool {
		if ( '1' === get_option( 'easyformly_review_terms_version' ) ) {
			return true;
		}
		$complete = true;
		foreach ( self::labels() as $slug => $label ) {
			$term = term_exists( $slug, self::TAXONOMY );
			if ( ! $term ) {
				$term = wp_insert_term( $label, self::TAXONOMY, array( 'slug' => $slug ) );
			}
			$complete = $complete && ! is_wp_error( $term );
		}
		if ( $complete ) {
			update_option( 'easyformly_review_terms_version', '1', false );
		}
		return $complete;
	}

	/**
	 * The entry's single category, or an empty string when it has none or several.
	 *
	 * @param int $id Entry ID.
	 */
	public static function category( int $id ): string {
		$terms = wp_get_object_terms( $id, self::TAXONOMY, array( 'fields' => 'slugs' ) );
		return ! is_wp_error( $terms ) && 1 === count( $terms ) && isset( self::labels()[ $terms[0] ] ) ? $terms[0] : '';
	}

	/**
	 * A manual category change never releases a held delivery. Approval is a separate action.
	 *
	 * @param int $id Entry ID.
	 */
	public static function held( int $id ): bool {
		$value = get_post_meta( $id, '_easyformly_held', true );
		return '' !== $value && '0' !== $value;
	}

	/**
	 * Whether destinations may receive the entry now.
	 *
	 * @param int $id Entry ID.
	 */
	public static function can_deliver( int $id ): bool {
		if ( self::held( $id ) || get_post_meta( $id, '_easyformly_initializing', true ) ) {
			return false;
		}
		$category = self::category( $id );
		// Existing entries have no assessment. Preserve their established recovery behavior.
		return 'accepted' === $category || ( '' === $category && ! get_post_meta( $id, '_easyformly_review', true ) );
	}

	/**
	 * Record the first automatic assessment, exactly once per entry.
	 *
	 * @param int                  $id         Entry ID.
	 * @param array<string, mixed> $assessment Result of AntiSpam::assess().
	 */
	public static function initialize( int $id, array $assessment ): bool {
		if ( ! add_post_meta( $id, '_easyformly_antispam', $assessment, true ) ) {
			return false;
		}
		return self::write( $id, $assessment['status'], 'automatic', 'accepted' !== $assessment['status'] );
	}

	/**
	 * The caller must hold retry_ID, shared with both initial and recovered deliveries.
	 *
	 * @param int    $id       Entry ID.
	 * @param string $category Category slug.
	 * @param string $source   automatic or manual.
	 * @param bool   $held     Whether deliveries stay held after the write.
	 */
	public static function write( int $id, string $category, string $source, bool $held ): bool {
		if ( ! isset( self::labels()[ $category ] ) || Plugin::ENTRY !== get_post_type( $id ) ) {
			return false;
		}
		$previous = get_post_meta( $id, '_easyformly_review', true );
		if ( 'automatic' === $source && is_array( $previous ) && 'manual' === ( $previous['source'] ?? '' ) ) {
			return true;
		}
		$term = term_exists( $category, self::TAXONOMY );
		if ( ! $term ) {
			// A category removed outside the plugin (a migration, a database cleanup) would make every archive fail.
			delete_option( 'easyformly_review_terms_version' );
			self::create_terms();
			$term = term_exists( $category, self::TAXONOMY );
		}
		if ( ! $term || is_wp_error( $term ) ) {
			return false;
		}
		// Fail closed if a partial write occurs: mark the hold before changing the category.
		update_post_meta( $id, '_easyformly_held', '1' );
		if ( '1' !== get_post_meta( $id, '_easyformly_held', true ) ) {
			return false;
		}
		$result = wp_set_object_terms( $id, array( (int) ( is_array( $term ) ? $term['term_id'] : $term ) ), self::TAXONOMY, false );
		if ( is_wp_error( $result ) || self::category( $id ) !== $category ) {
			return false;
		}
		$record = array(
			'status'   => $category,
			'source'   => $source,
			'time'     => gmdate( 'c' ),
			'reviewer' => 'manual' === $source ? get_current_user_id() : 0,
		);
		update_post_meta( $id, '_easyformly_review', $record );
		if ( get_post_meta( $id, '_easyformly_review', true ) !== $record ) {
			return false;
		}
		if ( 'manual' === $source ) {
			$history   = get_post_meta( $id, '_easyformly_review_history', true );
			$history   = is_array( $history ) ? $history : array();
			$history[] = $record;
			update_post_meta( $id, '_easyformly_review_history', array_slice( $history, -10 ) );
		}
		update_post_meta( $id, '_easyformly_held', $held ? '1' : '0' );
		if ( get_post_meta( $id, '_easyformly_held', true ) !== ( $held ? '1' : '0' ) ) {
			return false;
		}
		if ( $held ) {
			Submission::cancel( $id );
		}
		return true;
	}

	/**
	 * A manual category change, serialized with deliveries through the entry's retry lease.
	 *
	 * @param int    $id       Entry ID.
	 * @param string $category Category slug.
	 * @return true|WP_Error
	 */
	public static function change( int $id, string $category ) {
		$lease = 'retry_' . $id;
		$owner = State::claim( $lease );
		if ( null === $owner ) {
			return new WP_Error( 'busy', __( 'This submission is being processed. Try again in a few seconds.', 'easyformly' ) );
		}
		try {
			if ( 'private' !== get_post_status( $id ) || get_post_meta( $id, '_easyformly_initializing', true ) ) {
				return new WP_Error( 'busy', __( 'This submission is not available for review. Try again in a few seconds.', 'easyformly' ) );
			}
			if ( ! self::write( $id, $category, 'manual', 'accepted' !== $category || self::held( $id ) ) ) {
				return new WP_Error( 'save', __( 'The category could not be updated.', 'easyformly' ) );
			}
			return true;
		} finally {
			State::release( $lease, $owner );
		}
	}

	/**
	 * Keep approval available if an interrupted attempt left some destinations held.
	 *
	 * @param int $id Entry ID.
	 */
	public static function requires_approval( int $id ): bool {
		if ( self::held( $id ) || in_array( self::category( $id ), array( 'suspect', 'spam' ), true ) ) {
			return true;
		}
		$status = get_post_meta( $id, '_easyformly_delivery', true );
		return is_array( $status ) && in_array( 'held', (array) ( $status['outcomes'] ?? array() ), true );
	}
}
