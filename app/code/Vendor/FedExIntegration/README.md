# Vendor_FedExIntegration

Magento 2.4.x module for FedEx REST APIs using OAuth 2.0 Client Credentials
authentication with FedEx API Key (Client ID) and Secret Key (Client Secret).

## Features

- Admin configuration under **Stores > Configuration > Sales > Delivery Methods > FedEx REST Integration**.
- Sandbox and production endpoint selection.
- Encrypted API Key and Secret Key configuration fields.
- OAuth token service with Magento cache storage and pre-expiration refresh.
- Authenticated REST client with one-time 401 token refresh/retry.
- Live checkout rates for:
  - FedEx Ground
  - FedEx Express Saver
  - FedEx 2Day
  - FedEx Standard Overnight
  - FedEx Priority Overnight
- Shipment creation through the FedEx Ship API.
- PDF label persistence under `pub/media/fedex_labels`.
- Magento shipment tracking attachment.
- Track API status/event retrieval.
- Dedicated sanitized logging at `var/log/fedex_integration.log`.

## Installation

1. Copy this module to:

   ```text
   app/code/Vendor/FedExIntegration
   ```

2. Enable and install:

   ```bash
   bin/magento module:enable Vendor_FedExIntegration
   bin/magento setup:upgrade
   bin/magento setup:di:compile
   bin/magento cache:flush
   ```

3. Configure Magento shipping origin:

   ```text
   Stores > Configuration > Sales > Shipping Settings > Origin
   ```

4. Configure FedEx REST Integration:

   ```text
   Stores > Configuration > Sales > Delivery Methods > FedEx REST Integration
   ```

   Required fields:

   - Enabled
   - Environment
   - API Key (Client ID)
   - Secret Key (Client Secret)
   - FedEx Account Number
   - Shipper Phone (required for Ship API)

## Configuration Paths

The carrier stores configuration under `carriers/fedex_rest/*`.

Important keys:

- `active`
- `environment`
- `api_key`
- `secret_key`
- `account_number`
- `shipper_company`
- `shipper_phone`
- `allowed_methods`
- `timeout`
- `token_ttl_buffer`

## FedEx API Endpoints

Defaults are provided in `etc/config.xml`:

| Environment | Base URL |
| --- | --- |
| Sandbox | `https://apis-sandbox.fedex.com` |
| Production | `https://apis.fedex.com` |

| API | Path |
| --- | --- |
| OAuth | `/oauth/token` |
| Rates and Transit Times | `/rate/v1/rates/quotes` |
| Ship | `/ship/v1/shipments` |
| Track | `/track/v1/trackingnumbers` |

## Request/Response Mappings

### OAuth API

Request:

```http
POST /oauth/token
Content-Type: application/x-www-form-urlencoded

grant_type=client_credentials&client_id=<api-key>&client_secret=<secret-key>
```

Response fields used:

```json
{
  "access_token": "eyJ...",
  "expires_in": 3600
}
```

The token is cached using Magento cache. The configured refresh buffer is
subtracted from `expires_in`, so the cached token expires before FedEx expires it.

### Rates API

Magento `RateRequest` fields are mapped to:

```json
{
  "accountNumber": { "value": "123456789" },
  "requestedShipment": {
    "shipper": {
      "address": {
        "streetLines": ["1 Origin St"],
        "city": "Memphis",
        "stateOrProvinceCode": "TN",
        "postalCode": "38116",
        "countryCode": "US"
      }
    },
    "recipient": {
      "address": {
        "streetLines": ["100 Market St"],
        "city": "San Francisco",
        "stateOrProvinceCode": "CA",
        "postalCode": "94105",
        "countryCode": "US",
        "residential": true
      }
    },
    "pickupType": "USE_SCHEDULED_PICKUP",
    "packagingType": "YOUR_PACKAGING",
    "rateRequestType": ["ACCOUNT", "LIST"],
    "requestedPackageLineItems": [
      {
        "groupPackageCount": 1,
        "weight": { "units": "LB", "value": 5.0 },
        "dimensions": { "length": 10, "width": 8, "height": 4, "units": "IN" }
      }
    ]
  }
}
```

Response fields used:

```json
{
  "output": {
    "rateReplyDetails": [
      {
        "serviceType": "FEDEX_2_DAY",
        "ratedShipmentDetails": [
          {
            "totalNetCharge": 42.75,
            "currency": "USD"
          }
        ]
      }
    ]
  }
}
```

The carrier filters `serviceType` against configured allowed methods and returns
Magento checkout rates.

### Ship API

`ShipmentServiceInterface::createShipment($shipment, $options)` builds:

```json
{
  "labelResponseOptions": "LABEL",
  "accountNumber": { "value": "123456789" },
  "requestedShipment": {
    "shipDatestamp": "2026-06-08",
    "pickupType": "USE_SCHEDULED_PICKUP",
    "serviceType": "FEDEX_2_DAY",
    "packagingType": "YOUR_PACKAGING",
    "shipper": {
      "contact": { "companyName": "Store", "phoneNumber": "15551234567" },
      "address": { "postalCode": "38116", "countryCode": "US" }
    },
    "recipients": [
      {
        "contact": { "personName": "Jane Doe", "phoneNumber": "15557654321" },
        "address": { "postalCode": "94105", "countryCode": "US" }
      }
    ],
    "shippingChargesPayment": {
      "paymentType": "SENDER",
      "payor": {
        "responsibleParty": {
          "accountNumber": { "value": "123456789" }
        }
      }
    },
    "labelSpecification": {
      "imageType": "PDF",
      "labelStockType": "PAPER_85X11_TOP_HALF_LABEL"
    },
    "requestedPackageLineItems": [
      {
        "weight": { "units": "LB", "value": 5.0 },
        "dimensions": { "length": 10, "width": 8, "height": 4, "units": "IN" }
      }
    ]
  }
}
```

Response fields used:

```json
{
  "output": {
    "transactionShipments": [
      {
        "masterTrackingNumber": "123456789012",
        "pieceResponses": [
          {
            "trackingNumber": "123456789012",
            "packageDocuments": [
              { "encodedLabel": "JVBERi0x..." }
            ]
          }
        ]
      }
    ]
  }
}
```

The service decodes `encodedLabel`, saves a PDF under `fedex_labels`, adds a
shipment track with carrier code `fedex_rest`, sets the Magento shipment label
when available, and saves the shipment.

Example:

```php
$result = $shipmentService->createShipment($shipment, [
    'service_type' => 'FEDEX_2_DAY',
    'weight' => 5.0,
    'dimensions' => ['length' => 10, 'width' => 8, 'height' => 4],
]);
```

### Track API

Request:

```json
{
  "includeDetailedScans": true,
  "trackingInfo": [
    {
      "trackingNumberInfo": {
        "trackingNumber": "123456789012"
      }
    }
  ]
}
```

Response fields used:

```json
{
  "output": {
    "completeTrackResults": [
      {
        "trackResults": [
          {
            "latestStatusDetail": {
              "code": "DL",
              "description": "Delivered"
            },
            "scanEvents": [
              {
                "date": "2026-06-08T10:30:00-05:00",
                "eventDescription": "Delivered",
                "scanLocation": {
                  "city": "Austin",
                  "stateOrProvinceCode": "TX",
                  "countryCode": "US"
                }
              }
            ]
          }
        ]
      }
    ]
  }
}
```

## Security and Logging

- API Key and Secret Key use Magento's encrypted config backend model.
- Secret values, OAuth tokens, authorization headers, labels, and documents are
  redacted before logging.
- Logs are written to `var/log/fedex_integration.log`.
- Ship API responses returned by `ShipmentServiceInterface` redact label content.

## Checkout Rate Troubleshooting

If FedEx methods do not appear in checkout:

1. Confirm the carrier is enabled for the current website/store view.
2. Confirm these required values are configured:
   - Environment
   - API Key (Client ID)
   - Secret Key (Client Secret)
   - FedEx Account Number
   - Magento shipping origin address
     - Country
     - Postcode
     - Region/State
     - City
3. Flush Magento config/cache after changing credentials:

   ```bash
   bin/magento cache:clean config
   bin/magento cache:flush
   ```

4. Review:

   ```text
   var/log/fedex_integration.log
   ```

   The module logs FedEx HTTP errors, invalid address/service responses, returned
   FedEx service codes, filtered service codes, and missing charges without
   logging secrets or labels.

   FedEx rate failures are intentionally kept out of the checkout UI. If FedEx
   returns no usable rates, Magento receives no FedEx method instead of a
   zero-price error row that customers could confuse with a selectable method.

5. For residential Ground deliveries, FedEx can return `GROUND_HOME_DELIVERY`.
   The module treats that as compatible with `FEDEX_GROUND`, so enabling FedEx
   Ground also allows the common residential Ground response.

The module falls back to Magento's configured Shipping Origin when checkout
rate requests do not include origin fields. If the log says the `origin` or
`destination` address is missing required fields, complete the named fields in
Magento admin or in the customer checkout address.

## Tests

Example PHPUnit tests are included under:

```text
app/code/Vendor/FedExIntegration/Test/Unit
```

In a Magento installation with PHPUnit configured:

```bash
vendor/bin/phpunit app/code/Vendor/FedExIntegration/Test/Unit
```

## Notes

- No database schema is required.
- The module uses Magento's built-in HTTP curl client and cache services.
- Package dimensions can be supplied on the rate request/shipment options; when
  absent, configured default package dimensions are used.
