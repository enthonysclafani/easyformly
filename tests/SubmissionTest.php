<?php
/**
 * Submission flow: validation, anti-spam, delivery, quarantine, recovery, privacy.
 *
 * Converted from the former tests/integration.php (run with `studio wp eval-file`):
 * same cases, now on the WordPress test suite in CI.
 *
 * @package EasyFormly
 */

namespace EasyFormly\Tests;

use EasyFormly\AntiSpam\AntiSpam;
use EasyFormly\AntiSpam\DisposableDomains;
use EasyFormly\Forms\Admin\Editor;
use EasyFormly\Forms\Forms;
use EasyFormly\Forms\Renderer;
use EasyFormly\Forms\Schema;
use EasyFormly\Phone\Phone;
use EasyFormly\Plugin;
use EasyFormly\Privacy\Privacy;
use EasyFormly\Review\Admin\Screen;
use EasyFormly\Review\Review;
use EasyFormly\Settings\Admin\Page;
use EasyFormly\Settings\Settings;
use EasyFormly\Submissions\Endpoint;
use EasyFormly\Submissions\Entries;
use EasyFormly\Submissions\State;
use EasyFormly\Submissions\Submission;

/**
 * Behaviour visible to visitors, administrators and the external services.
 */
class SubmissionTest extends TestCase {

	/**
	 * Promotional text that trips the content rules.
	 */
	private const PROMOTION = 'Buy now: guaranteed rankings. https://example.org/a https://example.org/b';

	public function test_email_and_phone_values_are_normalized_without_losing_valid_contacts(): void {
		$this->assertSame( 'Case+alias@example.org', AntiSpam::email( 'Case+alias@EXAMPLE.ORG' ), 'Email preserves local part and plus aliases' );
		foreach ( array( 'a..b@example.org', '.abc@example.org', 'abc.@example.org', 'a@localhost', 'a@ example.org', "a\r\n@example.org", 'a@@example.org' ) as $invalid ) {
			$this->assertFalse( AntiSpam::email( $invalid ), 'Reject malformed email: ' . $invalid );
		}
		$this->assertSame( 'djaisfauaus@gmail.com', AntiSpam::email( 'djaisfauaus@gmail.com' ), 'A random-looking valid alias is not treated as proof of fraud' );
		$this->assertSame( 'example.org', AntiSpam::domain( 'EXAMPLE.ORG' ), 'Domains canonicalized' );
		$this->assertSame( '', AntiSpam::domain( 'https://example.org' ), 'Reject URLs as domains' );
		$this->assertWPError( AntiSpam::phrase_list( 'cia' ), 'Prevent overly generic short phrases' );
		$this->assertSame( 'pacchetti speciali', AntiSpam::phrase_list( 'PACCHETTI SPECIALI' ), 'Phrases canonicalized' );

		$numbers = array(
			array( 'IT', '02 3661 8300', '+390236618300' ),
			array( 'US', '(202) 555-0123', '+12025550123' ),
			array( 'GB', '020 7946 0018', '+442079460018' ),
			array( 'FR', '01 42 68 53 00', '+33142685300' ),
			array( 'AU', '02 9374 4000', '+61293744000' ),
			array( 'IT', '0044 20 7946 0018', '+442079460018' ),
			array( 'IT', '٠٢ ٣٦٦١ ٨٣٠٠', '+390236618300' ),
		);
		foreach ( $numbers as [ $country, $input, $expected ] ) {
			$this->assertSame( $expected, Phone::normalize( $input, $country ), 'International telephone normalization: ' . $input );
		}
		foreach ( array( '123', 'call 02 3661 8300', '+39 02 3661 8300 ext 42', '++390236618300', '02 3661 8300<script>' ) as $invalid ) {
			$this->assertWPError( Phone::normalize( $invalid, 'IT' ), 'Whole phone value validated: ' . $invalid );
		}
		$this->assertWPError( Phone::normalize( '02 3661 8300', 'IT', true ), 'Mobile-only option rejects a landline' );
		$this->assertTrue( Phone::repetitive( '+393333333333' ), 'Detect repetitive Italian mobile number' );
	}

	public function test_initial_phone_country_follows_locale_and_overrides(): void {
		$locale = $GLOBALS['locale'] ?? null;
		try {
			$GLOBALS['locale'] = 'it_IT';
			$this->assertSame( 'IT', Phone::default_country(), 'Country follows site locale' );
			$GLOBALS['locale'] = 'en_GB';
			$this->assertSame( 'GB', Phone::default_country(), 'Territory respected for shared languages' );
			$this->assertSame( 'AU', Phone::default_country( array( 'phone_country' => 'FR' ), array( 'phone_country' => 'AU' ) ), 'Field country override has precedence' );
		} finally {
			$GLOBALS['locale'] = $locale;
		}
	}

	public function test_levels_and_conditional_fields_shape_validation(): void {
		$definition = $this->definition();
		$implicit   = $definition;
		unset( $implicit['config']['antispam_level'] );
		$implicit_id = $this->form( $implicit );
		$this->assertSame( 2, AntiSpam::level( Forms::definition( $implicit_id )['config'] ), 'Forms without an explicit level inherit the site level' );
		$this->assertSame( 2, AntiSpam::level( Forms::config_defaults() ), 'New forms inherit balanced protection' );

		$validation = Schema::validate( $definition, $this->values( array( 'telefono' => '3333333333' ) ), array( 'telefono' => 'IT' ) );
		$this->assertArrayHasKey( 'telefono', $validation['errors'], 'Repetitive phone has an actionable field error at level 2' );
		$base                             = $definition;
		$base['config']['antispam_level'] = '1';
		$this->assertEmpty( Schema::validate( $base, $this->values( array( 'telefono' => '3333333333' ) ) )['errors'], 'Level 1 keeps plausible repetitive numbers' );
		$exception = $definition;
		$exception['schema']['fields']['telefono']['phone_allow_repeated'] = true;
		$this->assertEmpty( Schema::validate( $exception, $this->values( array( 'telefono' => '3333333333' ) ) )['errors'], 'A field can explicitly permit repetitive numbers' );

		$conditional                    = $definition;
		$conditional['conditions']      = Schema::rules(
			$definition['schema']['fields'],
			array(
				array(
					'target'   => 'telefono',
					'source'   => 'nome',
					'operator' => 'equals',
					'value'    => 'show phone',
				),
			)
		);
		$conditional_id                 = $this->form( $conditional );
		$input                          = $this->input( $conditional_id, $this->values( array( 'telefono' => 'forged junk' ) ) );
		$input['countries']['telefono'] = 'ZZ';
		$this->assertNotWPError( $this->submit( $conditional_id, $input ), 'Hidden malformed phone/country are discarded' );
		$this->assertArrayNotHasKey( 'telefono', get_post_meta( $this->entry(), '_easyformly_values', true ), 'Hidden phone never reaches storage' );
	}

	public function test_optional_dns_check_rejects_domains_without_mail_and_tolerates_outages(): void {
		$GLOBALS['easyformly_test_dns'] = array(
			'null.example.org'     => array(
				array(
					'type'   => 'MX',
					'target' => '.',
					'pri'    => 0,
				),
			),
			'fallback.example.org' => array(
				array(
					'type' => 'A',
					'ip'   => '192.0.2.1',
				),
			),
			'absent.example.org'   => array(),
		);
		$this->assertSame( 'invalid', AntiSpam::mail_domain( 'null.example.org' ), 'Null MX is rejected' );
		$this->assertSame( 'valid', AntiSpam::mail_domain( 'fallback.example.org' ), 'Address record permits SMTP fallback' );
		$this->assertSame( 'invalid', AntiSpam::mail_domain( 'absent.example.org' ), 'Domain without mail/address records is rejected' );
		$this->assertSame( 'unknown', AntiSpam::mail_domain( 'timeout.example.org' ), 'Resolver failure remains inconclusive' );

		$dns = $this->definition( array( 'email_dns_check' => 'yes' ) );
		$this->assertArrayHasKey( 'email', Schema::validate( $dns, $this->values( array( 'email' => 'person@null.example.org' ) ) )['errors'], 'Optional DNS produces a field error' );
		$this->assertEmpty( Schema::validate( $dns, $this->values( array( 'email' => 'person@timeout.example.org' ) ) )['errors'], 'DNS outage does not lose valid contacts' );
	}

	public function test_assessment_combines_independent_signals(): void {
		$definition                       = $this->definition();
		$id                               = $this->form( $definition );
		$base                             = $definition;
		$base['config']['antispam_level'] = '1';

		$this->assertSame( 'accepted', AntiSpam::assess( $id, $base, $this->values( array( 'messaggio' => self::PROMOTION ) ) )['status'], 'Base level applies fewer rules' );
		$this->assertSame( 'suspect', AntiSpam::assess( $id, $definition, $this->values( array( 'messaggio' => self::PROMOTION ) ) )['status'], 'Promotional content alone is quarantined as suspect' );
		$this->assertSame( 'suspect', AntiSpam::assess( $id, $definition, $this->values( array( 'email' => 'person@mailinator.com' ) ) )['status'], 'Disposable domain alone is suspect at balanced level' );
		$this->assertSame(
			'spam',
			AntiSpam::assess(
				$id,
				$definition,
				$this->values(
					array(
						'email'     => 'person@mailinator.com',
						'messaggio' => self::PROMOTION,
					)
				)
			)['status'],
			'Independent domain and content signals establish spam'
		);

		$allow                                    = $definition;
		$allow['config']['allowed_email_domains'] = 'mailinator.com';
		$this->assertContains( 'disposable', AntiSpam::assess( $id, $allow, $this->values( array( 'email' => 'person@mailinator.com' ) ) )['reasons'], 'Retired manual exceptions do not override public lists' );

		$observe                               = $definition;
		$observe['config']['antispam_observe'] = 'yes';
		$observed_phone                        = Schema::validate( $observe, $this->values( array( 'telefono' => '3333333333' ) ) );
		$this->assertEmpty( $observed_phone['errors'], 'Observation does not reject a repetitive phone' );
		$this->assertContains( 'repetitive_phone', AntiSpam::assess( $id, $observe, $observed_phone['values'] )['reasons'], 'Observation records repetitive phone' );
		$observed = AntiSpam::assess(
			$id,
			$observe,
			$this->values(
				array(
					'email'     => 'person@mailinator.com',
					'messaggio' => self::PROMOTION,
				)
			)
		);
		$this->assertSame( array( 'accepted', 'spam' ), array( $observed['status'], $observed['detected'] ), 'Observation preserves detection without holding delivery' );

		$block                                    = $base;
		$block['config']['antispam_observe']      = 'yes';
		$block['config']['blocked_email_domains'] = 'example.org';
		$this->assertSame( 'accepted', AntiSpam::assess( $id, $block, $this->values() )['status'], 'Retired manual blocks no longer affect submissions' );
		$this->assertSame( 'spam', AntiSpam::assess( $id, $observe, $this->values(), array( 'honeypot' => true ) )['status'], 'Honeypot remains effective in observation mode' );
		$this->assertFalse( DisposableDomains::contains( 'easyformly-unrelated-mailinator.com' ), 'Public lists ignore unrelated suffixes' );
		$this->assertTrue( DisposableDomains::contains( 'inbox.mailinator.com' ), 'Public lists match parent domains' );

		$strict                             = $definition;
		$strict['config']['antispam_level'] = '3';
		$strict_id                          = $this->form( $strict );
		$this->assertSame( 'spam', AntiSpam::assess( $strict_id, $strict, $this->values( array( 'email' => 'person@mailinator.com' ) ), array( 'elapsed' => 0 ) )['status'], 'Strict level combines weak timing and domain signals sooner' );

		$duplicate_id = $this->form( $definition );
		for ( $n = 0; $n < 4; ++$n ) {
			$duplicate = AntiSpam::assess(
				$duplicate_id,
				$definition,
				$this->values(
					array(
						'email'     => 'visitor' . $n . '@example.org',
						'telefono'  => '',
						'messaggio' => 'This identical campaign requests an unrelated appointment each day.',
					)
				)
			);
		}
		$this->assertContains( 'duplicate', $duplicate['reasons'], 'Similar content is counted across independent tickets' );
		$this->assertSame( 'suspect', $duplicate['status'], 'Similar content makes the submission suspect' );
	}

	public function test_counters_and_rate_limits_stay_saturated(): void {
		for ( $n = 0; $n < 5; ++$n ) {
			$counter = State::count( 'integration_cap', 'fixture', 300, 2 );
		}
		$this->assertSame( 2, $counter, 'Saturated counters remain enforced' );
		$id                     = $this->form( $this->definition() );
		$_SERVER['REMOTE_ADDR'] = '192.0.2.41';
		$this->assertTrue( State::rate_allowed( $id, 1, 'test_rate' ), 'First request within the cap' );
		$this->assertFalse( State::rate_allowed( $id, 1, 'test_rate' ), 'Second request over the cap' );
		$this->assertFalse( State::rate_allowed( $id, 1, 'test_rate' ), 'IP rate stays blocked after reaching its cap' );
	}

	public function test_clean_submission_is_delivered_once_and_receipts_hold_no_contact_data(): void {
		$id    = $this->form( $this->delivery_definition() );
		$input = $this->input( $id, $this->values( array( 'newsletter' => '1' ) ) );

		$result = $this->submit( $id, $input );
		$this->assertNotWPError( $result, 'Clean submission is accepted' );
		$this->assertArrayNotHasKey( 'held', $result, 'Clean submission is not held' );
		$entry = $this->entry();
		$this->assertSame( 'accepted', Review::category( $entry ), 'Clean archive has one accepted category' );
		$this->assertTrue( Review::can_deliver( $entry ), 'Clean archive can be delivered' );
		$this->assertSame(
			array(
				'mail'       => 1,
				'slack'      => 1,
				'mailerlite' => 1,
			),
			$this->calls,
			'Each destination receives once'
		);

		$after = $this->calls;
		$this->assertSame( $result, $this->submit( $id, $input ), 'Receipt retry returns the stored answer' );
		$this->assertSame( $after, $this->calls, 'Receipt retry never redelivers' );
		$changed                   = $input;
		$changed['fields']['nome'] = 'Changed person';
		$this->assertSame( 'used', $this->submit( $id, $changed )->get_error_code(), 'One ticket cannot be reused for another payload' );

		$state = State::read( substr( hash( 'sha256', $input['ticket'] ), 0, 40 ) );
		$this->assertStringNotContainsString( 'Maria+preventivo', wp_json_encode( $state ), 'Receipts contain no raw contact fields' );
		$this->assertArrayNotHasKey( 'values', $state, 'Receipts contain no values' );

		$bad_clock           = $this->input( $id, $this->values() );
		$bad_clock['clock'] .= '0';
		$this->assertSame( 'invalid', $this->submit( $id, $bad_clock )->get_error_code(), 'Tampered start clock is rejected' );
		$missing_trap = $this->input( $id, $this->values() );
		unset( $missing_trap['honeypot'] );
		$this->assertSame( 'invalid', $this->submit( $id, $missing_trap )->get_error_code(), 'Missing rotating honeypot is rejected' );
		$token = Endpoint::issue( $id, $input['clock'] );
		$this->assertSame( $input['clock'], $token['clock'], 'Refresh preserves interaction time' );
		$this->assertArrayNotHasKey( $token['honeypot'], $input['honeypot'], 'Refresh rotates the trap' );

		$legacy_time   = time();
		$legacy_uuid   = wp_generate_uuid4();
		$legacy_ticket = $legacy_time . '.' . $legacy_uuid . '.' . hash_hmac( 'sha256', get_current_blog_id() . '|' . $id . '|' . $legacy_time . '|' . $legacy_uuid, wp_salt( 'auth' ) );
		$legacy_input  = array(
			'fields'  => $this->values(),
			'ticket'  => $legacy_ticket,
			'nonce'   => wp_create_nonce( 'easyformly_submit_' . get_current_blog_id() . '_' . $id ),
			'website' => '',
		);
		$this->assertSame( 'expired', $this->submit( $id, $legacy_input )->get_error_code(), 'Tickets without the current signature format are refused' );
	}

	public function test_spam_is_held_until_an_administrator_approves_it(): void {
		$definition = $this->delivery_definition();
		$id         = $this->form( $definition );

		$held = $this->submit( $id, $this->input( $id, $this->values( array( 'newsletter' => '1' ) ), 'filled by bot' ) );
		$this->assertNotWPError( $held, 'Spam is saved' );
		$this->assertTrue( $held['held'], 'Spam is acknowledged as held' );
		$this->assertSame( 0, array_sum( $this->calls ), 'Spam reaches no outbound destination' );
		$entry = $this->entry();
		$this->assertSame( 'spam', Review::category( $entry ), 'Honeypot categorizes spam in private taxonomy' );
		$this->assertTrue( Review::held( $entry ), 'Spam is held' );
		$this->assertFalse( wp_next_scheduled( 'easyformly_retry_delivery', array( $entry ) ), 'Spam has no scheduled delivery' );
		$this->assertWPError( Submission::retry( $entry ), 'Cron cannot release spam' );

		$this->login_admin();
		$this->assertTrue( Review::change( $entry, 'accepted' ), 'Category can change' );
		$this->assertTrue( Review::held( $entry ), 'Manual category change preserves delivery hold' );
		$this->assertTrue( Review::write( $entry, 'spam', 'automatic', true ), 'Automatic write is accepted' );
		$this->assertSame( 'accepted', Review::category( $entry ), 'Manual classification has precedence' );
		$this->assertWPError( Submission::retry( $entry, true ), 'Generic retry cannot approve held entry' );
		$this->assertSame( 0, array_sum( $this->calls ), 'Nothing sent before approval' );

		$block_job = static function ( $check, $post_id, $key ) use ( $entry ) {
			return $post_id === $entry && '_easyformly_retry' === $key ? false : $check;
		};
		add_filter( 'update_post_metadata', $block_job, 10, 3 );
		$failed = Submission::retry( $entry, true, false, true );
		remove_filter( 'update_post_metadata', $block_job, 10 );
		$this->assertWPError( $failed, 'Approval preparation failure is reported' );
		$this->assertTrue( Review::held( $entry ), 'Approval preparation failure keeps the hold' );
		$this->assertSame( 0, array_sum( $this->calls ), 'Approval preparation failure performs no delivery' );

		$lease = State::claim( 'retry_' . $entry );
		$this->assertWPError( Review::change( $entry, 'spam' ), 'Classification shares delivery ownership' );
		State::release( 'retry_' . $entry, $lease );

		$this->assertTrue( Submission::retry( $entry, true, false, true ), 'Explicit approval succeeds' );
		$this->assertFalse( Review::held( $entry ), 'Explicit approval releases quarantined entry' );
		$this->assertSame(
			array(
				'mail'       => 1,
				'slack'      => 1,
				'mailerlite' => 1,
			),
			$this->calls,
			'Approval sends each held destination once'
		);
		$this->assertWPError( Submission::retry( $entry, true, false, true ), 'A second approval is refused' );
		$this->assertTrue( Review::change( $entry, 'spam' ), 'Categories can be manually corrected to spam' );
		$this->assertTrue( Review::change( $entry, 'accepted' ), 'Categories can be manually corrected back' );
		$this->assertCount( 1, wp_get_object_terms( $entry, Review::TAXONOMY ), 'Taxonomy remains mutually exclusive' );
		$this->assertTrue( Submission::retry( $entry, true, false, true ), 'Reapproval is possible' );
		$this->assertSame( 3, array_sum( $this->calls ), 'Reapproval skips previously sent destinations' );

		$interrupted = Entries::store( $id, $definition, $this->values( array( 'newsletter' => '1' ) ), $this->assessment( 'accepted' ) );
		update_post_meta(
			$interrupted,
			'_easyformly_delivery',
			array(
				'key'      => 'fixture-interrupted',
				'outcomes' => array(
					'archive'    => 'saved',
					'mail'       => 'sent',
					'mailerlite' => 'sent',
					'slack'      => 'held',
				),
			)
		);
		$this->assertFalse( Review::held( $interrupted ), 'Interrupted approval is not marked held' );
		$this->assertTrue( Review::requires_approval( $interrupted ), 'Interrupted approval remains recoverable in the interface' );
		$this->assertTrue( Submission::retry( $interrupted, true, false, true ), 'Interrupted approval completes' );
		$this->assertSame(
			array(
				'mail'       => 1,
				'slack'      => 2,
				'mailerlite' => 1,
			),
			$this->calls,
			'Interrupted approval skips every previously sent destination'
		);
		update_post_meta( $interrupted, '_easyformly_initializing', '1' );
		$this->assertFalse( Review::can_deliver( $interrupted ), 'Partially initialized entries cannot be delivered' );
		$this->assertWPError( Review::change( $interrupted, 'spam' ), 'Partially initialized entries cannot be reviewed' );
	}

	public function test_suspicious_submissions_need_explicit_storage_and_a_daily_budget(): void {
		$no_archive    = $this->definition(
			array(
				'store_entries'      => 'no',
				'quarantine_entries' => 'no',
			)
		);
		$no_archive_id = $this->form( $no_archive );
		$this->assertSame( 'spam', $this->submit( $no_archive_id, $this->input( $no_archive_id, $this->values(), 'bot' ) )->get_error_code(), 'Spam is rejected without explicit local-storage consent' );
		$this->assertSame( 0, array_sum( $this->calls ), 'Rejected spam reaches no destination' );
		$this->assertEmpty(
			get_posts(
				array(
					'post_type'   => Plugin::ENTRY,
					'post_status' => 'private',
					'meta_key'    => '_easyformly_form_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Test assertion on a handful of rows.
					'meta_value'  => $no_archive_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Test assertion on a handful of rows.
				)
			),
			'No quarantined entry is created when storage is off'
		);

		$quarantine                                 = $no_archive;
		$quarantine['config']['quarantine_entries'] = 'yes';
		$quarantine_id                              = $this->form( $quarantine );
		$this->assertNotWPError( $this->submit( $quarantine_id, $this->input( $quarantine_id, $this->values( array( 'messaggio' => self::PROMOTION ) ) ) ), 'Explicit quarantine works independently of normal archive' );
		$this->assertSame( 'suspect', Review::category( $this->entry() ), 'Probable spam is a distinct category' );

		update_option( Settings::OPTION, array_merge( Settings::get(), array( 'quarantine_limit' => 2 ) ), false );
		$this->assertNotWPError( $this->submit( $quarantine_id, $this->input( $quarantine_id, $this->values(), 'bot' ) ), 'Quarantine accepts within budget' );
		$this->assertSame( 'spam', $this->submit( $quarantine_id, $this->input( $quarantine_id, $this->values(), 'bot' ) )->get_error_code(), 'Quarantine daily budget is bounded' );
	}

	public function test_recoverable_failures_get_a_bounded_retry(): void {
		$id               = $this->form(
			$this->definition(
				array(
					'slack_enabled' => true,
					'slack_webhook' => 'https://hooks.slack.com/services/TEST/TEST/TEST',
				)
			)
		);
		$this->slack_code = 429;
		$this->submit( $id, $this->input( $id, $this->values() ) );
		$entry = $this->entry();
		$this->assertNotFalse( wp_next_scheduled( 'easyformly_retry_delivery', array( $entry ) ), 'Recoverable clean failure gets bounded cron retry' );

		$this->slack_code = 200;
		// Run the event as WP-Cron does, with its stored arguments.
		$event = wp_get_scheduled_event( 'easyformly_retry_delivery', array( $entry ) );
		do_action_ref_array( 'easyformly_retry_delivery', $event->args );
		$this->assertFalse( wp_next_scheduled( 'easyformly_retry_delivery', array( $entry ) ), 'Recovery succeeds and leaves nothing scheduled' );
		$this->assertSame(
			array(
				'mail'       => 1,
				'slack'      => 2,
				'mailerlite' => 0,
			),
			$this->calls,
			'Recovery sends only the failed destination'
		);
		$this->login_admin();
		$this->assertTrue( Review::change( $entry, 'spam' ), 'Entry can be marked as spam' );
		$this->assertFalse( wp_next_scheduled( 'easyformly_retry_delivery', array( $entry ) ), 'Manual spam classification cancels recovery' );
	}

	public function test_privacy_tools_bulk_review_and_retention(): void {
		$definition = $this->definition();
		$id         = $this->form( $definition );
		$accepted   = Entries::store( $id, $definition, $this->values(), $this->assessment( 'accepted' ) );
		$suspect    = Entries::store( $id, $definition, $this->values(), $this->assessment( 'suspect' ) );

		$export = wp_json_encode( Privacy::export( 'Maria+preventivo@example.org' ) );
		$this->assertStringContainsString( 'Anti-spam category', $export, 'Privacy export includes category' );
		$this->assertStringContainsString( 'Anti-spam score', $export, 'Privacy export includes assessment' );

		$taxonomy = get_taxonomy( Review::TAXONOMY );
		$this->assertFalse( post_type_supports( Plugin::ENTRY, 'editor' ) || post_type_supports( Plugin::ENTRY, 'title' ), 'Archived contact details have no content editor' );
		$this->assertFalse( $taxonomy->public || $taxonomy->show_in_rest || $taxonomy->show_ui, 'Taxonomy is private' );
		$this->assertSame( 'manage_options', $taxonomy->cap->assign_terms, 'Taxonomy is administrator-only' );

		$this->login_admin();
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'bulk-posts' );
		Screen::bulk( admin_url( 'edit.php' ), 'easyformly-classify-suspect', array( $accepted, $suspect ) );
		$this->assertSame( array( 'suspect', 'suspect' ), array( Review::category( $accepted ), Review::category( $suspect ) ), 'Bulk classification handles multiple entries' );

		update_option(
			Settings::OPTION,
			array_merge(
				Settings::get(),
				array(
					'spam_retention_days' => 14,
					'retention_days'      => 30,
				)
			),
			false
		);
		$retention = array();
		foreach ( array( array( 'spam', 15, false ), array( 'suspect', 15, false ), array( 'accepted', 15, true ), array( 'accepted', 31, false ), array( 'spam', 5, true ) ) as [ $category, $age, $keep ] ) {
			$entry = Entries::store( $id, $definition, $this->values(), $this->assessment( $category ) );
			$date  = gmdate( 'Y-m-d H:i:s', time() - $age * DAY_IN_SECONDS );
			wp_update_post(
				array(
					'ID'            => $entry,
					'post_date'     => $date,
					'post_date_gmt' => $date,
				)
			);
			$retention[ $entry ] = $keep;
		}
		Entries::cleanup();
		foreach ( $retention as $entry => $keep ) {
			$this->assertSame( $keep, (bool) get_post( $entry ), 'Retention honors category-specific duration for entry ' . $entry );
		}

		$erase = Entries::store( $id, $definition, $this->values( array( 'email' => 'erase-fixture@example.org' ) ) );
		wp_schedule_single_event( time() + 60, 'easyformly_retry_delivery', array( $erase ) );
		$this->assertNotEmpty( Privacy::erase( 'erase-fixture@example.org' )['items_removed'], 'Privacy erasure removes the entry' );
		$this->assertNull( get_post( $erase ), 'Erased entry is gone' );
		$this->assertFalse( wp_next_scheduled( 'easyformly_retry_delivery', array( $erase ) ), 'Privacy erasure also cancels pending work' );
	}

	public function test_admin_validation_keeps_safe_values_and_markup_has_protections(): void {
		$definition = $this->definition();
		$this->assertWPError( Editor::config( array( 'antispam_level' => '99' ), $definition['config'], $definition['schema']['fields'] ), 'Reject invalid per-form protection level' );

		$settings = Page::sanitize_settings(
			array(
				'antispam_present'      => '1',
				'antispam_level'        => '99',
				'spam_retention_days'   => '0',
				'quarantine_limit'      => '100000',
				'blocked_email_domains' => 'https://invalid.example',
			)
		);
		$this->assertSame( 2, $settings['antispam_level'], 'Invalid level keeps the previous one' );
		$this->assertSame( 14, $settings['spam_retention_days'], 'Invalid retention keeps the previous one' );
		$this->assertSame( 500, $settings['quarantine_limit'], 'Invalid limit keeps the previous one' );
		$this->assertArrayNotHasKey( 'blocked_email_domains', $settings, 'Retired manual lists are discarded' );
		$this->assertArrayNotHasKey( 'allowed_email_domains', $settings, 'Retired manual lists are discarded' );

		$html = Renderer::render( $this->form( $definition ), $definition );
		$this->assertStringContainsString( 'data-easyformly-country', $html, 'Markup includes the country selector' );
		$this->assertStringContainsString( 'name="countries[telefono]"', $html, 'Markup includes the country field' );
		$this->assertStringContainsString( 'data-easyformly-trap', $html, 'Markup includes the honeypot' );
	}

	public function test_archive_recreates_review_categories_removed_outside_the_plugin(): void {
		$definition = $this->definition();
		$id         = $this->form( $definition );
		foreach ( array_keys( Review::labels() ) as $slug ) {
			$term = get_term_by( 'slug', $slug, Review::TAXONOMY );
			if ( $term ) {
				wp_delete_term( $term->term_id, Review::TAXONOMY );
			}
		}

		$this->assertNotWPError( $this->submit( $id, $this->input( $id, $this->values() ) ), 'The submission is archived' );
		$this->assertSame( 'accepted', Review::category( $this->entry() ), 'The missing category is recreated' );
	}

	/**
	 * A form that delivers to every destination.
	 *
	 * @return array
	 */
	private function delivery_definition(): array {
		return $this->definition(
			array(
				'slack_enabled'      => true,
				'slack_webhook'      => 'https://hooks.slack.com/services/TEST/TEST/TEST',
				'slack_fields'       => 'nome,email,messaggio',
				'mailerlite_enabled' => true,
				'mailerlite_groups'  => '1',
			)
		);
	}
}
