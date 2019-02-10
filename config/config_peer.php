<?php
return array(
/*
|--------------------------------------------------------------------------
| Peer Configuration
|--------------------------------------------------------------------------
*/
	// Maximum number of connected peers
	'max_peer'=>30,

	// Database max peer number
	'db_max_peers'=>300,

	// How many new peers to check from each peer
	'max_test_peers'=>5,	//checke peer max number

	// The initial peers to sync from in sanity
	'initial_peer_list'=>array(
							'http://t1.originchain.io',
							),

	// Bad peer is not add database
	'bad_peers'=>array(
							"127.",
							"localhost",
							"10.",
							),
);
?>
