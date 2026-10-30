<?php
/**
 * The static code scanner.
 *
 * @package AryanX\Axle\Leftovers
 */

namespace AryanX\Axle\Leftovers\Tests\Unit;

use AryanX\Axle\Leftovers\Signature;

/**
 * Names and prefixes from PHP source.
 */
final class SignatureTest extends TestCase {

	public function test_finds_every_kind_of_name(): void {
		$source = <<<'PHP'
<?php
global $wpdb;
$table = $wpdb->prefix . 'acme_log';
$wpdb->query( "CREATE TABLE {$wpdb->prefix}acme_items ( id int )" );
add_option( 'acme_settings', array() );
update_option( 'acme_' . $key, 1 );
register_setting( 'acme', 'acme_api' );
set_transient( 'acme_cache', 1, 60 );
set_site_transient( 'acme_net', 1 );
wp_schedule_event( time() + 60, 'daily', 'acme_sync', array( 1 ) );
wp_schedule_single_event( time(), 'acme_once' );
update_post_meta( $id, '_acme_flag', 1 );
get_user_meta( $id, 'acme_seen', true );
add_role( 'acme_manager', 'Acme', array() );
$role->add_cap( 'acme_manage' );
$dir = wp_upload_dir()['basedir'] . '/acme-files/';
$this->get_option( 'not_wordpress' );
Acme::update_option( 'also_not' );
get_option( $dynamic );
get_option( 'ternary' ? 'a' : 'b' );
PHP;

		$signature = Signature::normalize( Signature::scan_source( $source ) );

		$this->assertSame( array( 'acme_items', 'acme_log' ), $signature['t'] );
		$this->assertSame( array( '_site_transient_acme_net', '_transient_acme_cache', 'acme_api', 'acme_settings' ), $signature['o'] );
		$this->assertSame( array( 'acme_once', 'acme_sync' ), $signature['c'] );
		$this->assertSame( array( '_acme_flag', 'acme_seen' ), $signature['m'] );
		$this->assertSame( array( 'acme_manage', 'acme_manager' ), $signature['r'] );
		$this->assertSame( array( 'acme-files' ), $signature['u'] );
		$this->assertSame( array( 'acme_' ), $signature['p'] );
	}

	public function test_core_tables_and_generic_prefixes_are_dropped(): void {
		$signature = Signature::scan_source( '<?php $wpdb->prefix . "posts"; get_option( "wp_" . $x ); get_option( "ab_" . $x ); get_option( "acme" . $x );' );

		$this->assertSame( array(), $signature['t'] );
		$this->assertSame( array(), $signature['p'] );
		$this->assertTrue( Signature::useful_prefix( 'acme_' ) );
		$this->assertFalse( Signature::useful_prefix( 'acme' ) );
		$this->assertFalse( Signature::useful_prefix( 'widget_' ) );
	}

	public function test_uninstall_names_and_like_patterns(): void {
		$signature = Signature::scan_source( "<?php delete_option( 'acme_version' ); \$wpdb->query( \"DELETE FROM x WHERE option_name LIKE 'acme_cache_%'\" ); \$x = 'acme_leftover';", true );

		$this->assertContains( 'acme_leftover', $signature['n'] );
		$this->assertContains( 'acme_cache_', $signature['p'] );
	}

	public function test_call_args_respect_nesting_and_quotes(): void {
		$source = "f( array( 1, 2 ), 'a,b', \"c(\" ) ;";
		$this->assertSame( array( 'array( 1, 2 )', "'a,b'", '"c("' ), Signature::call_args( $source, 2 ) );
	}

	public function test_match_by_name_uninstall_and_prefix(): void {
		$signature = array(
			'o' => array( 'acme_settings', '_site_transient_acme_net' ),
			'n' => array( 'acme_old' ),
			'p' => array( 'acme_cache_' ),
		);

		$this->assertSame( 'name', Signature::match( $signature, 'o', 'acme_settings' ) );
		$this->assertSame( 'name', Signature::match( $signature, 'o', '_transient_acme_net' ) );
		$this->assertSame( 'uninstall', Signature::match( $signature, 'o', 'acme_old' ) );
		$this->assertSame( 'prefix', Signature::match( $signature, 'o', '_transient_acme_cache_42' ) );
		$this->assertSame( '', Signature::match( $signature, 'o', 'other' ) );
	}

	public function test_scan_path_reads_a_folder_and_its_uninstall_file(): void {
		$dir = sys_get_temp_dir() . '/axle-leftovers-sig-' . uniqid();
		mkdir( $dir . '/inc', 0777, true );
		mkdir( $dir . '/node_modules' );
		file_put_contents( $dir . '/plugin.php', "<?php add_option( 'acme_a', 1 );" );
		file_put_contents( $dir . '/inc/x.php', "<?php add_option( 'acme_b', 1 );" );
		file_put_contents( $dir . '/node_modules/y.php', "<?php add_option( 'acme_c', 1 );" );
		file_put_contents( $dir . '/uninstall.php', "<?php \$n = 'acme_named';" );

		$signature = Signature::scan_path( $dir );

		$this->assertSame( array( 'acme_a', 'acme_b' ), $signature['o'] );
		$this->assertSame( array( 'acme_named' ), $signature['n'] );
		$this->assertEquals( array( 'n' => array( 'acme_named' ), 'o' => array( 'acme_a', 'acme_b' ) ), array_intersect_key( Signature::compact( $signature ), array( 'n' => 1, 'o' => 1 ) ) );
		$this->assertSame( array( 'acme_b' ), Signature::strip( $signature, array( 'o' => array( 'acme_a' ) ) )['o'] );

		array_map( 'unlink', array( $dir . '/plugin.php', $dir . '/inc/x.php', $dir . '/node_modules/y.php', $dir . '/uninstall.php' ) );
		rmdir( $dir . '/inc' );
		rmdir( $dir . '/node_modules' );
		rmdir( $dir );
	}
}
