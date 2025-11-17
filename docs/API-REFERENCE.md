# LGL API Reference

This document provides a comprehensive reference for the Little Green Light (LGL) Dynamic API endpoints used by the Integrate-LGL WordPress plugin.

## API Overview

**Base URL:** Configured in plugin settings (e.g., `https://api.littlegreenlight.com/api/v1`)

**Authentication:** Bearer Token (API Key)

**Content-Type:** `application/json`

**Rate Limits:** 300 requests per 5 minutes (60 requests/minute)

### Authentication

All API requests require a Bearer token passed in the Authorization header:

```http
Authorization: Bearer YOUR_API_KEY
Content-Type: application/json
Accept: application/json
```

## Rate Limiting

### Limits
- **Maximum:** 300 API calls per 5 minutes
- **Per Minute:** 60 calls/minute average
- **Recommended Delay:** 1-1.1 seconds between requests

### Rate Limit Headers (if provided by LGL)
```http
X-RateLimit-Limit: 300
X-RateLimit-Remaining: 299
X-RateLimit-Reset: 1616161616
```

### Plugin Rate Limiting
The plugin implements `RateLimiter` class to track and enforce limits:
- Tracks requests in 5-minute sliding window
- Adds delay when approaching limit
- Returns error if limit exceeded

## Constituents API

### Get Constituent

**Endpoint:** `GET /constituents/{constituent_id}.json`

**Purpose:** Retrieve constituent information by ID.

**Used By:** `Constituents::getConstituent()`

**Request:**
```http
GET /api/v1/constituents/12345.json
Authorization: Bearer YOUR_API_KEY
```

**Response (200 OK):**
```json
{
  "items": [
    {
      "id": 12345,
      "first_name": "John",
      "last_name": "Doe",
      "email_addresses": [
        {
          "address": "john@example.com",
          "is_primary": true
        }
      ],
      "phone_numbers": [
        {
          "number": "555-123-4567",
          "phone_type": "Home"
        }
      ],
      "street_addresses": [
        {
          "street": "123 Main St",
          "city": "New York",
          "state": "NY",
          "postal_code": "10001"
        }
      ]
    }
  ]
}
```

**Error Responses:**
- `404 Not Found` - Constituent not found
- `401 Unauthorized` - Invalid API key
- `429 Too Many Requests` - Rate limit exceeded

### Search Constituents

**Endpoint:** `GET /constituents.json?search={email}`

**Purpose:** Search for constituent by email address.

**Used By:** `Constituents::findConstituentByEmail()`

**Request:**
```http
GET /api/v1/constituents.json?search=john@example.com
Authorization: Bearer YOUR_API_KEY
```

**Response (200 OK):**
```json
{
  "items": [
    {
      "id": 12345,
      "first_name": "John",
      "last_name": "Doe",
      "email_addresses": [
        {
          "address": "john@example.com"
        }
      ]
    }
  ],
  "total_items": 1,
  "total_pages": 1,
  "page_number": 1
}
```

**Query Parameters:**
- `search` - Email address to search
- `limit` - Results per page (default: 25, max: 100)
- `offset` - Pagination offset

### Create Constituent

**Endpoint:** `POST /constituents.json`

**Purpose:** Create a new constituent record.

**Used By:** `Constituents::addConstituent()`

**Request:**
```http
POST /api/v1/constituents.json
Authorization: Bearer YOUR_API_KEY
Content-Type: application/json

{
  "first_name": "John",
  "last_name": "Doe",
  "constituent_type": "Individual",
  "email_addresses": [
    {
      "address": "john@example.com",
      "is_primary": true
    }
  ],
  "phone_numbers": [
    {
      "number": "555-123-4567",
      "phone_type": "Home",
      "is_primary": true
    }
  ],
  "street_addresses": [
    {
      "street": "123 Main St",
      "city": "New York",
      "state": "NY",
      "postal_code": "10001",
      "country": "United States",
      "is_primary": true
    }
  ]
}
```

**Response (201 Created):**
```json
{
  "id": 12345,
  "first_name": "John",
  "last_name": "Doe",
  "created_at": "2025-01-15T12:00:00Z"
}
```

**Error Responses:**
- `400 Bad Request` - Invalid data
- `422 Unprocessable Entity` - Validation errors
- `401 Unauthorized` - Invalid API key

### Update Constituent

**Endpoint:** `PATCH /constituents/{constituent_id}.json`

**Purpose:** Update existing constituent information.

**Used By:** `Constituents::updateConstituent()`

**Request:**
```http
PATCH /api/v1/constituents/12345.json
Authorization: Bearer YOUR_API_KEY
Content-Type: application/json

{
  "first_name": "John",
  "last_name": "Smith",
  "phone_numbers": [
    {
      "number": "555-987-6543",
      "phone_type": "Mobile"
    }
  ]
}
```

**Response (200 OK):**
```json
{
  "id": 12345,
  "first_name": "John",
  "last_name": "Smith",
  "updated_at": "2025-01-15T12:30:00Z"
}
```

## Gifts/Payments API

### Create Gift

**Endpoint:** `POST /constituents/{constituent_id}/gifts.json`

**Purpose:** Create a gift/payment record for a constituent.

**Used By:** `Payments::addGiftToConstituent()`

**Request:**
```http
POST /api/v1/constituents/12345/gifts.json
Authorization: Bearer YOUR_API_KEY
Content-Type: application/json

{
  "gift_date": "2025-01-15",
  "gift_type": "Cash",
  "amount": 150.00,
  "fund_id": 852,
  "campaign_id": 1234,
  "gift_category_id": 6047,
  "payment_type_id": 101,
  "gift_note": "Membership renewal - Supporter Level"
}
```

**Response (201 Created):**
```json
{
  "id": 98765,
  "constituent_id": 12345,
  "gift_date": "2025-01-15",
  "amount": 150.00,
  "fund_id": 852,
  "campaign_id": 1234,
  "created_at": "2025-01-15T12:00:00Z"
}
```

**Error Responses:**
- `400 Bad Request` - Invalid data
- `404 Not Found` - Constituent not found
- `422 Unprocessable Entity` - Validation errors

**Required Fields:**
- `gift_date` - Date of the gift (YYYY-MM-DD)
- `amount` - Gift amount (decimal)
- `fund_id` - LGL fund ID

**Optional Fields:**
- `gift_type` - Type of gift (default: "Cash")
- `campaign_id` - LGL campaign ID
- `gift_category_id` - LGL gift category ID
- `payment_type_id` - LGL payment type ID
- `gift_note` - Notes about the gift

### Update Gift

**Endpoint:** `PATCH /gifts/{gift_id}.json`

**Purpose:** Update existing gift record.

**Used By:** Data remediation scripts, manual updates

**Request:**
```http
PATCH /api/v1/gifts/98765.json
Authorization: Bearer YOUR_API_KEY
Content-Type: application/json

{
  "fund_id": 852,
  "campaign_id": 1234,
  "gift_category_id": 6047
}
```

**Response (200 OK):**
```json
{
  "id": 98765,
  "fund_id": 852,
  "campaign_id": 1234,
  "gift_category_id": 6047,
  "updated_at": "2025-01-15T13:00:00Z"
}
```

## Memberships API

### Create Membership

**Endpoint:** `POST /constituents/{constituent_id}/memberships.json`

**Purpose:** Create a membership record for a constituent.

**Used By:** `WpUsers::addMembership()`

**Request:**
```http
POST /api/v1/constituents/12345/memberships.json
Authorization: Bearer YOUR_API_KEY
Content-Type: application/json

{
  "membership_level_id": 123,
  "start_date": "2025-01-15",
  "expires_on": "2026-01-15",
  "membership_note": "Supporter membership via WordPress"
}
```

**Response (201 Created):**
```json
{
  "id": 5678,
  "constituent_id": 12345,
  "membership_level_id": 123,
  "start_date": "2025-01-15",
  "expires_on": "2026-01-15",
  "created_at": "2025-01-15T12:00:00Z"
}
```

**Required Fields:**
- `membership_level_id` - LGL membership level ID (from settings)
- `start_date` - Membership start date (YYYY-MM-DD)
- `expires_on` - Membership expiration date (YYYY-MM-DD)

**Optional Fields:**
- `membership_note` - Notes about the membership
- `is_primary` - Boolean (default: true)

### Get Membership Levels

**Endpoint:** `GET /membership_levels.json`

**Purpose:** Retrieve available membership levels.

**Used By:** Settings import functionality

**Request:**
```http
GET /api/v1/membership_levels.json
Authorization: Bearer YOUR_API_KEY
```

**Response (200 OK):**
```json
{
  "items": [
    {
      "id": 123,
      "name": "Supporter",
      "price": 150.00,
      "is_active": true
    },
    {
      "id": 124,
      "name": "Patron",
      "price": 500.00,
      "is_active": true
    }
  ],
  "total_items": 2
}
```

## Constituent Relationships API

### Create Relationship

**Endpoint:** `POST /constituents/{constituent_id}/constituent_relationships.json`

**Purpose:** Create a relationship between two constituents (e.g., parent/child).

**Used By:** `FamilyMemberAction::createLGLRelationship()`

**Request:**
```http
POST /api/v1/constituents/12345/constituent_relationships.json
Authorization: Bearer YOUR_API_KEY
Content-Type: application/json

{
  "related_constituent_id": 67890,
  "relationship_type": "Parent",
  "is_reciprocal": true
}
```

**Response (201 Created):**
```json
{
  "id": 111,
  "constituent_id": 12345,
  "related_constituent_id": 67890,
  "relationship_type": "Parent",
  "reciprocal_relationship_id": 112,
  "created_at": "2025-01-15T12:00:00Z"
}
```

**Relationship Types:**
- `Parent` - Parent relationship
- `Child` - Child relationship
- `Spouse` - Spouse relationship
- `Sibling` - Sibling relationship

### Delete Relationship

**Endpoint:** `DELETE /constituent_relationships/{relationship_id}.json`

**Purpose:** Delete a constituent relationship.

**Used By:** `FamilyMemberDeactivationAction::deleteLGLRelationship()`

**Request:**
```http
DELETE /api/v1/constituent_relationships/111.json
Authorization: Bearer YOUR_API_KEY
```

**Response (204 No Content)**

**Notes:**
- Deleting a relationship with `is_reciprocal: true` also deletes the reciprocal relationship
- Returns 404 if relationship not found

## Funds, Campaigns, and Categories API

### Get Funds

**Endpoint:** `GET /funds.json`

**Purpose:** Retrieve available funds for gift allocation.

**Used By:** Settings import, gift processing

**Request:**
```http
GET /api/v1/funds.json?limit=100
Authorization: Bearer YOUR_API_KEY
```

**Response (200 OK):**
```json
{
  "items": [
    {
      "id": 852,
      "name": "Language Classes",
      "is_active": true,
      "description": "Revenue from language class registrations"
    },
    {
      "id": 2437,
      "name": "Membership Fees",
      "is_active": true
    }
  ],
  "total_items": 2
}
```

### Get Campaigns

**Endpoint:** `GET /campaigns.json`

**Purpose:** Retrieve available campaigns.

**Used By:** Settings import, gift processing

**Request:**
```http
GET /api/v1/campaigns.json?limit=100
Authorization: Bearer YOUR_API_KEY
```

**Response (200 OK):**
```json
{
  "items": [
    {
      "id": 1234,
      "name": "Spring 2025 Semester",
      "start_date": "2025-01-01",
      "end_date": "2025-05-31",
      "is_active": true
    }
  ],
  "total_items": 1
}
```

### Get Gift Categories

**Endpoint:** `GET /gift_categories.json`

**Purpose:** Retrieve available gift categories.

**Used By:** Settings import, gift categorization

**Request:**
```http
GET /api/v1/gift_categories.json?limit=100
Authorization: Bearer YOUR_API_KEY
```

**Response (200 OK):**
```json
{
  "items": [
    {
      "id": 6047,
      "name": "Program Revenue",
      "is_active": true
    }
  ],
  "total_items": 1
}
```

### Get Payment Types

**Endpoint:** `GET /payment_types.json`

**Purpose:** Retrieve available payment types.

**Used By:** Settings import, payment mapping

**Request:**
```http
GET /api/v1/payment_types.json?limit=100
Authorization: Bearer YOUR_API_KEY
```

**Response (200 OK):**
```json
{
  "items": [
    {
      "id": 101,
      "name": "Credit Card",
      "is_active": true
    },
    {
      "id": 102,
      "name": "Check",
      "is_active": true
    }
  ],
  "total_items": 2
}
```

## Error Handling

### HTTP Status Codes

| Code | Meaning | Action |
|------|---------|--------|
| 200 | OK | Request successful |
| 201 | Created | Resource created successfully |
| 204 | No Content | Delete successful |
| 400 | Bad Request | Fix request data |
| 401 | Unauthorized | Check API key |
| 403 | Forbidden | Check permissions |
| 404 | Not Found | Resource doesn't exist |
| 422 | Unprocessable Entity | Validation errors |
| 429 | Too Many Requests | Rate limit exceeded, wait and retry |
| 500 | Internal Server Error | LGL server error, retry later |
| 503 | Service Unavailable | LGL maintenance, retry later |

### Error Response Format

```json
{
  "error": {
    "message": "Validation failed",
    "details": {
      "email_addresses": ["Email address is invalid"],
      "amount": ["Amount must be greater than 0"]
    }
  }
}
```

### Plugin Error Handling

The plugin implements comprehensive error handling:

```php
// Connection.php
try {
    $response = $this->makeRequest($endpoint, 'POST', $data);
    
    if (isset($response['error'])) {
        Helper::getInstance()->debug('LGL API Error: ' . json_encode($response['error']));
        return ['success' => false, 'error' => $response['error']];
    }
    
    return ['success' => true, 'data' => $response];
    
} catch (\Exception $e) {
    Helper::getInstance()->debug('LGL API Exception: ' . $e->getMessage());
    return ['success' => false, 'error' => $e->getMessage()];
}
```

**Retry Logic:**
- 429 (Rate Limit): Wait calculated delay, then retry
- 500/503 (Server Error): Retry up to 3 times with exponential backoff
- 401/403 (Auth Error): Log error, don't retry
- 400/422 (Validation): Fix data, don't retry

## Caching Strategy

### Cached Endpoints
The plugin caches GET requests for performance:

- Constituent lookups: 1 hour
- Membership levels: 24 hours
- Funds/campaigns/categories: 24 hours
- Gift/payment lookups: 30 minutes

### Cache Keys
```php
// Example cache key generation
$cache_key = 'api_request_' . md5($endpoint . serialize($data));
$cached = CacheManager::get($cache_key);

if ($cached !== false) {
    return $cached;
}

// Make request and cache
$response = $this->makeRequest($endpoint, 'GET', $data);
CacheManager::set($cache_key, $response, 3600);
```

### Cache Invalidation
Caches are invalidated on:
- Settings changes
- Manual cache clear
- TTL expiration
- User/order updates

## Best Practices

### 1. Rate Limiting
```php
// Check before making request
if (!$rateLimiter->canMakeRequest()) {
    $rateLimiter->waitForAvailability();
}

// Make request
$response = $connection->makeRequest($endpoint, 'POST', $data);
```

### 2. Batch Operations
For bulk operations, batch requests with delays:
```php
foreach ($users as $user) {
    // Process user
    $result = $this->syncToLGL($user);
    
    // Wait between requests
    usleep(1100000); // 1.1 seconds
}
```

### 3. Error Recovery
```php
$max_retries = 3;
$attempt = 0;

while ($attempt < $max_retries) {
    $result = $connection->makeRequest($endpoint, 'POST', $data);
    
    if ($result['success']) {
        break;
    }
    
    if ($result['http_code'] === 429) {
        // Rate limited, wait and retry
        sleep(60);
        $attempt++;
    } else {
        // Other error, don't retry
        break;
    }
}
```

### 4. Data Validation
Always validate data before sending to API:
```php
// Validate required fields
$required = ['first_name', 'last_name', 'email'];
foreach ($required as $field) {
    if (empty($data[$field])) {
        return ['success' => false, 'error' => "$field is required"];
    }
}

// Validate email format
if (!is_email($data['email'])) {
    return ['success' => false, 'error' => 'Invalid email'];
}
```

## Plugin Integration Examples

### Create Constituent and Membership
```php
// 1. Create constituent
$constituent_data = [
    'first_name' => $user->first_name,
    'last_name' => $user->last_name,
    'email_addresses' => [['address' => $user->user_email, 'is_primary' => true]]
];

$constituent = Constituents::getInstance()->addConstituent($constituent_data);

if ($constituent['success']) {
    $lgl_id = $constituent['data']['id'];
    update_user_meta($user_id, 'lgl_id', $lgl_id);
    
    // 2. Create membership
    $membership_data = [
        'membership_level_id' => 123,
        'start_date' => date('Y-m-d'),
        'expires_on' => date('Y-m-d', strtotime('+1 year'))
    ];
    
    $membership = WpUsers::getInstance()->addMembership($lgl_id, $membership_data);
}
```

### Create Gift for Order
```php
$gift_data = [
    'gift_date' => date('Y-m-d'),
    'amount' => $order->get_total(),
    'fund_id' => 852,
    'campaign_id' => 1234,
    'gift_note' => "Order #{$order->get_id()} - {$product_name}"
];

$gift = Payments::getInstance()->addGiftToConstituent($lgl_id, $gift_data);

if ($gift['success']) {
    update_post_meta($order_id, '_lgl_payment_id', $gift['data']['id']);
}
```

---

**Last Updated:** November 17, 2025  
**Plugin Version:** 2.0.0+  
**LGL API Version:** v1

