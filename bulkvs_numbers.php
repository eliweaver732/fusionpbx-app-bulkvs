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
	} catch (Exception $e) {
		$error_message = $e->getMessage();
		message::add($text['message-api-error'] . ': ' . $error_message, 'negative');
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
	if (!empty($numbers)) {
		echo "<div class='count'>".number_format(count($numbers))."</div>";
	}
	echo "</div>\n";
	echo "	<div class='actions'>\n";
	if (permission_exists('bulkvs_search')) {
		echo button::create(['type'=>'button','label'=>$text['title-bulkvs-search'],'icon'=>'search','link'=>'bulkvs_search.php']);
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

	if (!empty($numbers)) {
		echo "<div class='card'>\n";
		echo "<table class='list'>\n";
		echo "<tr class='list-header'>\n";
		echo "	<th>".$text['label-telephone-number']."</th>\n";
		echo "	<th>".$text['label-trunk-group']."</th>\n";
		echo "	<th>".$text['label-portout-pin']."</th>\n";
		echo "	<th>".$text['label-cnam']."</th>\n";
		if (permission_exists('bulkvs_edit')) {
			echo "	<td class='action-button'>&nbsp;</td>\n";
		}
		echo "</tr>\n";

		foreach ($numbers as $number) {
			$tn = $number['tn'] ?? $number['telephoneNumber'] ?? '';
			$tg = $number['trunkGroup'] ?? '';
			$portout_pin = $number['portoutPin'] ?? '';
			$cnam = $number['cnam'] ?? '';

			//create the row link
			$list_row_url = '';
			if (permission_exists('bulkvs_edit')) {
				$list_row_url = "bulkvs_number_edit.php?tn=".urlencode($tn);
			}

			//show the data
			echo "<tr class='list-row'".(!empty($list_row_url) ? " href='".$list_row_url."'" : "").">\n";
			echo "	<td class='no-wrap'>";
			if (permission_exists('bulkvs_edit')) {
				echo "		<a href='".$list_row_url."'>".escape($tn)."</a>\n";
			} else {
				echo "		".escape($tn);
			}
			echo "	</td>\n";
			echo "	<td>".escape($tg)."&nbsp;</td>\n";
			echo "	<td>".escape($portout_pin)."&nbsp;</td>\n";
			echo "	<td>".escape($cnam)."&nbsp;</td>\n";
			if (permission_exists('bulkvs_edit')) {
				echo "	<td class='action-button'>";
				echo button::create(['type'=>'button','title'=>$text['button-edit'],'icon'=>$settings->get('theme', 'button_icon_edit'),'link'=>$list_row_url]);
				echo "	</td>\n";
			}
			echo "</tr>\n";
		}

		echo "</table>\n";
		echo "</div>\n";
	} else {
		if (empty($error_message)) {
			echo "<div class='card'>\n";
			echo "	<div class='subheading'>".$text['message-no-numbers']."</div>\n";
			echo "</div>\n";
		}
	}

	echo "<br />\n";

//include the footer
	require_once "resources/footer.php";

?>

