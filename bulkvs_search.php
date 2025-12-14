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

//check permissions
	if (!permission_exists('bulkvs_search')) {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//initialize the settings object
	$settings = new settings(['database' => $database, 'domain_uuid' => $domain_uuid]);

//get http variables
	$npa = $_GET['npa'] ?? $_POST['npa'] ?? '';
	$npanxx = $_GET['npanxx'] ?? $_POST['npanxx'] ?? '';
	$search_action = $_GET['action'] ?? $_POST['action'] ?? '';
	$purchase_tn = $_POST['purchase_tn'] ?? '';
	$purchase_domain_uuid = $_POST['purchase_domain_uuid'] ?? '';

//process purchase
	if ($search_action == 'purchase' && !empty($purchase_tn) && !empty($purchase_domain_uuid)) {
		if (!permission_exists('bulkvs_purchase')) {
			message::add("Access denied", 'negative');
			header("Location: bulkvs_search.php");
			return;
		}

		try {
			require_once "resources/classes/bulkvs_api.php";
			$bulkvs_api = new bulkvs_api($settings);
			$trunk_group = $settings->get('bulkvs', 'trunk_group', '');
			
			if (empty($trunk_group)) {
				throw new Exception("Trunk Group must be configured in default settings");
			}

			// Purchase the number
			$bulkvs_api->purchaseNumber($purchase_tn, $trunk_group);

			// Create destination in FusionPBX
			require_once dirname(__DIR__, 2) . "/app/destinations/resources/classes/destinations.php";
			$destination = new destinations(['database' => $database, 'domain_uuid' => $purchase_domain_uuid]);
			
			$destination_uuid = uuid();
			$destination_number = preg_replace('/[^0-9]/', '', $purchase_tn); // Remove non-numeric characters
			
			// Get domain name for context
			$sql = "select domain_name from v_domains where domain_uuid = :domain_uuid ";
			$parameters['domain_uuid'] = $purchase_domain_uuid;
			$domain_name = $database->select($sql, $parameters, 'column');
			unset($sql, $parameters);

			// Prepare destination array
			$array['destinations'][0]['destination_uuid'] = $destination_uuid;
			$array['destinations'][0]['domain_uuid'] = $purchase_domain_uuid;
			$array['destinations'][0]['destination_type'] = 'inbound';
			$array['destinations'][0]['destination_number'] = $destination_number;
			$array['destinations'][0]['destination_context'] = $domain_name ?? 'public';
			$array['destinations'][0]['destination_enabled'] = 'true';
			$array['destinations'][0]['destination_description'] = 'BulkVS: ' . $purchase_tn;

			// Grant temporary permissions
			$p = permissions::new();
			$p->add('destination_add', 'temp');
			$p->add('dialplan_add', 'temp');
			$p->add('dialplan_detail_add', 'temp');

			// Save the destination
			$database->app_name = 'destinations';
			$database->app_uuid = '5ec89622-b19c-3559-64f0-afde802ab139';
			$database->save($array);

			// Revoke temporary permissions
			$p->delete('destination_add', 'temp');
			$p->delete('dialplan_add', 'temp');
			$p->delete('dialplan_detail_add', 'temp');

			message::add($text['message-purchase-success']);
			header("Location: bulkvs_search.php?npa=".urlencode($npa)."&npanxx=".urlencode($npanxx));
			return;
		} catch (Exception $e) {
			message::add($text['message-api-error'] . ': ' . $e->getMessage(), 'negative');
		}
	}

//search for numbers
	$search_results = [];
	$error_message = '';
	if ($search_action == 'search' && (!empty($npa) || !empty($npanxx))) {
		try {
			require_once "resources/classes/bulkvs_api.php";
			$bulkvs_api = new bulkvs_api($settings);
			$api_response = $bulkvs_api->searchNumbers($npa, $npanxx);
			
			// Handle API response
			if (isset($api_response['data']) && is_array($api_response['data'])) {
				$search_results = $api_response['data'];
			} elseif (is_array($api_response)) {
				$search_results = $api_response;
			}
		} catch (Exception $e) {
			$error_message = $e->getMessage();
			message::add($text['message-api-error'] . ': ' . $error_message, 'negative');
		}
	}

//get list of domains for purchase dropdown
	$domains = [];
	if (permission_exists('domain_all') || permission_exists('domain_select')) {
		$sql = "select domain_uuid, domain_name ";
		$sql .= "from v_domains ";
		$sql .= "where domain_enabled = true ";
		$sql .= "order by domain_name asc ";
		$domains = $database->select($sql, null, 'all');
	} else {
		// Only current domain
		$sql = "select domain_uuid, domain_name ";
		$sql .= "from v_domains ";
		$sql .= "where domain_uuid = :domain_uuid ";
		$parameters['domain_uuid'] = $domain_uuid;
		$domains = $database->select($sql, $parameters, 'all');
	}

//create token
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//include the header
	$document['title'] = $text['title-bulkvs-search'];
	require_once "resources/header.php";

//show the content
	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['title-bulkvs-search']."</b></div>\n";
	echo "	<div class='actions'>\n";
	echo button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>'arrow-left','link'=>'bulkvs_numbers.php']);
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	echo $text['description-bulkvs-search']."\n";
	echo "<br /><br />\n";

	// Search form
	echo "<div class='card'>\n";
	echo "	<div class='subheading'>".$text['button-search']."</div>\n";
	echo "	<div class='content'>\n";
	echo "		<form method='get' action=''>\n";
	echo "			<input type='hidden' name='action' value='search'>\n";
	echo "			<table class='no_hover'>\n";
	echo "				<tr>\n";
	echo "					<td class='vncell'>".$text['label-npa']."</td>\n";
	echo "					<td class='vtable'><input type='text' class='formfld' name='npa' value='".escape($npa)."' maxlength='3' placeholder='e.g., 415'></td>\n";
	echo "				</tr>\n";
	echo "				<tr>\n";
	echo "					<td class='vncell'>".$text['label-npanxx']."</td>\n";
	echo "					<td class='vtable'><input type='text' class='formfld' name='npanxx' value='".escape($npanxx)."' maxlength='6' placeholder='e.g., 415555'></td>\n";
	echo "				</tr>\n";
	echo "				<tr>\n";
	echo "					<td colspan='2'><input type='submit' class='btn' value='".$text['button-search']."'></td>\n";
	echo "				</tr>\n";
	echo "			</table>\n";
	echo "		</form>\n";
	echo "	</div>\n";
	echo "</div>\n";
	echo "<br />\n";

	// Search results
	if ($search_action == 'search') {
		if (!empty($error_message)) {
			echo "<div class='alert alert-warning'>".escape($error_message)."</div>\n";
			echo "<br />\n";
		}

		if (!empty($search_results)) {
			echo "<div class='card'>\n";
			echo "<table class='list'>\n";
			echo "<tr class='list-header'>\n";
			echo "	<th>".$text['label-telephone-number']."</th>\n";
			echo "	<th>".$text['label-rate-center']."</th>\n";
			echo "	<th>".$text['label-lata']."</th>\n";
			if (permission_exists('bulkvs_purchase')) {
				echo "	<td class='action-button'>&nbsp;</td>\n";
			}
			echo "</tr>\n";

			foreach ($search_results as $result) {
				$tn = $result['tn'] ?? $result['telephoneNumber'] ?? '';
				$rate_center = $result['rateCenter'] ?? '';
				$lata = $result['lata'] ?? '';

				echo "<tr class='list-row'>\n";
				echo "	<td>".escape($tn)."</td>\n";
				echo "	<td>".escape($rate_center)."&nbsp;</td>\n";
				echo "	<td>".escape($lata)."&nbsp;</td>\n";
				if (permission_exists('bulkvs_purchase')) {
					echo "	<td class='action-button'>\n";
					echo "		<form method='post' action='' style='display: inline;'>\n";
					echo "			<input type='hidden' name='action' value='purchase'>\n";
					echo "			<input type='hidden' name='purchase_tn' value='".escape($tn)."'>\n";
					echo "			<input type='hidden' name='npa' value='".escape($npa)."'>\n";
					echo "			<input type='hidden' name='npanxx' value='".escape($npanxx)."'>\n";
					echo "			<select name='purchase_domain_uuid' class='formfld' style='width: auto; margin-right: 5px;'>\n";
					foreach ($domains as $domain) {
						$selected = ($domain['domain_uuid'] == $domain_uuid) ? 'selected' : '';
						echo "				<option value='".escape($domain['domain_uuid'])."' ".$selected.">".escape($domain['domain_name'])."</option>\n";
					}
					echo "			</select>\n";
					echo "			<input type='submit' class='btn' value='".$text['button-purchase']."'>\n";
					echo "			<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
					echo "		</form>\n";
					echo "	</td>\n";
				}
				echo "</tr>\n";
			}

			echo "</table>\n";
			echo "</div>\n";
		} else {
			if (empty($error_message)) {
				echo "<div class='card'>\n";
				echo "	<div class='subheading'>".$text['message-no-results']."</div>\n";
				echo "</div>\n";
			}
		}
	}

	echo "<br />\n";

//include the footer
	require_once "resources/footer.php";

?>

