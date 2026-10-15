<?php
/**
 * WordPress personal-data tools and disclosure.
 *
 * @package EasyFormly
 */

namespace EasyFormly\Privacy;

use EasyFormly\AntiSpam\AntiSpam;
use EasyFormly\Plugin;
use EasyFormly\Review\Review;
use EasyFormly\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Integrate local archives with WordPress personal data tools.
 */
final class Privacy {
	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'erasers' ) );
		add_action( 'admin_init', array( self::class, 'policy' ) );
	}

	/**
	 * Register the native personal data exporter.
	 *
	 * @param array<string, array<string, mixed>> $items Registered WordPress privacy callbacks.
	 * @return array<string, array<string, mixed>> Result of this operation.
	 */
	public static function exporters( array $items ): array {
		$items['easyformly'] = array(
			'exporter_friendly_name' => 'EasyFormly',
			'callback'               => array( self::class, 'export' ),
		);
		return $items;
	}

	/**
	 * Register the native personal data eraser.
	 *
	 * @param array<string, array<string, mixed>> $items Registered WordPress privacy callbacks.
	 * @return array<string, array<string, mixed>> Result of this operation.
	 */
	public static function erasers( array $items ): array {
		$items['easyformly'] = array(
			'eraser_friendly_name' => 'EasyFormly',
			'callback'             => array( self::class, 'erase' ),
		);
		return $items;
	}

	/**
	 * Find a bounded batch of private entries matching an email identity.
	 *
	 * @param string $email Email identity supplied by WordPress privacy tools.
	 * @param int    $page One-based privacy batch page.
	 * @return array<int, \WP_Post> Result of this operation.
	 */
	private static function entries( string $email, int $page ): array {
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Core privacy tools require email matching; native metadata is queried in batches of 50.
		return get_posts(
			array(
				'post_type'      => Plugin::ENTRY,
				'post_status'    => array( 'private', 'trash', 'publish', 'draft', 'pending', 'future' ),
				'posts_per_page' => 50,
				'paged'          => max( 1, $page ),
				'orderby'        => 'ID',
				'order'          => 'ASC',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Bounded native metadata matching for the WordPress personal data tools; no custom tables.
				'meta_key'       => '_easyformly_email',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Bounded native metadata matching for the WordPress personal data tools; no custom tables.
				'meta_value'     => strtolower( $email ),
			)
		);
	}

	/**
	 * Export a page of archived data through WordPress privacy tools.
	 *
	 * @param string $email Email identity supplied by WordPress privacy tools.
	 * @param int    $page One-based privacy batch page.
	 * @return array{data: list<array{group_id: string, group_label: string, item_id: string, data: list<array{name: string, value: string}>}>, done: bool} Result of this operation.
	 */
	public static function export( string $email, int $page = 1 ): array {
		$posts = is_email( $email ) ? self::entries( $email, $page ) : array();
		$data  = array();
		foreach ( $posts as $post ) {
			$values = (array) get_post_meta( $post->ID, '_easyformly_values', true );
			$labels = (array) get_post_meta( $post->ID, '_easyformly_labels', true );
			$fields = array(
				array(
					'name'  => __( 'Submission date', 'easyformly' ),
					'value' => $post->post_date_gmt . ' UTC',
				),
			);
			foreach ( $values as $name => $value ) {
				$fields[] = array(
					'name'  => $labels[ $name ] ?? $name,
					'value' => (string) $value,
				);
			}
			$category = Review::category( $post->ID );
			if ( $category ) {
				$fields[] = array(
					'name'  => __( 'Anti-spam category', 'easyformly' ),
					'value' => Review::labels()[ $category ],
				);
			}
			$assessment = get_post_meta( $post->ID, '_easyformly_antispam', true );
			if ( is_array( $assessment ) ) {
				$fields[] = array(
					'name'  => __( 'Anti-spam score', 'easyformly' ),
					'value' => (string) $assessment['score'] . '/100',
				);
				$reasons  = array_intersect_key( AntiSpam::reason_labels(), array_flip( $assessment['reasons'] ) );
				$fields[] = array(
					'name'  => __( 'Check reasons', 'easyformly' ),
					'value' => implode( '; ', $reasons ),
				);
			}
			$data[] = array(
				'group_id'    => 'easyformly',
				'group_label' => __( 'Form submissions', 'easyformly' ),
				'item_id'     => 'easyformly-entry-' . $post->ID,
				'data'        => $fields,
			);
		}
		return array(
			'data' => $data,
			'done' => count( $posts ) < 50,
		);
	}

	/**
	 * Erase a page without skipping records as the result set shrinks.
	 *
	 * @param string $email Email identity supplied by WordPress privacy tools.
	 * @param int    $page One-based privacy batch page.
	 * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool} Result of this operation.
	 */
	public static function erase( string $email, int $page = 1 ): array {
		$allowed = (bool) Settings::get()['allow_erasure'];
		// Deletions shrink the result set: always consume the first batch, never skip with an offset.
		$posts    = is_email( $email ) ? self::entries( $email, $allowed ? 1 : $page ) : array();
		$removed  = false;
		$retained = false;
		foreach ( $posts as $post ) {
			if ( $allowed && wp_delete_post( $post->ID, true ) ) {
				$removed = true;
			} else {
				$retained = true;
			}
		}
		$messages = array();
		if ( $retained ) {
			$messages[] = __( 'Some submissions were kept according to the site settings or could not be deleted.', 'easyformly' );
		}
		if ( $removed && 1 === $page ) {
			$messages[] = __( 'The local copies were deleted. Any copies in email, MailerLite and Slack must be managed in those services.', 'easyformly' );
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $messages,
			'done'           => count( $posts ) < 50 || ( $allowed && $retained ),
		);
	}

	/**
	 * Provide editable privacy policy guidance in WordPress.
	 *
	 * @return void
	 */
	public static function policy(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text  = __( 'Forms send the submitted data to the configured email recipients. When enabled, the local archive keeps the fields and the submission date for the period set by the administrator. The WordPress export and erasure tools can manage these copies through the email addresses in the submission. The plugin does not store IP addresses or user agents in submissions; it temporarily uses a fingerprint of the IP, not reversible without the site key, to limit repeated submissions. When enabled for the form, MailerLite receives the subscriber\'s email, name and group after consent is selected; Slack receives only the fields chosen by the administrator; Cloudflare Turnstile verifies the CAPTCHA and may process browser data. Local erasure does not delete copies already sent to external services. Adapt this text to the site\'s actual configuration and to the policies of the services in use.', 'easyformly' );
		$text .= "\n\n" . __( 'Anti-spam checks use local rules, without artificial intelligence. Temporary counters with fingerprints protected by the site key measure how often emails, phone numbers and similar messages occur, for up to 30 minutes. Quarantine, when allowed by the archive or turned on separately, keeps suspicious submissions, their score, reasons and manual review for the configured period. Held submissions are not delivered to recipients before approval. The email DNS check is optional: it asks the server\'s resolver about the domain only and keeps the result for up to 15 minutes; it does not check that the individual mailbox exists. International phone metadata and a copy of the disposable domain list are included in the plugin. The administrator can synchronize the list manually by downloading the public lists from GitHub, without sending email addresses or submission content. Disposable domain checks always use the local copy.', 'easyformly' );
		wp_add_privacy_policy_content( 'EasyFormly', wpautop( esc_html( $text ) ) );
	}
}
