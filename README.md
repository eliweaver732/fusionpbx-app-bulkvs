# BulkVS FusionPBX App

A FusionPBX application that integrates with the BulkVS API to manage phone numbers, update Portout PIN and CNAM information, and purchase new phone numbers directly from the FusionPBX interface.

## Overview

This app provides seamless integration between FusionPBX and BulkVS, allowing administrators to:

- View all phone numbers in their BulkVS account filtered by trunk group
- Update Portout PIN and CNAM for individual numbers
- Search for available phone numbers by area code (NPA) or area code + exchange (NPANXX)
- Purchase phone numbers and automatically create destinations in FusionPBX

## Features

- **Number Management**: View and manage all BulkVS numbers filtered by configured trunk group
- **Number Editing**: Update Portout PIN and CNAM values for individual numbers
- **Number Search**: Search for available numbers by NPA (3-digit area code) or NPANXX (6-digit area code + exchange)
- **Number Purchase**: Purchase numbers directly from the interface with automatic destination creation
- **Permission-Based Access**: Granular permissions for viewing, editing, searching, and purchasing
- **FusionPBX Standards**: Built using FusionPBX frameworks and follows standard app patterns
- **No External Dependencies**: Uses standard PHP cURL library (no additional packages required)

## Requirements

- FusionPBX installation
- PHP with cURL extension enabled
- BulkVS API account with API credentials
- Valid BulkVS trunk group configured

## Installation

1. Copy the `bulkvs` directory to your FusionPBX `app/` directory:
   ```bash
   cp -r bulkvs /var/www/fusionpbx/app/
   ```

2. Navigate to **Advanced > Upgrade** in FusionPBX and run the upgrade to register the app

3. Configure the app settings (see Configuration section below)

## Configuration

Before using the app, you must configure the BulkVS API credentials and trunk group in FusionPBX Default Settings:

1. Navigate to **Advanced > Default Settings**
2. Configure the following settings under the `bulkvs` category:

   - **bulkvs/api_key**: Your BulkVS API Key/Username
   - **bulkvs/api_secret**: Your BulkVS API Secret
   - **bulkvs/trunk_group**: The trunk group name to filter numbers (optional, but recommended)
   - **bulkvs/api_url**: BulkVS API Base URL (default: `https://portal.bulkvs.com/api/v1.0`)

## Permissions

The app includes four permissions that can be assigned to user groups:

- **bulkvs_view**: View BulkVS numbers list
- **bulkvs_edit**: Edit number details (Portout PIN and CNAM)
- **bulkvs_search**: Search for available numbers
- **bulkvs_purchase**: Purchase numbers

By default, these permissions are assigned to `superadmin` and `admin` groups.

To assign permissions to other groups:
1. Navigate to **Advanced > Groups**
2. Select the group you want to modify
3. Add the appropriate BulkVS permissions

## Usage

### Viewing Numbers

1. Navigate to **Switch > BulkVS > Numbers**
2. The page displays all numbers from your BulkVS account that match the configured trunk group
3. Numbers are displayed in a table showing:
   - Telephone Number
   - Trunk Group
   - Portout PIN
   - CNAM

### Editing Number Details

1. From the Numbers page, click on a number or use the Edit button
2. Update the Portout PIN and/or CNAM fields
3. Click **Save** to update the number in BulkVS

### Searching for Numbers

1. Navigate to **Switch > BulkVS > Search & Purchase**
2. Enter either:
   - **NPA**: 3-digit area code (e.g., `415`)
   - **NPANXX**: 6-digit area code + exchange (e.g., `415555`)
3. Click **Search** to view available numbers
4. Results show:
   - Telephone Number
   - Rate Center
   - LATA

### Purchasing Numbers

1. After searching for numbers, select a domain from the dropdown
2. Click **Purchase** next to the number you want to buy
3. The number will be:
   - Purchased in BulkVS and assigned to your trunk group
   - Automatically created as a destination in the selected FusionPBX domain
   - Ready to use for routing calls

## File Structure

```
bulkvs/
├── app_config.php              # App configuration, permissions, and default settings
├── app_menu.php                # Menu items for the app
├── app_languages.php           # Language strings for UI elements
├── bulkvs_numbers.php          # Main numbers list page
├── bulkvs_number_edit.php      # Number edit page
├── bulkvs_search.php           # Search and purchase page
└── resources/
    └── classes/
        └── bulkvs_api.php      # BulkVS API client class
```

## API Client

The `bulkvs_api` class provides methods for interacting with the BulkVS API:

- `getNumbers($trunk_group)`: Retrieve numbers filtered by trunk group
- `updateNumber($tn, $portout_pin, $cnam)`: Update number details
- `searchNumbers($npa, $npanxx)`: Search for available numbers
- `purchaseNumber($tn, $trunk_group)`: Purchase a number

## API Documentation

For detailed information about the BulkVS API, refer to the official documentation:
https://portal.bulkvs.com/api/v1.0/documentation

## Troubleshooting

### Numbers Not Appearing

- Verify your API credentials are correct in Default Settings
- Ensure the trunk group matches exactly (case-sensitive)
- Check that your BulkVS account has numbers assigned to the specified trunk group

### API Errors

- Verify your API credentials are valid
- Check that your BulkVS account has sufficient permissions
- Ensure the API URL is correct (default should work)
- Check FusionPBX error logs for detailed error messages

### Purchase Failures

- Ensure the trunk group is configured in Default Settings
- Verify you have permissions to purchase numbers in BulkVS
- Check that you have sufficient account balance (if required by BulkVS)
- Verify domain permissions if purchasing to a different domain

## License

This app is licensed under the Mozilla Public License 1.1 (MPL 1.1), consistent with FusionPBX.

## Support

For issues related to:
- **BulkVS API**: Contact BulkVS support
- **FusionPBX**: Visit the FusionPBX community forums
- **This App**: Check the repository issues or create a new issue

## Version

Current Version: 1.0

## Contributing

Contributions are welcome! Please ensure your code follows FusionPBX coding standards and includes appropriate error handling.
