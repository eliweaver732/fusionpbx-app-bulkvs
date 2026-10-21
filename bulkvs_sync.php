<?php
/*
	FusionPBX
	Version: MPL 1.1

	The contents of this file are subject to the Mozilla Public License Version
	1.1 (the "License"); you may not use this file except in compliance with
	the License. You may obtain a copy of the License at
	http://www.mozilla.org/MPL/

	Software distributed under the License is distributed on an "AS IS" basis,
	WITHOUT WARRANTY OF ANY KIND, either express or implied. See the License
	for the specific language governing rights and limitations under the
	License.

	The Original Code is FusionPBX

	The Initial Developer of the Original Code is
	Mark J Crane <markjcrane@fusionpbx.com>
	Portions created by the Initial Developer are Copyright (C) 2008-2025
	the Initial Developer. All Rights Reserved.

	Contributor(s):
	Mark J Crane <markjcrane@fusionpbx.com>
*/

//includes files
	require_once dirname(__DIR__, 2) . "/resources/require.php";
	require_once "resources/check_auth.php";

	header('Content-Type: application/json');

//check permissions
	if (!permission_exists('bulkvs_view')) {
		echo json_encode(['status' => 'failed', 'message' => 'Access denied']);
		exit;
	}

//get sync type from request
	$sync_type = $_GET['type'] ?? $_POST['type'] ?? '';
	$reset = isset($_GET['reset']) || isset($_POST['reset']);

//load cache class
	require_once "resources/classes/bulkvs_cache.php";

	if (!in_array($sync_type, bulkvs_cache::SYNC_TYPES, true)) {
		echo json_encode(['status' => 'failed', 'message' => 'Invalid sync type']);
		exit;
	}

	$settings = new settings(['database' => $database, 'domain_uuid' => $domain_uuid]);
	$cache = new bulkvs_cache($database, $settings);

//release the session lock so other pages load during the sync; no message::add, token::create, or session_start below this line
	session_write_close();

	if ($reset) {
		$cache->acknowledgeRefresh($sync_type);
		echo json_encode(['status' => 'completed']);
		exit;
	}

	set_time_limit(0);
	$result = $cache->sync($sync_type, bulkvs_client_still_connected());
	echo json_encode($result->toArray($cache->refreshAvailable($sync_type)));

/**
 * PHP only notices a dropped client when it writes, so each call (at most every 2 seconds)
 * writes a newline, which is valid leading whitespace for the final JSON body.
 * ignore_user_abort keeps PHP from aborting the script on disconnect.
 * If a proxy hides the disconnect, the sync finishes normally.
 * @return callable fn(): bool
 */
function bulkvs_client_still_connected(): callable {
	ignore_user_abort(true);
	header('X-Accel-Buffering: no');
	while (ob_get_level() > 0) {
		ob_end_flush();
	}

	$last_write = 0;
	return function () use (&$last_write) {
		if (microtime(true) - $last_write >= 2) {
			$last_write = microtime(true);
			echo "\n";
			flush();
		}
		return connection_aborted() === 0;
	};
}

?>

