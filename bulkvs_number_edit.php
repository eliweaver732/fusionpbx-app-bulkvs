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
	if (!permission_exists('bulkvs_edit')) {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//initialize the settings object
	$settings = new settings(['database' => $database, 'domain_uuid' => $domain_uuid]);

//get http variables
	$tn = $_GET['tn'] ?? '';
	$portout_pin = $_POST['portout_pin'] ?? '';
	$cnam = $_POST['cnam'] ?? '';

//process form submission
	if (!empty($_POST['action']) && $_POST['action'] == 'save' && !empty($tn)) {
		$portout_pin = $_POST['portout_pin'] ?? null;
		$cnam = $_POST['cnam'] ?? null;

		try {
			require_once "resources/classes/bulkvs_api.php";
			$bulkvs_api = new bulkvs_api($settings);
			$bulkvs_api->updateNumber($tn, $portout_pin, $cnam);
			
			message::add($text['message-update']);
			header("Location: bulkvs_numbers.php");
			return;
		} catch (Exception $e) {
			message::add($text['message-api-error'] . ': ' . $e->getMessage(), 'negative');
		}
	}

//get current number details from API
	$current_portout_pin = '';
	$current_cnam = '';
	$trunk_group = '';
	if (!empty($tn)) {
		try {
			require_once "resources/classes/bulkvs_api.php";
			$bulkvs_api = new bulkvs_api($settings);
			$trunk_group = $settings->get('bulkvs', 'trunk_group', '');
			$numbers = $bulkvs_api->getNumbers($trunk_group);
			
			// Find the specific number
			if (isset($numbers['data']) && is_array($numbers['data'])) {
				$numbers = $numbers['data'];
			} elseif (is_array($numbers)) {
				// numbers is already an array
			} else {
				$numbers = [];
			}
			
			foreach ($numbers as $number) {
				$number_tn = $number['tn'] ?? $number['telephoneNumber'] ?? '';
				if ($number_tn == $tn) {
					$current_portout_pin = $number['portoutPin'] ?? '';
					$current_cnam = $number['cnam'] ?? '';
					break;
				}
			}
		} catch (Exception $e) {
			message::add($text['message-api-error'] . ': ' . $e->getMessage(), 'negative');
		}
	}

//set default values
	if (empty($portout_pin)) {
		$portout_pin = $current_portout_pin;
	}
	if (empty($cnam)) {
		$cnam = $current_cnam;
	}

//create token
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//include the header
	$document['title'] = $text['title-bulkvs-number-edit'];
	require_once "resources/header.php";

//show the content
	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['title-bulkvs-number-edit']."</b></div>\n";
	echo "	<div class='actions'>\n";
	echo button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>'arrow-left','link'=>'bulkvs_numbers.php']);
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	echo "<form name='frm' id='frm' method='post' action=''>\n";
	echo "<input type='hidden' name='action' value='save'>\n";
	echo "<input type='hidden' name='tn' value='".escape($tn)."'>\n";

	echo "<div class='card'>\n";
	echo "	<div class='subheading'>".$text['label-telephone-number'].": ".escape($tn)."</div>\n";
	echo "</div>\n";
	echo "<br />\n";

	echo "<div class='card'>\n";
	echo "	<div class='subheading'>".$text['title-bulkvs-number-edit']."</div>\n";
	echo "	<div class='content'>\n";
	echo "		<table class='no_hover'>\n";
	echo "			<tr>\n";
	echo "				<td class='vncell' style='vertical-align: top;'>".$text['label-portout-pin']."</td>\n";
	echo "				<td class='vtable'><input type='text' class='formfld' name='portout_pin' value='".escape($portout_pin)."'></td>\n";
	echo "			</tr>\n";
	echo "			<tr>\n";
	echo "				<td class='vncell' style='vertical-align: top;'>".$text['label-cnam']."</td>\n";
	echo "				<td class='vtable'><input type='text' class='formfld' name='cnam' value='".escape($cnam)."'></td>\n";
	echo "			</tr>\n";
	echo "		</table>\n";
	echo "	</div>\n";
	echo "</div>\n";
	echo "<br />\n";

	echo "<div class='card'>\n";
	echo "	<div class='content'>\n";
	echo "		<input type='submit' class='btn' value='".$text['button-save']."'>\n";
	echo "	</div>\n";
	echo "</div>\n";

	echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
	echo "</form>\n";

//include the footer
	require_once "resources/footer.php";

?>

