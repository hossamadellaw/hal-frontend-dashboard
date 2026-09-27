<?php
/**
 * Test-only subprocess launcher (NO product code, NO assertions).
 *
 * Some runners (e.g. ubuntu-latest) compile sodium statically into PHP
 * while every harness invokes children with `-d extension=sodium`, which
 * then always emits an "Unable to load" startup warning that strict-stderr
 * harnesses correctly reject. This helper DROPS redundant
 * `-d extension=sodium|zip` flags when a probe child — same binary, same
 * base flags (including `-n` and `-d extension_dir=`), NO extension flags —
 * already provides them. It NEVER adds flags the caller did not request,
 * so absence configurations keep their real proof. On probe failure the
 * original flags are kept (fail-safe to old behavior).
 *
 * Usage (replaces the fixed flag lines, nothing else):
 *   $command = hal_php_argv(
 *       $php,
 *       array( '-d', 'extension_dir=' . HAL_TEST_EXT_DIR ), // + '-n' if the old code had it
 *       array( 'sodium', 'zip' )                            // subset the old code requested
 *   );
 *   $command[] = $script; // ... then append script/args exactly as before
 */

if ( ! function_exists( 'hal_php_argv' ) ) {
	function hal_php_argv( string $binary, array $base, array $wants ): array {
		static $cache = array();
		$key = md5( $binary . "\0" . implode( "\0", $base ) );
		if ( ! isset( $cache[ $key ] ) ) {
			$cache[ $key ] = array( 'sodium' => false, 'zip' => false );
			$probe = array_merge(
				array( $binary ),
				$base,
				array( '-r', 'echo extension_loaded("sodium") ? "S1" : "S0"; echo class_exists("ZipArchive") ? "Z1" : "Z0";' )
			);
			$desc = array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			);
			$proc = @proc_open( $probe, $desc, $pipes );
			if ( is_resource( $proc ) ) {
				$out = (string) stream_get_contents( $pipes[1] );
				fclose( $pipes[1] );
				$err = (string) stream_get_contents( $pipes[2] );
				fclose( $pipes[2] );
				$code = proc_close( $proc );
				if ( 0 === $code && false === strpos( $err, 'Fatal error' ) && false === strpos( $err, 'Parse error' ) ) {
					$cache[ $key ] = array(
						'sodium' => false !== strpos( $out, 'S1' ),
						'zip'    => false !== strpos( $out, 'Z1' ),
					);
				}
			}
		}
		$have = $cache[ $key ];
		$argv = array_merge( array( $binary ), $base );
		foreach ( $wants as $ext ) {
			$ext = strtolower( (string) $ext );
			if ( ( 'sodium' === $ext || 'zip' === $ext ) && ! empty( $have[ $ext ] ) ) {
				continue; // already provided by the child runtime: skip the redundant load flag
			}
			$argv[] = '-d';
			$argv[] = 'extension=' . $ext;
		}
		return $argv;
	}
}
