<?php

// Loaded before the test MU loader to simulate a failed history metadata write.
add_filter( 'update_user_metadata', function ( $check, $object_id, $meta_key ) {
	return 'safety-passwords_stop-list' === $meta_key ? false : $check;
}, 10, 3 );
