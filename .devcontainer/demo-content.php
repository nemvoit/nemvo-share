<?php
/**
 * Creates a few demo shares on an empty dev site (run with `wp eval-file`).
 *
 * @package NemvoShare
 */

use NemvoShare\Plugin;
use NemvoShare\Shares;

defined( 'ABSPATH' ) || exit;

if ( Shares::counts()['all'] > 0 ) {
	WP_CLI::log( 'Shares exist already; no demo content added.' );
	return;
}

wp_set_current_user( 1 );
$tmp = get_temp_dir();

// A small PDF and a 5 MB file (bigger than PHP's default 2 MB upload limit).
$pdf = $tmp . 'Contract-Acme-2026.pdf';
file_put_contents( $pdf, "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/MediaBox[0 0 595 842]/Parent 2 0 R>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n" ); // phpcs:ignore
$big = $tmp . 'Site backup 2026-11-01.zip';
file_put_contents( $big, random_bytes( 5 * MB_IN_BYTES ) ); // phpcs:ignore

$id = Shares::create(
	array(
		'title'      => 'Signed contract for Acme',
		'note'       => "Hi Anna,\n\nHere is the signed contract and the backup we talked about.\nThe VPN password is: Kp7w-M3qa-xT9e-Vh4c\n\nBest regards,\nFredrik",
		'password'   => 'Rq4t-Hn8m-Wz3k-Pb6d',
		'expires_at' => Plugin::now( 7 * DAY_IN_SECONDS ),
		'auto_delete' => 1,
	)
);
Shares::add_file_from_path( $id, $pdf, 'Contract-Acme-2026.pdf', 'application/pdf' );
Shares::add_file_from_path( $id, $big, 'Site backup 2026-11-01.zip', 'application/zip' );

Shares::create(
	array(
		'title'      => 'Wi-Fi for the guest network',
		'note'       => "Network: Nemvo-Guest\nPassword: correct-horse-battery-staple",
		'expires_at' => Plugin::now( DAY_IN_SECONDS ),
		'burn'       => 1,
		'auto_delete' => 1,
	)
);

$id = Shares::create(
	array(
		'title'      => 'Brand assets',
		'expires_at' => null,
		'auto_delete' => 0,
	)
);
Shares::add_file_from_path( $id, $pdf, 'Brand guidelines.pdf', 'application/pdf' );

wp_delete_file( $pdf );
wp_delete_file( $big );
WP_CLI::success( 'Demo shares created.' );
