<?php
/**
 * Admin screens of the human review: archive list, review box and review actions.
 *
 * @package EasyFormly
 */

namespace EasyFormly\Review\Admin;

use EasyFormly\AntiSpam\AntiSpam;
use EasyFormly\Plugin;
use EasyFormly\Review\Review;
use EasyFormly\Submissions\Submission;
use WP_Error;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Classification column, category views and filter, review box, bulk and single actions.
 */
final class Screen {
	/**
	 * Hooks for the archive list, the review box and the review actions; registered only in admin.
	 */
	public static function boot(): void {
		add_filter( 'manage_' . Plugin::ENTRY . '_posts_columns', array( self::class, 'columns' ) );
		add_action( 'manage_' . Plugin::ENTRY . '_posts_custom_column', array( self::class, 'column' ), 10, 2 );
		add_filter( 'views_edit-' . Plugin::ENTRY, array( self::class, 'views' ) );
		add_action( 'pre_get_posts', array( self::class, 'filter' ) );
		add_action(
			'add_meta_boxes',
			static function (): void {
				add_meta_box( 'easyformly-review', __( 'Anti-spam category', 'easyformly' ), array( self::class, 'box' ), Plugin::ENTRY, 'side', 'high' );
			}
		);
		add_filter( 'bulk_actions-edit-' . Plugin::ENTRY, array( self::class, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-' . Plugin::ENTRY, array( self::class, 'bulk' ), 10, 3 );
		add_action( 'admin_post_easyformly_classify', array( self::class, 'action' ) );
		add_action( 'admin_post_easyformly_approve', array( self::class, 'action' ) );
		add_action( 'admin_notices', array( self::class, 'notice' ) );
	}

	/**
	 * Add the classification column to the archive list.
	 *
	 * @param array<string, string> $columns List table columns.
	 * @return array<string, string>
	 */
	public static function columns( array $columns ): array {
		$columns['easyformly-review'] = __( 'Classification', 'easyformly' );
		return $columns;
	}

	/**
	 * Print the classification column.
	 *
	 * @param string $column Column key.
	 * @param int    $id     Entry ID.
	 */
	public static function column( string $column, int $id ): void {
		if ( 'easyformly-review' !== $column ) {
			return;
		}
		$category = Review::category( $id );
		echo '<span class="easyformly-badge easyformly-badge-' . esc_attr( '' !== $category ? $category : 'unknown' ) . '">' . esc_html( Review::labels()[ $category ] ?? __( 'To be classified', 'easyformly' ) ) . '</span>';
		$record = get_post_meta( $id, '_easyformly_review', true );
		if ( is_array( $record ) ) {
			echo '<br><small>' . esc_html( 'manual' === $record['source'] ? __( 'Manual review', 'easyformly' ) : __( 'Automatic check', 'easyformly' ) ) . '</small>';
		}
		if ( Review::held( $id ) ) {
			echo '<br><small>' . esc_html__( 'Delivery held', 'easyformly' ) . '</small>';
		}
	}

	/**
	 * One view per category, plus entries that predate classification.
	 *
	 * @param array<string, string> $views List table views.
	 * @return array<string, string>
	 */
	public static function views( array $views ): array {
		unset( $views['private'] );
		$current = isset( $_GET['easyformly_review'] ) && is_string( $_GET['easyformly_review'] ) ? sanitize_key( wp_unslash( $_GET['easyformly_review'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only archive filter.
		foreach ( Review::labels() as $slug => $label ) {
			$query                          = new WP_Query(
				array(
					'post_type'      => Plugin::ENTRY,
					'post_status'    => 'private',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'tax_query'      => array(
						array(
							'taxonomy' => Review::TAXONOMY,
							'field'    => 'slug',
							'terms'    => $slug,
						),
					),
				)
			); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Three bounded private archive counts.
			$views[ 'easyformly-' . $slug ] = '<a href="' . esc_url(
				add_query_arg(
					array(
						'post_type'         => Plugin::ENTRY,
						'easyformly_review' => $slug,
					),
					admin_url( 'edit.php' )
				)
			) . '"' . ( $current === $slug ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html( $label ) . ' <span class="count">(' . (int) $query->found_posts . ')</span></a>';
		}
		$unclassified = new WP_Query(
			array(
				'post_type'      => Plugin::ENTRY,
				'post_status'    => 'private',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'tax_query'      => array(
					array(
						'taxonomy' => Review::TAXONOMY,
						'operator' => 'NOT EXISTS',
					),
				),
			)
		); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Private count for entries predating classification.
		if ( $unclassified->found_posts || 'unclassified' === $current ) {
			$views['easyformly-unclassified'] = '<a href="' . esc_url(
				add_query_arg(
					array(
						'post_type'         => Plugin::ENTRY,
						'easyformly_review' => 'unclassified',
					),
					admin_url( 'edit.php' )
				)
			) . '"' . ( 'unclassified' === $current ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html__( 'To be classified', 'easyformly' ) . ' <span class="count">(' . (int) $unclassified->found_posts . ')</span></a>';
		}
		if ( $current && isset( $views['all'] ) ) {
			$views['all'] = str_replace( array( 'class="current"', 'aria-current="page"' ), '', $views['all'] );
		}
		return $views;
	}

	/**
	 * Restrict the archive list to the selected category.
	 *
	 * @param WP_Query $query Admin list query.
	 */
	public static function filter( WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() || Plugin::ENTRY !== $query->get( 'post_type' ) ) {
			return;
		}
		$category = isset( $_GET['easyformly_review'] ) && is_string( $_GET['easyformly_review'] ) ? sanitize_key( wp_unslash( $_GET['easyformly_review'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only archive filter.
		if ( isset( Review::labels()[ $category ] ) || 'unclassified' === $category ) {
			$tax   = $query->get( 'tax_query' );
			$tax   = is_array( $tax ) ? $tax : array();
			$tax[] = 'unclassified' === $category ? array(
				'taxonomy' => Review::TAXONOMY,
				'operator' => 'NOT EXISTS',
			) : array(
				'taxonomy' => Review::TAXONOMY,
				'field'    => 'slug',
				'terms'    => $category,
			);
			$query->set( 'tax_query', $tax );
		}
	}

	/**
	 * Review box on the entry screen.
	 *
	 * @param WP_Post $post Entry.
	 */
	public static function box( WP_Post $post ): void {
		$category = Review::category( $post->ID );
		wp_nonce_field( 'easyformly_review_' . $post->ID, 'easyformly_review_nonce', false );
		echo '<input type="hidden" name="easyformly_review_entry" value="' . esc_attr( (string) $post->ID ) . '">';
		echo '<p><label for="easyformly-review-status">' . esc_html__( 'Category', 'easyformly' ) . '</label><br><select id="easyformly-review-status" name="easyformly_review_status">';
		if ( ! $category ) {
			echo '<option value="">' . esc_html__( 'To be classified', 'easyformly' ) . '</option>';
		}
		foreach ( Review::labels() as $slug => $label ) {
			echo '<option value="' . esc_attr( $slug ) . '" ' . selected( $category, $slug, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></p><p><button class="button" type="submit" name="action" value="easyformly_classify" formaction="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . esc_html__( 'Update category', 'easyformly' ) . '</button></p>';
		$assessment = get_post_meta( $post->ID, '_easyformly_antispam', true );
		if ( is_array( $assessment ) ) {
			/* translators: 1: anti-spam score, 2: protection level. */
			echo '<p>' . esc_html( sprintf( __( 'Score: %1$d/100 · Level %2$d', 'easyformly' ), $assessment['score'], $assessment['level'] ) ) . '</p>';
			if ( ! empty( $assessment['observe'] ) ) {
				/* translators: %s: category detected in observation mode. */
				echo '<p>' . esc_html( sprintf( __( 'Observation mode: detected as %s.', 'easyformly' ), Review::labels()[ $assessment['detected'] ] ) ) . '</p>';
			}
			echo '<ul>';
			foreach ( $assessment['reasons'] as $reason ) {
				if ( isset( AntiSpam::reason_labels()[ $reason ] ) ) {
					echo '<li>' . esc_html( AntiSpam::reason_labels()[ $reason ] ) . '</li>';
				}
			}
			echo '</ul>';
		}
		$record = get_post_meta( $post->ID, '_easyformly_review', true );
		if ( is_array( $record ) && 'manual' === $record['source'] ) {
			echo '<p>' . esc_html__( 'Manual review takes precedence over the automatic check.', 'easyformly' ) . '</p>';
		}
		if ( Review::requires_approval( $post->ID ) ) {
			echo '<p>' . esc_html__( 'Delivery held. Changing the category sends no notifications.', 'easyformly' ) . '</p><p><button class="button button-primary" type="submit" name="action" value="easyformly_approve" formaction="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . esc_html__( 'Approve and send', 'easyformly' ) . '</button></p>';
		}
	}

	/**
	 * One bulk action per category.
	 *
	 * @param array<string, string> $actions List table bulk actions.
	 * @return array<string, string>
	 */
	public static function bulk_actions( array $actions ): array {
		foreach ( Review::labels() as $slug => $label ) {
			/* translators: %s: classification label. */
			$actions[ 'easyformly-classify-' . $slug ] = sprintf( __( 'Mark as %s', 'easyformly' ), $label );
		}
		return $actions;
	}

	/**
	 * Apply a bulk classification to at most one hundred entries.
	 *
	 * @param string            $redirect Redirect URL.
	 * @param string            $action   Bulk action key.
	 * @param array<int|string> $ids      Selected entry IDs.
	 */
	public static function bulk( string $redirect, string $action, array $ids ): string {
		$category = substr( $action, strlen( 'easyformly-classify-' ) );
		if ( ! str_starts_with( $action, 'easyformly-classify-' ) || ! isset( Review::labels()[ $category ] ) ) {
			return $redirect;
		}
		check_admin_referer( 'bulk-posts' );
		$updated = 0;
		if ( current_user_can( 'manage_options' ) ) {
			foreach ( array_map( 'absint', array_slice( $ids, 0, 100 ) ) as $id ) {
				if ( Plugin::ENTRY === get_post_type( $id ) && 'private' === get_post_status( $id ) && ! is_wp_error( Review::change( $id, $category ) ) ) {
					++$updated;
				}
			}
		}
		/* translators: 1: updated entries, 2: selected entries. */
		self::queue_notice( sprintf( __( 'Category updated for %1$d of %2$d selected submissions.', 'easyformly' ), $updated, count( $ids ) ), count( $ids ) !== $updated );
		return $redirect;
	}

	/**
	 * Handle the review box: change the category or approve the held delivery.
	 */
	public static function action(): void {
		$id = isset( $_POST['easyformly_review_entry'] ) && is_scalar( $_POST['easyformly_review_entry'] ) ? absint( $_POST['easyformly_review_entry'] ) : 0;
		if ( ! current_user_can( 'manage_options' ) || ! $id || Plugin::ENTRY !== get_post_type( $id ) || 'private' !== get_post_status( $id ) ) {
			wp_die( esc_html__( 'Submission not available.', 'easyformly' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'easyformly_review_' . $id, 'easyformly_review_nonce' );
		$action = isset( $_POST['action'] ) && is_string( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';
		if ( 'easyformly_approve' === $action ) {
			$result  = Submission::retry( $id, true, false, true );
			$message = __( 'Submission approved. Check the destination outcomes in the details.', 'easyformly' );
		} else {
			$category = isset( $_POST['easyformly_review_status'] ) && is_string( $_POST['easyformly_review_status'] ) ? sanitize_key( wp_unslash( $_POST['easyformly_review_status'] ) ) : '';
			$result   = isset( Review::labels()[ $category ] ) ? Review::change( $id, $category ) : new WP_Error( 'category', __( 'Select a valid category.', 'easyformly' ) );
			$message  = __( 'Category updated.', 'easyformly' );
		}
		self::queue_notice( is_wp_error( $result ) ? $result->get_error_message() : $message, is_wp_error( $result ) );
		wp_safe_redirect( admin_url( 'post.php?post=' . $id . '&action=edit' ) );
		exit;
	}

	/**
	 * Keep the outcome for the next admin screen of the same user.
	 *
	 * @param string $message Notice text.
	 * @param bool   $error   Whether the notice reports a failure.
	 */
	private static function queue_notice( string $message, bool $error ): void {
		set_transient(
			'easyformly_review_notice_' . get_current_user_id(),
			array(
				'message' => $message,
				'error'   => $error,
			),
			90
		);
	}

	/**
	 * Print and clear the queued outcome.
	 */
	public static function notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$key    = 'easyformly_review_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( is_array( $notice ) ) {
			echo '<div class="notice notice-' . ( $notice['error'] ? 'error' : 'success' ) . '"><p>' . esc_html( $notice['message'] ) . '</p></div>';
			delete_transient( $key );
		}
	}
}
