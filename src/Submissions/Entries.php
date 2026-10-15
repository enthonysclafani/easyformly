<?php
/**
 * Archived entries: storage preferences, storage and retention.
 *
 * @package EasyFormly
 */

namespace EasyFormly\Submissions;

use EasyFormly\AntiSpam\AntiSpam;
use EasyFormly\Plugin;
use EasyFormly\Review\Review;
use EasyFormly\Settings\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Store accepted and quarantined entries with all their metadata, or none of it, and delete them after retention.
 */
final class Entries {
	/**
	 * Resolve the explicit per-form archive preference.
	 *
	 * @param array<string, mixed> $config Validated delivery and privacy configuration.
	 * @return bool Result of this operation.
	 */
	public static function stores_entries( array $config ): bool {
		return 'inherit' === $config['store_entries'] ? (bool) Settings::get()['store_entries'] : 'yes' === $config['store_entries'];
	}

	/**
	 * Suspicious entries are saved only when an archive preference explicitly permits it.
	 *
	 * @param array<string, mixed> $config Validated delivery and privacy configuration.
	 */
	public static function stores_quarantine( array $config ): bool {
		return self::stores_entries( $config ) || AntiSpam::enabled( $config, 'quarantine_entries' );
	}

	/**
	 * Persist all archived metadata or roll back an incomplete entry.
	 *
	 * @param int                   $form_id Published form ID.
	 * @param array<string, mixed>  $definition Validated form definition.
	 * @param array<string, string> $values Sanitized visible field values.
	 * @param array<string, mixed>  $assessment Result of AntiSpam::assess(); empty for entries saved without one.
	 * @return int|WP_Error Result of this operation.
	 */
	public static function store( int $form_id, array $definition, array $values, array $assessment = array() ) {
		$id = wp_insert_post(
			array(
				'post_type'    => Plugin::ENTRY,
				'post_status'  => 'private',
				'post_title'   => wp_slash( get_the_title( $form_id ) . ' · ' . current_time( 'mysql' ) ),
				'post_content' => '',
				'post_author'  => 0,
				'meta_input'   => array( '_easyformly_initializing' => '1' ),
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$labels = array();
		$emails = array();
		foreach ( $values as $name => $value ) {
			$field           = $definition['schema']['fields'][ $name ];
			$labels[ $name ] = $field['label'];
			if ( 'email' === $field['type'] && '' !== $value ) {
				$emails[] = strtolower( $value );
			}
		}
		foreach ( array(
			'_easyformly_form_id' => $form_id,
			'_easyformly_values'  => $values,
			'_easyformly_labels'  => $labels,
		) as $key => $value ) {
			if ( ! add_post_meta( $id, $key, wp_slash( $value ), true ) ) {
				wp_delete_post( $id, true );
				return new WP_Error( 'archive_failed', 'archive_failed' );
			}
		}
		foreach ( array_unique( $emails ) as $email ) {
			if ( ! add_post_meta( $id, '_easyformly_email', wp_slash( $email ) ) ) {
				wp_delete_post( $id, true );
				return new WP_Error( 'archive_failed', 'archive_failed' );
			}
		}
		if ( $assessment && ! Review::initialize( $id, $assessment ) ) {
			wp_delete_post( $id, true );
			return new WP_Error( 'archive_failed', 'archive_failed' );
		}
		delete_post_meta( $id, '_easyformly_initializing' );
		return $id;
	}

	/**
	 * Delete expired entries in bounded batches.
	 *
	 * Called by Maintenance, never by WP-Cron: a run stops after two batches per retention class or two seconds.
	 *
	 * @return bool Whether expired entries may remain for the next run.
	 */
	public static function cleanup(): bool {
		$settings = Settings::get();
		$started  = microtime( true );
		$pending  = false;
		foreach ( array(
			'IN'     => 'spam_retention_days',
			'NOT IN' => 'retention_days',
		) as $operator => $setting ) {
			$days = (int) $settings[ $setting ];
			if ( $days < 1 ) {
				continue;
			}
			for ( $batch = 0; $batch < 2; ++$batch ) {
				if ( microtime( true ) - $started > 2 ) {
					$pending = true;
					break;
				}
				$ids     = get_posts(
					array(
						'post_type'      => Plugin::ENTRY,
						'post_status'    => array( 'private', 'trash', 'publish', 'draft', 'pending', 'future', 'auto-draft' ),
						'posts_per_page' => 100,
						'fields'         => 'ids',
						'orderby'        => 'ID',
						'order'          => 'ASC',
						'date_query'     => array(
							array(
								'column'    => 'post_date_gmt',
								'before'    => gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ),
								'inclusive' => false,
							),
						),
						'tax_query'      => array(
							array(
								'taxonomy' => Review::TAXONOMY,
								'field'    => 'slug',
								'terms'    => array( 'suspect', 'spam' ),
								'operator' => $operator,
							),
						),
					)
				); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Separate bounded retention for quarantine and ordinary entries.
				$removed = 0;
				foreach ( $ids as $id ) {
					$lease = 'retry_' . $id;
					$owner = State::claim( $lease );
					if ( null === $owner ) {
						$pending = true;
						continue;
					}
					try {
						$removed += wp_delete_post( $id, true ) ? 1 : 0;
					} finally {
						State::release( $lease, $owner );
					}
				}
				if ( count( $ids ) < 100 || 0 === $removed ) {
					break;
				}
				$pending = true;
			}
		}
		return $pending;
	}
}
