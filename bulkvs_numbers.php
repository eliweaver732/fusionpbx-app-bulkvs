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
	require_once dirname(__DIR__, 2) . "/resources/paging.php";

//check permissions
	if (!permission_exists('bulkvs_view')) {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//initialize the settings object
	$settings = new settings(['database' => $database, 'domain_uuid' => $domain_uuid]);

//get trunk group from settings
	$trunk_group = $settings->get('bulkvs', 'trunk_group', '');

//get numbers from BulkVS API
	$numbers = [];
	$error_message = '';
	try {
		require_once "resources/classes/bulkvs_api.php";
		$bulkvs_api = new bulkvs_api($settings);
		$api_response = $bulkvs_api->getNumbers($trunk_group);
		
		// Handle API response - it may be an array of numbers or an object with a data property
		if (isset($api_response['data']) && is_array($api_response['data'])) {
			$numbers = $api_response['data'];
		} elseif (is_array($api_response)) {
			$numbers = $api_response;
		}
		
		// Filter out empty/invalid entries
		$numbers = array_filter($numbers, function($number) {
			$tn = $number['TN'] ?? $number['tn'] ?? $number['telephoneNumber'] ?? '';
			return !empty($tn);
		});
		$numbers = array_values($numbers); // Re-index array
	} catch (Exception $e) {
		$error_message = $e->getMessage();
		message::add($text['message-api-error'] . ': ' . $error_message, 'negative');
	}

//prepare to page the results
	$num_rows = count($numbers);
	$rows_per_page = $settings->get('domain', 'paging', 50);
	$param = "";
	if (!empty($_GET['page'])) {
		$page = $_GET['page'];
	}
	if (!isset($page)) { $page = 0; $_GET['page'] = 0; }
	[$paging_controls, $rows_per_page] = paging($num_rows, $param, $rows_per_page);
	[$paging_controls_mini, $rows_per_page] = paging($num_rows, $param, $rows_per_page, true);
	$offset = $rows_per_page * $page;
	
	// Slice the results array for pagination
	$paginated_numbers = [];
	if (!empty($numbers)) {
		$paginated_numbers = array_slice($numbers, $offset, $rows_per_page);
	}

//build domain lookup map for paginated numbers
	$domain_map = [];
	if (!empty($paginated_numbers)) {
		// Extract 10-digit numbers from BulkVS 11-digit numbers
		$tn_10_digit = [];
		foreach ($paginated_numbers as $number) {
			$tn = $number['TN'] ?? $number['tn'] ?? $number['telephoneNumber'] ?? '';
			if (!empty($tn)) {
				// Convert 11-digit to 10-digit (remove leading "1")
				$tn_10 = preg_replace('/^1/', '', $tn);
				if (strlen($tn_10) == 10) {
					$tn_10_digit[] = $tn_10;
				}
			}
		}
		
		// Query destinations for these numbers
		if (!empty($tn_10_digit)) {
			$placeholders = [];
			$parameters = [];
			foreach ($tn_10_digit as $index => $tn_10) {
				$placeholders[] = ':tn_' . $index;
				$parameters['tn_' . $index] = $tn_10;
			}
			
			$sql = "select distinct destination_number, domain_uuid ";
			$sql .= "from v_destinations ";
			$sql .= "where destination_number in (" . implode(', ', $placeholders) . ") ";
			$sql .= "and destination_enabled = 'true' ";
			$destinations = $database->select($sql, $parameters, 'all');
			unset($sql, $parameters);
			
			// Build map of destination_number -> domain_uuid
			$domain_uuids = [];
			foreach ($destinations as $dest) {
				$dest_number = $dest['destination_number'] ?? '';
				$dest_domain_uuid = $dest['domain_uuid'] ?? '';
				if (!empty($dest_number) && !empty($dest_domain_uuid)) {
					// Store domain_uuid for this number (use first match if multiple)
					if (!isset($domain_uuids[$dest_number])) {
						$domain_uuids[$dest_number] = $dest_domain_uuid;
					}
				}
			}
			
			// Query domain names for unique domain_uuids
			if (!empty($domain_uuids)) {
				$unique_domain_uuids = array_unique(array_values($domain_uuids));
				$placeholders = [];
				$parameters = [];
				foreach ($unique_domain_uuids as $index => $domain_uuid) {
					$placeholders[] = ':domain_uuid_' . $index;
					$parameters['domain_uuid_' . $index] = $domain_uuid;
				}
				
				$sql = "select domain_uuid, domain_name ";
				$sql .= "from v_domains ";
				$sql .= "where domain_uuid in (" . implode(', ', $placeholders) . ") ";
				$domains = $database->select($sql, $parameters, 'all');
				unset($sql, $parameters);
				
				// Build map of domain_uuid -> domain_name
				$domain_names = [];
				foreach ($domains as $domain) {
					$domain_uuid = $domain['domain_uuid'] ?? '';
					$domain_name = $domain['domain_name'] ?? '';
					if (!empty($domain_uuid)) {
						$domain_names[$domain_uuid] = $domain_name;
					}
				}
				
				// Build final map: destination_number -> domain_name
				foreach ($domain_uuids as $dest_number => $domain_uuid) {
					if (isset($domain_names[$domain_uuid])) {
						$domain_map[$dest_number] = $domain_names[$domain_uuid];
					}
				}
			}
		}
	}

//create token
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//include the header
	$document['title'] = $text['title-bulkvs-numbers'];
	require_once "resources/header.php";

//show the content
	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['title-bulkvs-numbers']."</b>";
	if ($num_rows > 0) {
		echo "<div class='count'>".number_format($num_rows)."</div>";
	}
	echo "</div>\n";
	echo "	<div class='actions'>\n";
	if (permission_exists('bulkvs_search')) {
		echo button::create(['type'=>'button','label'=>$text['title-bulkvs-search'],'icon'=>'search','link'=>'bulkvs_search.php']);
	}
	if (!empty($paginated_numbers)) {
		echo "		<input type='text' id='table_filter' class='txt list-search' placeholder='Filter results...' style='margin-left: 15px; width: 200px;' onkeyup='filterTable()'>";
		echo "		<span id='filter_count' style='margin-left: 5px; color: #666; font-size: 12px;'></span>";
	}
	if ($paging_controls_mini != '') {
		echo "<span style='margin-left: 15px;'>".$paging_controls_mini."</span>";
	}
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	if (!empty($trunk_group)) {
		echo "<div class='card'>\n";
		echo "	<div class='subheading'>".$text['label-trunk-group'].": ".escape($trunk_group)."</div>\n";
		echo "</div>\n";
		echo "<br />\n";
	}

	echo $text['description-bulkvs-numbers']."\n";
	echo "<br /><br />\n";

	if (!empty($error_message)) {
		echo "<div class='alert alert-warning'>".escape($error_message)."</div>\n";
		echo "<br />\n";
	}

	if (!empty($paginated_numbers)) {
		echo "<div class='card'>\n";
		echo "<table class='list' id='numbers_table'>\n";
		echo "<tr class='list-header'>\n";
		echo "	<th>".$text['label-telephone-number']."</th>\n";
		echo "	<th>".$text['label-status']."</th>\n";
		echo "	<th>".$text['label-activation-date']."</th>\n";
		echo "	<th>".$text['label-rate-center']."</th>\n";
		echo "	<th>".$text['label-tier']."</th>\n";
		echo "	<th>".$text['label-lidb']."</th>\n";
		echo "	<th>".$text['label-notes']."</th>\n";
		echo "	<th>".$text['label-domain']."</th>\n";
		echo "</tr>\n";

		foreach ($paginated_numbers as $number) {
			// Extract fields from API response (handling nested structures)
			$tn = $number['TN'] ?? $number['tn'] ?? $number['telephoneNumber'] ?? '';
			$status = $number['Status'] ?? $number['status'] ?? '';
			$activation_date = '';
			$rate_center = '';
			$tier = '';
			$lidb = '';
			$notes = '';
			
			// Extract nested fields
			if (isset($number['TN Details']) && is_array($number['TN Details'])) {
				$tn_details = $number['TN Details'];
				$activation_date = $tn_details['Activation Date'] ?? $tn_details['activation_date'] ?? '';
				$rate_center = $tn_details['Rate Center'] ?? $tn_details['rate_center'] ?? '';
				$tier = $tn_details['Tier'] ?? $tn_details['tier'] ?? '';
			}
			
			// LIDB is at top level
			$lidb = $number['Lidb'] ?? $number['lidb'] ?? '';
			
			// Notes (ReferenceID)
			$notes = $number['ReferenceID'] ?? $number['referenceID'] ?? '';
			
			// Skip rows with no telephone number
			if (empty($tn)) {
				continue;
			}
			
			// Format activation date if present
			if (!empty($activation_date)) {
				// Try to format the date nicely
				$date_timestamp = strtotime($activation_date);
				if ($date_timestamp !== false) {
					$activation_date = date('Y-m-d H:i', $date_timestamp);
				}
			}
			
			// Look up domain for this number
			$domain_name = '';
			if (!empty($tn)) {
				// Convert 11-digit to 10-digit (remove leading "1")
				$tn_10 = preg_replace('/^1/', '', $tn);
				if (strlen($tn_10) == 10 && isset($domain_map[$tn_10])) {
					$domain_name = $domain_map[$tn_10];
				}
			}

			//show the data
			echo "<tr class='list-row'>\n";
			echo "	<td class='no-wrap'>".escape($tn)."</td>\n";
			echo "	<td>".escape($status)."&nbsp;</td>\n";
			echo "	<td class='no-wrap'>".escape($activation_date)."&nbsp;</td>\n";
			echo "	<td>".escape($rate_center)."&nbsp;</td>\n";
			echo "	<td>".escape($tier)."&nbsp;</td>\n";
			echo "	<td>".escape($lidb)."&nbsp;</td>\n";
			echo "	<td>".escape($notes)."&nbsp;</td>\n";
			echo "	<td>".escape($domain_name)."&nbsp;</td>\n";
			echo "</tr>\n";
		}

		echo "</table>\n";
		echo "</div>\n";
		echo "<br />\n";
		if ($paging_controls != '') {
			echo "<div align='center'>".$paging_controls."</div>\n";
		}
	} else {
		if (empty($error_message)) {
			echo "<div class='card'>\n";
			echo "	<div class='subheading'>".$text['message-no-numbers']."</div>\n";
			echo "</div>\n";
		}
	}

	echo "<br />\n";

//add client-side table filtering script
	if (!empty($paginated_numbers)) {
		$total_on_page = count($paginated_numbers);
		echo "<script>\n";
		echo "var totalRows = ".$total_on_page.";\n";
		echo "function filterTable() {\n";
		echo "	var input = document.getElementById('table_filter');\n";
		echo "	var filter = input.value.toLowerCase();\n";
		echo "	var table = document.getElementById('numbers_table');\n";
		echo "	var tr = table.getElementsByTagName('tr');\n";
		echo "	var visibleCount = 0;\n";
		echo "	\n";
		echo "	// Start from index 1 to skip header row\n";
		echo "	for (var i = 1; i < tr.length; i++) {\n";
		echo "		var td = tr[i].getElementsByTagName('td');\n";
		echo "		var found = false;\n";
		echo "		\n";
		echo "		// Check each cell in the row\n";
		echo "		for (var j = 0; j < td.length; j++) {\n";
		echo "			if (td[j]) {\n";
		echo "				var txtValue = td[j].textContent || td[j].innerText;\n";
		echo "				if (txtValue.toLowerCase().indexOf(filter) > -1) {\n";
		echo "					found = true;\n";
		echo "					break;\n";
		echo "				}\n";
		echo "			}\n";
		echo "		}\n";
		echo "		\n";
		echo "		if (found) {\n";
		echo "			tr[i].style.display = '';\n";
		echo "			visibleCount++;\n";
		echo "		} else {\n";
		echo "			tr[i].style.display = 'none';\n";
		echo "		}\n";
		echo "	}\n";
		echo "	\n";
		echo "	// Update filter count\n";
		echo "	var countElement = document.getElementById('filter_count');\n";
		echo "	if (filter === '') {\n";
		echo "		countElement.textContent = '';\n";
		echo "	} else {\n";
		echo "		countElement.textContent = '(' + visibleCount + '/' + totalRows + ')';\n";
		echo "	}\n";
		echo "}\n";
		echo "// Initialize count on page load\n";
		echo "document.addEventListener('DOMContentLoaded', function() {\n";
		echo "	filterTable();\n";
		echo "});\n";
		echo "</script>\n";
	}

//include the footer
	require_once "resources/footer.php";

?>

