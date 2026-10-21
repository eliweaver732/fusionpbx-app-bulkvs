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

/**
 * BulkVS Cache Management Class
 * Handles caching of BulkVS numbers and E911 records in PostgreSQL
 */
class bulkvs_cache {

	const SYNC_TYPES = ['numbers', 'e911'];

	/**
	 * First key of the two-int advisory lock. The second key is the index into SYNC_TYPES.
	 */
	const ADVISORY_LOCK_NAMESPACE = 0x42564B53;

	const CANCEL_CHECK_EVERY_ROWS = 50;

	/**
	 * Database object
	 * @var database
	 */
	private $database;

	/**
	 * Settings object
	 * @var settings
	 */
	private $settings;

	/**
	 * Called when the object is created
	 */
	public function __construct($database = null, $settings = null) {
		if ($database === null) {
			$this->database = database::new();
		} else {
			$this->database = $database;
		}
		
		if ($settings === null) {
			$this->settings = new settings(['database' => $this->database]);
		} else {
			$this->settings = $settings;
		}
	}

	/**
	 * Get numbers from cache filtered by trunk group
	 * @param string $trunk_group Trunk group name
	 * @return array Array of number records
	 */
	public function getNumbers($trunk_group = null) {
		$sql = "SELECT tn, status, activation_date, rate_center, tier, lidb, reference_id, ";
		$sql .= "sms, mms, portout_pin, trunk_group, data_json ";
		$sql .= "FROM v_bulkvs_numbers_cache ";
		
		$parameters = [];
		if (!empty($trunk_group)) {
			$sql .= "WHERE trunk_group = :trunk_group ";
			$parameters['trunk_group'] = $trunk_group;
		}
		
		$sql .= "ORDER BY tn ASC ";
		
		try {
			$results = $this->database->select($sql, $parameters, 'all');
		} catch (Exception $e) {
			error_log("BulkVS getNumbers error: " . $e->getMessage());
			$results = [];
		}
		
		// Convert database results to API-like format
		$numbers = [];
		foreach ($results as $row) {
			$number = [];
			
			// If data_json exists, use it as base and merge with individual fields
			if (!empty($row['data_json'])) {
				$number = json_decode($row['data_json'], true);
				if (!is_array($number)) {
					$number = [];
				}
			}
			
			// Override with individual fields (in case data_json is missing or incomplete)
			$number['TN'] = $row['tn'] ?? '';
			$number['Status'] = $row['status'] ?? '';
			$number['Lidb'] = $row['lidb'] ?? '';
			$number['ReferenceID'] = $row['reference_id'] ?? '';
			$number['Portout Pin'] = $row['portout_pin'] ?? '';
			
			// Handle nested TN Details structure
			if (!isset($number['TN Details'])) {
				$number['TN Details'] = [];
			}
			if (!empty($row['activation_date'])) {
				$number['TN Details']['Activation Date'] = $row['activation_date'];
			}
			if (!empty($row['rate_center'])) {
				$number['TN Details']['Rate Center'] = $row['rate_center'];
			}
			if (!empty($row['tier'])) {
				$number['TN Details']['Tier'] = $row['tier'];
			}
			
			// Handle Messaging structure
			if (!isset($number['Messaging'])) {
				$number['Messaging'] = [];
			}
			$number['Messaging']['Sms'] = isset($row['sms']) ? (bool)$row['sms'] : false;
			$number['Messaging']['Mms'] = isset($row['mms']) ? (bool)$row['mms'] : false;
			
			$numbers[] = $number;
		}
		
		return $numbers;
	}

	/**
	 * Get E911 records from cache
	 * @return array Array of E911 records
	 */
	public function getE911Records() {
		$sql = "SELECT tn, caller_name, address_line1, address_line2, city, state, zip, ";
		$sql .= "address_id, sms, data_json ";
		$sql .= "FROM v_bulkvs_e911_cache ";
		$sql .= "ORDER BY tn ASC ";
		
		$results = $this->database->select($sql, null, 'all') ?: [];
		
		// Convert database results to API-like format
		$records = [];
		foreach ($results as $row) {
			$record = [];
			
			// If data_json exists, use it as base
			if (!empty($row['data_json'])) {
				$record = json_decode($row['data_json'], true);
				if (!is_array($record)) {
					$record = [];
				}
			}
			
			// Override with individual fields
			$record['TN'] = $row['tn'] ?? '';
			$record['Caller Name'] = $row['caller_name'] ?? '';
			$record['Address Line 1'] = $row['address_line1'] ?? '';
			$record['Address Line 2'] = $row['address_line2'] ?? '';
			$record['City'] = $row['city'] ?? '';
			$record['State'] = $row['state'] ?? '';
			$record['Zip'] = $row['zip'] ?? '';
			$record['AddressID'] = $row['address_id'] ?? '';
			
			// Handle SMS array
			if (!empty($row['sms'])) {
				$sms_data = json_decode($row['sms'], true);
				if (is_array($sms_data)) {
					$record['Sms'] = $sms_data;
				}
			}
			
			$records[] = $record;
		}
		
		return $records;
	}

	/**
	 * Sync one cache type from the BulkVS API. This is the only writer of the cache.
	 *
	 * At most one sync per type runs at a time. The advisory lock belongs to the
	 * non-persistent database connection, so a crashed or killed request releases it.
	 * A cancelled sync keeps the rows it already upserted but skips the stale-row delete
	 * and the record counts, so it never removes data or toggles the refresh button.
	 * @param string $sync_type One of SYNC_TYPES
	 * @param callable|null $still_wanted fn(): bool, checked between rows. Null never cancels.
	 * @return bulkvs_sync_result
	 */
	public function sync(string $sync_type, ?callable $still_wanted = null): bulkvs_sync_result {
		$lock_key = array_search($sync_type, self::SYNC_TYPES, true);
		if (!$this->tryLock($lock_key)) {
			return bulkvs_sync_result::busy();
		}

		try {
			$this->updateSyncStatus($sync_type, [
				'sync_status' => 'in_progress',
				'last_sync_start' => date('Y-m-d H:i:s')
			]);

			$trunk_group = $this->settings->get('bulkvs', 'trunk_group', '');
			$before = $this->countRows($sync_type, $trunk_group);
			$records = $this->fetchRecords($sync_type, $trunk_group);

			if (!$this->upsertRows($sync_type, $records, $trunk_group, $still_wanted ?? function () { return true; })) {
				$this->updateSyncStatus($sync_type, [
					'sync_status' => 'cancelled',
					'last_sync_end' => date('Y-m-d H:i:s')
				]);
				return bulkvs_sync_result::cancelled();
			}

			$this->deleteMissing($sync_type, $records, $trunk_group);
			$after = $this->countRows($sync_type, $trunk_group);

			$this->updateSyncStatus($sync_type, [
				'sync_status' => 'success',
				'last_sync_end' => date('Y-m-d H:i:s'),
				'last_record_count' => $before,
				'current_record_count' => $after,
				'error_message' => null
			]);
			return bulkvs_sync_result::completed($after - $before, $after);
		} catch (Exception $e) {
			error_log("BulkVS $sync_type sync error: " . $e->getMessage());
			$this->updateSyncStatus($sync_type, [
				'sync_status' => 'error',
				'last_sync_end' => date('Y-m-d H:i:s'),
				'error_message' => $e->getMessage()
			]);
			return bulkvs_sync_result::failed($e->getMessage());
		} finally {
			$this->unlock($lock_key);
		}
	}

	/**
	 * True when the last completed sync changed what the page shows and nobody has acknowledged it.
	 * Numbers count deletions as changes; E911 counts only additions.
	 * @param string $sync_type One of SYNC_TYPES
	 * @return bool
	 */
	public function refreshAvailable(string $sync_type): bool {
		$sync_status = $this->getSyncStatus($sync_type);
		if (!$sync_status) {
			return false;
		}

		$current_count = (int)$sync_status['current_record_count'];
		$last_count = (int)$sync_status['last_record_count'];

		return $sync_type === 'numbers' ? $current_count !== $last_count : $current_count > $last_count;
	}

	/**
	 * Clear refreshAvailable() after the user reloads
	 * @param string $sync_type One of SYNC_TYPES
	 */
	public function acknowledgeRefresh(string $sync_type): void {
		$sync_status = $this->getSyncStatus($sync_type);
		if ($sync_status && isset($sync_status['current_record_count'])) {
			$this->updateSyncStatus($sync_type, [
				'last_record_count' => $sync_status['current_record_count']
			]);
		}
	}

	private function tryLock(int $lock_key): bool {
		$sql = sprintf("SELECT pg_try_advisory_lock(%d, %d)::int", self::ADVISORY_LOCK_NAMESPACE, $lock_key);
		return (int)$this->database->execute($sql, null, 'column') === 1;
	}

	private function unlock(int $lock_key): void {
		$sql = sprintf("SELECT pg_advisory_unlock(%d, %d)", self::ADVISORY_LOCK_NAMESPACE, $lock_key);
		$this->database->execute($sql, null, 'column');
	}

	private static function cacheTable(string $sync_type): string {
		return $sync_type === 'numbers' ? 'v_bulkvs_numbers_cache' : 'v_bulkvs_e911_cache';
	}

	private static function recordTn(array $record): string {
		return (string)($record['TN'] ?? $record['tn'] ?? $record['telephoneNumber'] ?? '');
	}

	private function countRows(string $sync_type, $trunk_group): int {
		$sql = "SELECT COUNT(*) FROM " . self::cacheTable($sync_type) . " ";
		$parameters = null;
		if ($sync_type === 'numbers' && !empty($trunk_group)) {
			$sql .= "WHERE trunk_group = :trunk_group ";
			$parameters = ['trunk_group' => $trunk_group];
		}
		return (int)$this->database->select($sql, $parameters, 'column');
	}

	private function fetchRecords(string $sync_type, $trunk_group): array {
		require_once __DIR__ . "/bulkvs_api.php";
		$bulkvs_api = new bulkvs_api($this->settings);
		$response = $sync_type === 'numbers' ? $bulkvs_api->getNumbers($trunk_group) : $bulkvs_api->getE911Records();

		if (isset($response['data']) && is_array($response['data'])) {
			$records = $response['data'];
		} elseif (is_array($response)) {
			$records = $response;
		} else {
			$records = [];
		}

		return array_values(array_filter($records, function ($record) {
			return is_array($record) && self::recordTn($record) !== '';
		}));
	}

	/**
	 * @return bool False if $still_wanted said stop
	 */
	private function upsertRows(string $sync_type, array $records, $trunk_group, callable $still_wanted): bool {
		foreach ($records as $index => $record) {
			if ($index % self::CANCEL_CHECK_EVERY_ROWS === 0 && !$still_wanted()) {
				return false;
			}
			if ($sync_type === 'numbers') {
				$this->upsertNumber($record, $trunk_group);
			} else {
				$this->upsertE911($record);
			}
		}
		return $still_wanted();
	}

	/**
	 * Remove cached rows the API no longer returns. Skipped when the API returned
	 * nothing, so an empty response cannot wipe the cache. Numbers are pruned only within a trunk group.
	 */
	private function deleteMissing(string $sync_type, array $records, $trunk_group) {
		if (empty($records) || ($sync_type === 'numbers' && empty($trunk_group))) {
			return;
		}

		$placeholders = [];
		$parameters = [];
		foreach ($records as $index => $record) {
			$placeholders[] = ':tn_' . $index;
			$parameters['tn_' . $index] = self::recordTn($record);
		}

		$sql = "DELETE FROM " . self::cacheTable($sync_type) . " ";
		$sql .= "WHERE tn NOT IN (" . implode(', ', $placeholders) . ") ";
		if ($sync_type === 'numbers') {
			$sql .= "AND trunk_group = :trunk_group ";
			$parameters['trunk_group'] = $trunk_group;
		}
		$this->database->execute($sql, $parameters);
	}

	private function upsertNumber(array $number, $trunk_group) {
		$tn = self::recordTn($number);

		// Extract fields
		$status = $number['Status'] ?? $number['status'] ?? '';
		$lidb = $number['Lidb'] ?? $number['lidb'] ?? '';
		$reference_id = $number['ReferenceID'] ?? $number['referenceID'] ?? '';
		$portout_pin = $number['Portout Pin'] ?? $number['portoutPin'] ?? '';

		$activation_date = '';
		$rate_center = '';
		$tier = '';
		if (isset($number['TN Details']) && is_array($number['TN Details'])) {
			$tn_details = $number['TN Details'];
			$activation_date_raw = $tn_details['Activation Date'] ?? $tn_details['activation_date'] ?? '';

			// Normalize activation_date - fix malformed dates
			if (!empty($activation_date_raw)) {
				$activation_date = preg_replace('/(\d{4}-\d{2}-\d{2})\s+(\d{1,2})\s+(\d{2}:\d{2})/', '$1 $2:$3', $activation_date_raw);
				$activation_date = preg_replace('/(\d{4}-\d{2}-\d{2})\s+(\d{1,2})\s+(\d{2})\s+(\d{2})/', '$1 $2:$3:$4', $activation_date);

				try {
					$date_obj = new DateTime($activation_date);
					$activation_date = $date_obj->format('Y-m-d H:i:s');
				} catch (Exception $date_e) {
					error_log("BulkVS: Invalid activation_date format for TN $tn: '$activation_date_raw'");
					$activation_date = '';
				}
			}

			$rate_center = $tn_details['Rate Center'] ?? $tn_details['rate_center'] ?? '';
			$tier = $tn_details['Tier'] ?? $tn_details['tier'] ?? '';
		}

		$sms = false;
		$mms = false;
		if (isset($number['Messaging']) && is_array($number['Messaging'])) {
			$messaging = $number['Messaging'];
			if (isset($messaging['Sms'])) {
				$sms_val = $messaging['Sms'];
				$sms = ($sms_val === true || $sms_val === 'true' || $sms_val === 1 || $sms_val === '1');
			}
			if (isset($messaging['Mms'])) {
				$mms_val = $messaging['Mms'];
				$mms = ($mms_val === true || $mms_val === 'true' || $mms_val === 1 || $mms_val === '1');
			}
		}

		// Check if record exists
		$check_sql = "SELECT bulkvs_numbers_cache_uuid FROM v_bulkvs_numbers_cache WHERE tn = :tn";
		$check_params = ['tn' => $tn];
		if (!empty($trunk_group)) {
			$check_sql .= " AND trunk_group = :trunk_group";
			$check_params['trunk_group'] = $trunk_group;
		}
		$existing = $this->database->select($check_sql, $check_params, 'row');

		if (empty($existing)) {
			$sql = "INSERT INTO v_bulkvs_numbers_cache ";
			$sql .= "(bulkvs_numbers_cache_uuid, tn, status, activation_date, rate_center, tier, lidb, reference_id, ";
			$sql .= "sms, mms, portout_pin, trunk_group, data_json, last_updated, created) ";
			$sql .= "VALUES ";
			$sql .= "(gen_random_uuid(), :tn, :status, :activation_date, :rate_center, :tier, :lidb, :reference_id, ";
			$sql .= "CAST(:sms AS boolean), CAST(:mms AS boolean), :portout_pin, :trunk_group, :data_json::jsonb, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP) ";
		} else {
			$sql = "UPDATE v_bulkvs_numbers_cache SET ";
			$sql .= "status = :status, ";
			$sql .= "activation_date = :activation_date, ";
			$sql .= "rate_center = :rate_center, ";
			$sql .= "tier = :tier, ";
			$sql .= "lidb = :lidb, ";
			$sql .= "reference_id = :reference_id, ";
			$sql .= "sms = CAST(:sms AS boolean), ";
			$sql .= "mms = CAST(:mms AS boolean), ";
			$sql .= "portout_pin = :portout_pin, ";
			$sql .= "trunk_group = :trunk_group, ";
			$sql .= "data_json = :data_json::jsonb, ";
			$sql .= "last_updated = CURRENT_TIMESTAMP ";
			$sql .= "WHERE tn = :tn";
			if (!empty($trunk_group)) {
				$sql .= " AND trunk_group = :trunk_group";
			}
		}

		$parameters = [
			'tn' => $tn,
			'status' => $status,
			'activation_date' => !empty($activation_date) ? $activation_date : null,
			'rate_center' => $rate_center,
			'tier' => $tier,
			'lidb' => $lidb,
			'reference_id' => $reference_id,
			'sms' => $sms ? 1 : 0,
			'mms' => $mms ? 1 : 0,
			'portout_pin' => $portout_pin,
			'trunk_group' => $trunk_group,
			'data_json' => json_encode($number)
		];

		try {
			$this->database->execute($sql, $parameters);
		} catch (Exception $e) {
			error_log("BulkVS cache insert error for TN $tn: " . $e->getMessage());
		}
	}

	private function upsertE911(array $record) {
		$tn = self::recordTn($record);

		$caller_name = $record['Caller Name'] ?? $record['callerName'] ?? '';
		$address_line1 = $record['Address Line 1'] ?? $record['addressLine1'] ?? '';
		$address_line2 = $record['Address Line 2'] ?? $record['addressLine2'] ?? '';
		$city = $record['City'] ?? $record['city'] ?? '';
		$state = $record['State'] ?? $record['state'] ?? '';
		$zip = $record['Zip'] ?? $record['zip'] ?? '';
		$address_id = $record['AddressID'] ?? $record['addressID'] ?? '';

		$sms_array = [];
		if (isset($record['Sms']) && is_array($record['Sms'])) {
			$sms_array = $record['Sms'];
		}

		$sql_check = "SELECT bulkvs_e911_cache_uuid FROM v_bulkvs_e911_cache WHERE tn = :tn ";
		$existing = $this->database->select($sql_check, ['tn' => $tn], 'row');

		if (empty($existing)) {
			$sql = "INSERT INTO v_bulkvs_e911_cache ";
			$sql .= "(bulkvs_e911_cache_uuid, tn, caller_name, address_line1, address_line2, city, state, zip, ";
			$sql .= "address_id, sms, data_json, last_updated, created) ";
			$sql .= "VALUES ";
			$sql .= "(gen_random_uuid(), :tn, :caller_name, :address_line1, :address_line2, :city, :state, :zip, ";
			$sql .= ":address_id, :sms::jsonb, :data_json::jsonb, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP) ";
		} else {
			$sql = "UPDATE v_bulkvs_e911_cache SET ";
			$sql .= "caller_name = :caller_name, ";
			$sql .= "address_line1 = :address_line1, ";
			$sql .= "address_line2 = :address_line2, ";
			$sql .= "city = :city, ";
			$sql .= "state = :state, ";
			$sql .= "zip = :zip, ";
			$sql .= "address_id = :address_id, ";
			$sql .= "sms = :sms::jsonb, ";
			$sql .= "data_json = :data_json::jsonb, ";
			$sql .= "last_updated = CURRENT_TIMESTAMP ";
			$sql .= "WHERE tn = :tn ";
		}

		$parameters = [
			'tn' => $tn,
			'caller_name' => $caller_name,
			'address_line1' => $address_line1,
			'address_line2' => $address_line2,
			'city' => $city,
			'state' => $state,
			'zip' => $zip,
			'address_id' => $address_id,
			'sms' => json_encode($sms_array),
			'data_json' => json_encode($record)
		];

		try {
			$this->database->execute($sql, $parameters);
		} catch (Exception $e) {
			error_log("BulkVS E911 cache insert error for TN $tn: " . $e->getMessage());
		}
	}

	/**
	 * Get sync status for a sync type
	 * @param string $sync_type 'numbers' or 'e911'
	 * @return array|null Sync status array or null if not found
	 */
	public function getSyncStatus($sync_type) {
		$sql = "SELECT sync_type, last_sync_start, last_sync_end, last_record_count, ";
		$sql .= "current_record_count, sync_in_progress, sync_status, error_message ";
		$sql .= "FROM v_bulkvs_sync_status ";
		$sql .= "WHERE sync_type = :sync_type ";
		$sql .= "LIMIT 1 ";
		
		$result = $this->database->select($sql, ['sync_type' => $sync_type], 'row');
		return $result ?: null;
	}

	/**
	 * Update sync status
	 * @param string $sync_type 'numbers' or 'e911'
	 * @param array $data Status data to update
	 */
	private function updateSyncStatus($sync_type, $data) {
		$existing = $this->getSyncStatus($sync_type);
		
		if (empty($existing)) {
			$sql = "INSERT INTO v_bulkvs_sync_status ";
			$sql .= "(bulkvs_sync_status_uuid, sync_type, last_sync_start, last_sync_end, last_record_count, ";
			$sql .= "current_record_count, sync_status, error_message) ";
			$sql .= "VALUES ";
			$sql .= "(gen_random_uuid(), :sync_type, :last_sync_start, :last_sync_end, :last_record_count, ";
			$sql .= ":current_record_count, :sync_status, :error_message) ";
			
			$parameters = [
				'sync_type' => $sync_type,
				'last_sync_start' => $data['last_sync_start'] ?? null,
				'last_sync_end' => $data['last_sync_end'] ?? null,
				'last_record_count' => $data['last_record_count'] ?? 0,
				'current_record_count' => $data['current_record_count'] ?? 0,
				'sync_status' => $data['sync_status'] ?? 'success',
				'error_message' => $data['error_message'] ?? null
			];
			
			try {
				$this->database->execute($sql, $parameters);
			} catch (Exception $e) {
				error_log("BulkVS sync status insert error: " . $e->getMessage());
				throw $e;
			}
		} else {
			$sql = "UPDATE v_bulkvs_sync_status SET ";
			$updates = [];
			$parameters = ['sync_type' => $sync_type];
			
			if (isset($data['last_sync_start'])) {
				$updates[] = "last_sync_start = :last_sync_start";
				$parameters['last_sync_start'] = $data['last_sync_start'];
			}
			if (isset($data['last_sync_end'])) {
				$updates[] = "last_sync_end = :last_sync_end";
				$parameters['last_sync_end'] = $data['last_sync_end'];
			}
			if (isset($data['last_record_count'])) {
				$updates[] = "last_record_count = :last_record_count";
				$parameters['last_record_count'] = $data['last_record_count'];
			}
			if (isset($data['current_record_count'])) {
				$updates[] = "current_record_count = :current_record_count";
				$parameters['current_record_count'] = $data['current_record_count'];
			}
			if (isset($data['sync_status'])) {
				$updates[] = "sync_status = :sync_status";
				$parameters['sync_status'] = $data['sync_status'];
			}
			if (isset($data['error_message'])) {
				$updates[] = "error_message = :error_message";
				$parameters['error_message'] = $data['error_message'];
			}
			
			if (!empty($updates)) {
				$sql .= implode(', ', $updates);
				$sql .= " WHERE sync_type = :sync_type ";
				try {
					$this->database->execute($sql, $parameters);
				} catch (Exception $e) {
					error_log("BulkVS: Error executing sync status update: " . $e->getMessage());
					throw $e;
				}
			}
		}
	}

	/**
	 * Clear cache for a sync type
	 * @param string $sync_type 'numbers' or 'e911'
	 */
	public function clearCache($sync_type) {
		if ($sync_type === 'numbers') {
			$sql = "DELETE FROM v_bulkvs_numbers_cache ";
			$this->database->execute($sql, null);
		} elseif ($sync_type === 'e911') {
			$sql = "DELETE FROM v_bulkvs_e911_cache ";
			$this->database->execute($sql, null);
		}
	}

	/**
	 * Update a single number in cache
	 * @param string $tn Telephone number
	 * @param array $number_data Number data
	 */
	public function updateNumber($tn, $number_data) {
		// This will be called after editing a number
	}

	/**
	 * Delete a number from cache
	 * @param string $tn Telephone number
	 */
	public function deleteNumber($tn) {
		$sql = "DELETE FROM v_bulkvs_numbers_cache WHERE tn = :tn ";
		$this->database->execute($sql, ['tn' => $tn]);
	}

	/**
	 * Update a single E911 record in cache
	 * @param string $tn Telephone number
	 * @param array $e911_data E911 record data
	 */
	public function updateE911($tn, $e911_data) {
		// This will be called after editing an E911 record
	}

	/**
	 * Delete an E911 record from cache
	 * @param string $tn Telephone number
	 */
	public function deleteE911($tn) {
		$sql = "DELETE FROM v_bulkvs_e911_cache WHERE tn = :tn ";
		$this->database->execute($sql, ['tn' => $tn]);
	}

}

/**
 * Result of one bulkvs_cache::sync() call. The named constructors keep invalid
 * combinations, such as a cancelled result with counts, from being built.
 */
final class bulkvs_sync_result {

	const COMPLETED = 'completed';
	const CANCELLED = 'cancelled';
	const BUSY = 'busy';
	const FAILED = 'failed';

	private $status;
	private $net_change;
	private $total;
	private $message;

	private function __construct(string $status, int $net_change, int $total, ?string $message) {
		$this->status = $status;
		$this->net_change = $net_change;
		$this->total = $total;
		$this->message = $message;
	}

	public static function completed(int $net_change, int $total): self {
		return new self(self::COMPLETED, $net_change, $total, null);
	}

	public static function cancelled(): self {
		return new self(self::CANCELLED, 0, 0, null);
	}

	public static function busy(): self {
		return new self(self::BUSY, 0, 0, null);
	}

	public static function failed(string $message): self {
		return new self(self::FAILED, 0, 0, $message);
	}

	/**
	 * Wire shape for bulkvs_sync.php
	 * @param bool $refresh_available From bulkvs_cache::refreshAvailable(), not from this result's counts
	 * @return array
	 */
	public function toArray(bool $refresh_available): array {
		return [
			'status' => $this->status,
			'refresh_available' => $refresh_available,
			'message' => $this->message,
			'net_change' => $this->net_change,
			'total' => $this->total
		];
	}
}

?>
