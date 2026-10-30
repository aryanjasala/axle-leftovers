<?php
/**
 * Owners, confidence and evidence.
 *
 * @package AryanX\Axle\Leftovers
 */

namespace AryanX\Axle\Leftovers\Tests\Unit;

use AryanX\Axle\Leftovers\Attribution;
use AryanX\Axle\Leftovers\Dataset;
use AryanX\Axle\Leftovers\Ledger;
use AryanX\Axle\Leftovers\Signature;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * The four sources, the in-use rule and the owner filter.
 */
final class AttributionTest extends LeftoversTestCase {

	/**
	 * Installed plugin files.
	 *
	 * @var array
	 */
	private $installed = array();

	protected function setUp(): void {
		parent::setUp();
		$this->installed = array( 'akismet/akismet.php' => array() );
		Functions\when( 'get_plugins' )->alias(
			function () {
				return $this->installed;
			}
		);
		Functions\when( 'get_mu_plugins' )->justReturn( array( 'site-tweaks.php' => array() ) );
		Functions\when( 'wp_get_themes' )->justReturn( array( 'twentytwentyfive' => true ) );
	}

	/**
	 * Attribution over a ledger and a dataset.
	 */
	private function attribution( array $seen = array(), array $ledger = array(), array $dataset = array() ): Attribution {
		$mock = \Mockery::mock( Ledger::class );
		$mock->shouldReceive( 'seen_all' )->andReturn( $seen );
		$mock->shouldReceive( 'all' )->andReturn( $ledger );
		$mock->shouldReceive( 'flush' );

		$file = tempnam( sys_get_temp_dir(), 'alo' );
		file_put_contents( $file, json_encode( array( 'plugins' => $dataset ) ) );

		return new Attribution( $mock, new Dataset( $file ) );
	}

	/**
	 * A ledger entry.
	 */
	private function entry( string $slug, string $name, array $signature, ?string $removed = '2026-10-01 00:00:00' ): array {
		return array(
			'slug'        => $slug,
			'name'        => $name,
			'removed_gmt' => $removed,
			'signature'   => Signature::normalize( $signature ),
		);
	}

	public function test_seen_is_certain(): void {
		$who = $this->attribution( array( 'table' => array( 'wp_acme_log' => array( 'owner' => 'acme', 'first_seen' => '2025-04-02 10:00:00', 'evidence' => 'activation' ) ) ), array( 'acme' => $this->entry( 'acme', 'Acme', array( 't' => array( 'acme_log' ) ) ) ) )->attribute( 'table', 'wp_acme_log' );

		$this->assertSame( 'acme', $who['owner'] );
		$this->assertSame( 'Acme', $who['owner_name'] );
		$this->assertSame( 'certain', $who['confidence'] );
		$this->assertFalse( $who['in_use'] );
		$this->assertSame( array( 'activation', 'signature' ), array_column( $who['evidence'], 'code' ) );
		$this->assertSame( 'Created when Acme was activated on 2025-04-02', $who['evidence'][0]['text'] );
	}

	public function test_signature_dataset_and_prefix_are_likely(): void {
		$attribution = $this->attribution(
			array(),
			array( 'acme' => $this->entry( 'acme', 'Acme', array( 'o' => array( 'acme_settings' ), 'p' => array( 'acme_cache_' ), 'n' => array( 'acme_old' ) ) ) ),
			array( 'contact-form-7' => array( 'name' => 'Contact Form 7', 'o' => array( 'wpcf7' ) ) )
		);

		$this->assertSame( array( 'acme', 'likely', 'signature' ), $this->summary( $attribution->attribute( 'option', 'acme_settings' ) ) );
		$this->assertSame( array( 'acme', 'likely', 'uninstall' ), $this->summary( $attribution->attribute( 'option', 'acme_old' ) ) );
		$this->assertSame( array( 'acme', 'likely', 'signature-prefix' ), $this->summary( $attribution->attribute( 'option', '_transient_acme_cache_9' ) ) );
		$this->assertSame( array( 'contact-form-7', 'likely', 'dataset' ), $this->summary( $attribution->attribute( 'option', 'wpcf7' ) ) );
		$this->assertSame( 'Dataset match: named in Contact Form 7 on WordPress.org', $attribution->attribute( 'option', 'wpcf7' )['evidence'][0]['text'] );
	}

	public function test_guess_and_unknown(): void {
		$attribution = $this->attribution( array(), array(), array( 'leftover-maker' => array( 'name' => 'Leftover Maker' ) ) );

		$this->assertSame( array( 'leftover-maker', 'guess', 'guess' ), $this->summary( $attribution->attribute( 'option', 'leftover_maker_mode' ) ) );
		$this->assertSame( array( 'leftover-maker', 'guess', 'guess' ), $this->summary( $attribution->attribute( 'table', 'wp_lm_items' ) ) );
		$this->assertSame( array( '', 'unknown', '' ), $this->summary( $attribution->attribute( 'table', 'wp_mystery' ) ) );
	}

	public function test_installed_owners_mean_in_use(): void {
		$attribution = $this->attribution(
			array( 'option' => array( 'akismet_key' => array( 'owner' => 'akismet', 'first_seen' => '', 'evidence' => 'runtime' ) ) ),
			array(),
			array( 'acme' => array( 'name' => 'Acme', 'o' => array( 'shared_opt' ) ), 'akismet' => array( 'name' => 'Akismet', 'o' => array( 'shared_opt' ) ) )
		);

		$this->assertTrue( $attribution->attribute( 'option', 'akismet_key' )['in_use'] );
		$shared = $attribution->attribute( 'option', 'shared_opt' );
		$this->assertTrue( $shared['in_use'] );
		$this->assertSame( 'akismet', $shared['in_use_by'] );
	}

	public function test_ties_go_to_the_owner_the_name_starts_with(): void {
		$attribution = $this->attribution( array(), array(), array( '404-to-301' => array( 'name' => '404 to 301', 't' => array( 'redirection_logs' ) ), 'redirection' => array( 'name' => 'Redirection', 't' => array( 'redirection_logs' ) ) ) );

		$this->assertSame( 'redirection', $attribution->attribute( 'table', 'wp_redirection_logs' )['owner'] );
	}

	public function test_owner_filter(): void {
		Filters\expectApplied( 'axle_leftovers_owner' )->once()->with( \Mockery::type( 'array' ), 'option', 'x_opt' )->andReturn( array( 'owner' => 'x', 'confidence' => 'certain', 'in_use' => true, 'in_use_by' => 'x' ) );

		$who = $this->attribution()->attribute( 'option', 'x_opt' );

		$this->assertSame( array( 'x', 'certain', true ), array( $who['owner'], $who['confidence'], $who['in_use'] ) );
	}

	public function test_guess_prefixes(): void {
		$this->assertSame( array( 'contact_form_7_', 'contact-form-7-', 'contact-form-7_', 'f7_' ), Attribution::guess_prefixes( 'contact-form-7' ) );
		$this->assertSame( array( 'redirection_', 'redirection-' ), Attribution::guess_prefixes( 'redirection' ) );
	}

	/**
	 * Owner, confidence and first evidence code.
	 */
	private function summary( array $who ): array {
		return array( $who['owner'], $who['confidence'], $who['evidence'][0]['code'] ?? '' );
	}
}
