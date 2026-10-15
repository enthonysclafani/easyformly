<?php
/**
 * Entry list and detail screen.
 *
 * @package EasyFormly
 */

namespace EasyFormly\Submissions\Admin;

use EasyFormly\Delivery\Admin\Outcomes;
use EasyFormly\Plugin;
use EasyFormly\Review\Review;
use EasyFormly\Submissions\Submission;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Show archived entries read-only, with their delivery outcomes and the manual retry.
 */
final class EntryScreen {
	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_action( 'add_meta_boxes', array( self::class, 'boxes' ) );
		add_action( 'admin_post_easyformly_retry', array( self::class, 'retry' ) );
		add_filter( 'manage_' . Plugin::ENTRY . '_posts_columns', array( self::class, 'columns' ) );
		add_action( 'manage_' . Plugin::ENTRY . '_posts_custom_column', array( self::class, 'column' ), 10, 2 );
		add_filter(
			'bulk_actions-edit-' . Plugin::ENTRY,
			static function ( $actions ) {
				unset( $actions['edit'] );
				return $actions;
			}
		);
	}

	/**
	 * Register the entry metabox.
	 *
	 * @return void
	 */
	public static function boxes(): void {
		add_meta_box( 'easyformly-entry-data', __( 'Submission data', 'easyformly' ), array( self::class, 'entry_box' ), Plugin::ENTRY, 'normal', 'high' );
		remove_meta_box( 'submitdiv', Plugin::ENTRY, 'side' );
	}

	/**
	 * Add the delivery status column to the entry list.
	 *
	 * @param array<string, string> $columns Native list-table columns.
	 * @return array<string, string> Result of this operation.
	 */
	public static function columns( array $columns ): array {
		$columns['easyformly-delivery'] = __( 'Submission outcome', 'easyformly' );
		return $columns;
	}

	/**
	 * Render a native list-table column.
	 *
	 * @param string $column Current list-table column name.
	 * @param int    $id Native WordPress post ID.
	 * @return void
	 */
	public static function column( string $column, int $id ): void {
		if ( 'easyformly-delivery' === $column ) {
			$status = get_post_meta( $id, '_easyformly_delivery', true );
			if ( is_array( $status ) ) {
				Outcomes::render( $status['outcomes'] );
			}
		}
	}

	/**
	 * Render escaped private archive values and recovery actions.
	 *
	 * @param WP_Post $post Native WordPress post.
	 * @return void
	 */
	public static function entry_box( WP_Post $post ): void {
		$values = (array) get_post_meta( $post->ID, '_easyformly_values', true );
		$labels = (array) get_post_meta( $post->ID, '_easyformly_labels', true );
		echo '<p>' . esc_html( $post->post_title ) . '</p><table class="widefat striped"><tbody>';
		foreach ( $values as $name => $value ) {
			echo '<tr><th>' . esc_html( $labels[ $name ] ?? $name ) . '</th><td class="easyformly-value">' . esc_html( (string) $value ) . '</td></tr>';
		}
		echo '</tbody></table>';
		$status = get_post_meta( $post->ID, '_easyformly_delivery', true );
		if ( is_array( $status ) ) {
			Outcomes::render( $status['outcomes'] );
			$retry = false;
			foreach ( $status['outcomes'] as $adapter => $outcome ) {
				$retry = $retry || ( 'archive' !== $adapter && ! in_array( $outcome, array( 'sent', 'skipped', 'disabled', 'held' ), true ) );
			}
			if ( wp_next_scheduled( 'easyformly_retry_delivery', array( $post->ID ) ) ) {
				echo '<p>' . esc_html__( 'A new attempt is scheduled for destinations that are temporarily unavailable.', 'easyformly' ) . '</p>';
			}
			if ( $retry && 'private' === $post->post_status && Review::can_deliver( $post->ID ) ) {
				wp_nonce_field( 'easyformly_retry_' . $post->ID, 'easyformly_retry_nonce', false );
				echo '<input type="hidden" name="easyformly_retry_entry" value="' . esc_attr( (string) $post->ID ) . '">';
				echo '<p><label><input type="checkbox" name="easyformly_retry_confirm" value="1"> ' . esc_html__( 'I have checked the destinations with unconfirmed outcomes and want to repeat these deliveries too.', 'easyformly' ) . '</label></p>';
				echo '<p><button type="submit" class="button" name="action" value="easyformly_retry" formaction="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . esc_html__( 'Retry failed destinations', 'easyformly' ) . '</button></p><p class="description">' . esc_html__( 'Uses the form\'s current configuration. Deliveries already accepted are kept. Without the check selected, unconfirmed outcomes stay held.', 'easyformly' ) . '</p>';
			}
		}
		$url = get_delete_post_link( $post->ID, '', true );
		if ( $url ) {
			echo '<p><a class="submitdelete deletion" href="' . esc_url( $url ) . '">' . esc_html__( 'Permanently delete this submission', 'easyformly' ) . '</a></p>';
		}
	}

	/**
	 * Run an explicit administrator retry from a private archived entry.
	 *
	 * @return void
	 */
	public static function retry(): void {
		$id = isset( $_POST['easyformly_retry_entry'] ) && is_scalar( $_POST['easyformly_retry_entry'] ) ? absint( $_POST['easyformly_retry_entry'] ) : 0;
		if ( ! current_user_can( 'manage_options' ) || ! $id || Plugin::ENTRY !== get_post_type( $id ) || 'private' !== get_post_status( $id ) ) {
			wp_die( esc_html__( 'Submission not available.', 'easyformly' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'easyformly_retry_' . $id, 'easyformly_retry_nonce' );
		$confirmed = isset( $_POST['easyformly_retry_confirm'] ) && '1' === $_POST['easyformly_retry_confirm'];
		Submission::retry( $id, true, $confirmed );
		wp_safe_redirect( admin_url( 'post.php?post=' . $id . '&action=edit' ) );
		exit;
	}
}
