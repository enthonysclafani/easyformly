<?php
/**
 * DNS fixture for AntiSpam::mail_domain().
 *
 * The plugin calls dns_get_record() unqualified from the EasyFormly\AntiSpam namespace, so
 * this function shadows the global one in every test. Records come from
 * $GLOBALS['easyformly_test_dns']; an unknown domain behaves like a resolver failure.
 *
 * @package EasyFormly
 */

namespace EasyFormly\AntiSpam;

/**
 * Return the fixture records for a domain, or false like a failed lookup.
 *
 * @param string $domain Domain name.
 * @param int    $type   Record types (ignored: the fixture lists them explicitly).
 * @return array|false
 */
function dns_get_record( $domain, $type ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Same signature as the core function it shadows.
	return $GLOBALS['easyformly_test_dns'][ $domain ] ?? false;
}
