<?php

namespace UpstateInternational\LGL\Memberships;

use UpstateInternational\LGL\LGL\Connection;
use UpstateInternational\LGL\LGL\Helper;
use UpstateInternational\LGL\LGL\Constituents;
use UpstateInternational\LGL\LGL\Payments;

class MembershipRegistrationService {
    private Connection $connection;
    private Helper $helper;
    private Constituents $constituents;
    private Payments $payments;

    public function __construct(
        Connection $connection,
        Helper $helper,
        Constituents $constituents,
        Payments $payments
    ) {
        $this->connection = $connection;
        $this->helper = $helper;
        $this->constituents = $constituents;
        $this->payments = $payments;
    }

    public function register(array $context): array {
        $userId = (int) ($context['user_id'] ?? 0);
        $searchName = $context['search_name'] ?? '';
        $emails = $context['emails'] ?? [];
        $email = $context['email'] ?? '';
        $orderId = (int) ($context['order_id'] ?? 0);
        $price = (float) ($context['price'] ?? 0);
        $membershipLevel = $context['membership_level'] ?? '';
        $membershipLevelId = $context['membership_level_id'] ?? null;
        $paymentType = $context['payment_type'] ?? null;
        $isFamilyMember = (bool) ($context['is_family_member'] ?? false);
        $request = $context['request'] ?? [];
        $order = $context['order'] ?? null;

        $this->helper->debug('🧭 MembershipRegistrationService::register() STARTED', [
            'user_id' => $userId,
            'search_name' => $searchName,
            'email' => $email,
            'order_id' => $orderId,
            'membership_level' => $membershipLevel,
            'membership_level_id' => $membershipLevelId
        ]);

        if ($userId <= 0) {
            throw new \InvalidArgumentException('Membership registration requires a valid user_id.');
        }

        // STEP 0: Check if order already has an LGL ID (for mixed carts - prevents duplicate constituents)
        // This must be checked BEFORE user meta to ensure all products in an order use the same constituent
        $lglId = null;
        $matchMethod = null;
        $matchedEmail = null;
        $created = false;
        
        if ($orderId > 0 && $order instanceof \WC_Order) {
            $order_lgl_id = $order->get_meta('_lgl_lgl_id');
            if (!empty($order_lgl_id)) {
                $this->helper->debug('🔄 MembershipRegistrationService: Order already has LGL ID, reusing for this product', [
                    'order_id' => $orderId,
                    'lgl_id' => $order_lgl_id,
                    'user_id' => $userId,
                    'product_type' => $isFamilyMember ? 'Family Member (slot purchase)' : 'Membership'
                ]);
                
                // Use the existing LGL ID from order - don't create a new constituent
                $lglId = (string) $order_lgl_id;
                $matchMethod = 'order_meta';
                
                // Ensure user meta is also set (in case it wasn't set yet)
                if (get_user_meta($userId, 'lgl_id', true) !== $lglId) {
                    update_user_meta($userId, 'lgl_id', $lglId);
                }
                
                // Skip constituent creation/update - go straight to payment creation
                // (the constituent was already created/updated for the first product in this order)
                // Jump to payment creation section below
            }
        }

        // STEP 1: Check user meta for existing LGL ID (only if order doesn't have one)
        if (!$lglId) {
            $lglId = $this->constituents->getUserLglId($userId);

            if ($lglId) {
                $this->helper->debug('🔍 MembershipRegistrationService: Found LGL ID in user meta', [
                'user_id' => $userId,
                'lgl_id' => $lglId,
                'meta_key' => 'lgl_constituent_id or lgl_id'
            ]);
            
            // Verify the constituent exists in LGL
            $verification = $this->connection->getConstituent((string) $lglId);
            if (!empty($verification['success']) && !empty($verification['data'])) {
                $this->helper->debug('✅ MembershipRegistrationService: LGL ID verified in LGL', [
                    'lgl_id' => $lglId,
                    'constituent_name' => ($verification['data']['first_name'] ?? '') . ' ' . ($verification['data']['last_name'] ?? '')
                ]);
                $matchMethod = 'user_meta';
                // Get email from verified constituent for logging
                $email_addresses = $verification['data']['email_addresses'] ?? [];
                if (!empty($email_addresses) && is_array($email_addresses)) {
                    $first_email = reset($email_addresses);
                    $matchedEmail = is_array($first_email) ? ($first_email['address'] ?? null) : ($first_email->address ?? null);
                }
            } else {
                $this->helper->debug('⚠️ MembershipRegistrationService: LGL ID in user meta not found in LGL, will search', [
                    'lgl_id' => $lglId,
                    'user_id' => $userId
                ]);
                // LGL ID in meta doesn't exist in LGL - clear it and search
                delete_user_meta($userId, 'lgl_constituent_id');
                delete_user_meta($userId, 'lgl_id');
                delete_user_meta($userId, 'lgl_user_id');
                $lglId = null;
            }
            }
        }

        // STEP 2: If no LGL ID found in user meta or order meta, search by email (then name)
        if (!$lglId) {
            $this->helper->debug('🔍 MembershipRegistrationService: No LGL ID in user meta, searching by email/name', [
                'user_id' => $userId,
                'email' => $email,
                'search_name' => $searchName
            ]);
            
            $match = $this->connection->searchByName($searchName, !empty($emails) ? $emails : $email);
            $matchMethod = $match['method'] ?? null;
            $matchedEmail = $match['email'] ?? null;
            $lglId = $match['id'] ?? null;
        }

        // STEP 3: Create or update constituent (only if not already found in order meta)
        if (!$lglId) {
            $this->helper->debug('➕ MembershipRegistrationService: No existing constituent found, creating new one', [
                'user_id' => $userId
            ]);
            $this->ensureUserProfileHasNames($userId, $request, $order);
            $lglId = $this->createConstituent($userId, $request, $isFamilyMember);
            $created = true;
        } elseif ($matchMethod !== 'order_meta') {
            // Only update constituent if we found it via user meta or search (not order meta)
            // If found via order meta, constituent was already created/updated for first product
            $this->helper->debug('🔄 MembershipRegistrationService: Existing constituent found, updating', [
                'user_id' => $userId,
                'lgl_id' => $lglId,
                'match_method' => $matchMethod
            ]);
            $this->ensureUserProfileHasNames($userId, $request, $order);
            $this->updateConstituent($userId, (string) $lglId, $request, $isFamilyMember);
        } else {
            // Found via order meta - constituent already exists, just verify it
            $this->helper->debug('✅ MembershipRegistrationService: Using existing constituent from order meta, skipping update', [
                'user_id' => $userId,
                'lgl_id' => $lglId,
                'match_method' => $matchMethod
            ]);
        }

        // Store membership level ID if provided (but skip for family member products)
        if ($membershipLevelId && !$isFamilyMember) {
            \update_user_meta($userId, 'lgl_membership_level_id', (int) $membershipLevelId);
        }

        $constituentVerification = $this->connection->getConstituent((string) $lglId);

        // Save LGL ID to user meta (canonical field: lgl_id) - ensure it's set even if we found via order meta
        \update_user_meta($userId, 'lgl_id', $lglId);
        
        // Only update membership type if NOT a family member product (family member = slot purchase only)
        if (!$isFamilyMember) {
            \update_user_meta($userId, 'user-membership-type', $membershipLevel);
        } else {
            $this->helper->debug('ℹ️ MembershipRegistrationService: Skipping membership type update for family member product', [
                'user_id' => $userId,
                'product_type' => 'Family Member (slot purchase)'
            ]);
        }

        $paymentId = null;
        $paymentResponse = null;
        // Create payment for all orders (including family member products)
        if ($orderId > 0 && $price > 0) {
            $product = $context['product'] ?? null;
            $productItemId = $context['product_item_id'] ?? null;
            $paymentResult = $this->createPayment((string) $lglId, $orderId, $price, $paymentType ?? 'online', $product, $productItemId);
            if ($paymentResult && isset($paymentResult['id'])) {
                $paymentId = $paymentResult['id'];
                // Use the creation response as verification data (no need for separate verification call)
                $paymentResponse = $paymentResult;
            }
        }

        $status = 'synced';
        if (empty($constituentVerification['success'])) {
            $status = 'unsynced';
        } elseif ($orderId > 0 && $price > 0 && $paymentId === null) {
            // Payment was expected but creation failed - mark as partial
            $status = 'partial';
        }

        return [
            'lgl_id' => (string) $lglId,
            'created' => $created,
            'match_method' => $matchMethod,
            'matched_email' => $matchedEmail,
            'payment_id' => $paymentId,
            'constituent_response' => $constituentVerification,
            'payment_response' => $paymentResponse,
            'status' => $status
        ];
    }

    /**
     * Ensure user profile contains first and last name prior to LGL payload creation.
     *
     * @param int   $userId
     * @param array $request
     * @return void
     */
    private function ensureUserProfileHasNames(int $userId, array $request, $order = null): void {
        $firstName = $request['user_firstname'] ?? '';
        $lastName  = $request['user_lastname'] ?? '';

        if (!$firstName || !$lastName) {
            $user = get_userdata($userId);
            if ($user) {
                $firstName = $firstName ?: $user->first_name;
                $lastName  = $lastName ?: $user->last_name;
                if (!$firstName) {
                    $firstName = get_user_meta($userId, 'first_name', true);
                }
                if (!$lastName) {
                    $lastName = get_user_meta($userId, 'last_name', true);
                }
            }
        if ((!$firstName || !$lastName) && $order && method_exists($order, 'get_billing_first_name')) {
            $firstName = $firstName ?: $order->get_billing_first_name();
            $lastName = $lastName ?: $order->get_billing_last_name();
            }
        }

        if ($firstName) {
            update_user_meta($userId, 'first_name', $firstName);
            update_user_meta($userId, 'user_firstname', $firstName);
        }
        if ($lastName) {
            update_user_meta($userId, 'last_name', $lastName);
            update_user_meta($userId, 'user_lastname', $lastName);
        }

        if ($firstName || $lastName) {
            wp_update_user([
                'ID' => $userId,
                'first_name' => $firstName ?: null,
                'last_name' => $lastName ?: null,
                'display_name' => trim($firstName . ' ' . $lastName) ?: null
            ]);
        } else {
            throw new \RuntimeException('Missing first and last name for membership registration.');
        }
    }

    private function createConstituent(int $userId, array $request = [], bool $skipMembership = false): string {
        $this->constituents->setData($userId);
        if (!empty($request['user_firstname']) || !empty($request['user_lastname'])) {
            $this->constituents->setName(
                $request['user_firstname'] ?? '',
                $request['user_lastname'] ?? ''
            );
        }
        if (!empty($request['user_email'])) {
            $this->constituents->setEmail($request['user_email']);
        }
        if (!empty($request['user_phone'])) {
            $this->constituents->setPhone($request['user_phone']);
        }
        
        // STEP 1: Create constituent with ONLY personal data (matching legacy pattern)
        $this->helper->debug('🚀 MembershipRegistrationService: About to create constituent (Step 1/4)', [
            'user_id' => $userId,
            'skip_membership' => $skipMembership
        ]);
        
        // Constituents::createConstituent() both builds AND sends the payload
        $response = $this->constituents->createConstituent();
        
        $this->helper->debug('📥 MembershipRegistrationService: Received response from LGL', [
            'success' => $response['success'] ?? null,
            'http_code' => $response['http_code'] ?? null,
            'has_id' => !empty($response['data']['id']),
            'response_structure' => array_keys($response)
        ]);

        $httpCode = $response['http_code'] ?? 0;
        if (
            !is_array($response)
            || empty($response['success'])
            || $httpCode < 200
            || $httpCode >= 300
            || empty($response['data']['id'])
        ) {
            $error = $response['error'] ?? 'Unknown error creating constituent';
            $this->helper->debug('❌ MembershipRegistrationService: Constituent creation FAILED', [
                'error' => $error,
                'response' => $response
            ]);
            throw new \RuntimeException($error);
        }

        $lglId = (string) $response['data']['id'];
        $this->helper->debug('✅ MembershipRegistrationService: Constituent created (Step 1/5)', [
            'lgl_id' => $lglId,
            'http_code' => $response['http_code'] ?? null
        ]);

        // STEP 2-5: Add email, phone, address, and membership separately (matching legacy lgl_add_object pattern)
        // Skip membership for family member products (slot purchases only)
        $this->addConstituentDetails($lglId, $userId, $skipMembership);

        return $lglId;
    }
    
    /**
     * Add constituent details via separate POST requests (matching legacy lgl_add_object)
     * 
     * @param string $lglId LGL constituent ID
     * @param int $userId WordPress user ID
     * @param bool $skipMembership Skip membership creation/update (for family member slot purchases)
     * @param bool $skipContactInfo Skip email/phone/address (for updates - syncContactInfo() already handles these)
     */
    private function addConstituentDetails(string $lglId, int $userId, bool $skipMembership = false, bool $skipContactInfo = false): void {
        // STEP 2: Add/update email address (use updateOrAdd to prevent duplicates)
        // Skip if syncContactInfo() already handled it (for updates)
        if (!$skipContactInfo) {
            $emailData = $this->constituents->getEmailData();
            if (!empty($emailData)) {
                foreach ($emailData as $email) {
                    $response = $this->connection->updateOrAddEmailAddress($lglId, $email);
                    if (isset($response['skipped']) && $response['skipped']) {
                        $this->helper->debug('✅ MembershipRegistrationService: Email unchanged (Step 2/4)', [
                            'lgl_id' => $lglId,
                            'email' => $email['address'] ?? 'unknown'
                        ]);
                    } elseif (isset($response['updated']) && $response['updated']) {
                        $this->helper->debug('✅ MembershipRegistrationService: Email updated (Step 2/4)', [
                            'lgl_id' => $lglId,
                            'success' => $response['success'] ?? false,
                            'response' => $response
                        ]);
                    } else {
                        $this->helper->debug('✅ MembershipRegistrationService: Email added (Step 2/4)', [
                            'lgl_id' => $lglId,
                            'success' => $response['success'] ?? false,
                            'response' => $response
                        ]);
                    }
                }
            } else {
                $this->helper->debug('⚠️ MembershipRegistrationService: No email data to add (Step 2/4)');
            }
            
            // STEP 3: Add/update phone number (use updateOrAdd to prevent duplicates)
            $phoneData = $this->constituents->getPhoneData();
            if (!empty($phoneData)) {
                foreach ($phoneData as $phone) {
                    $response = $this->connection->updateOrAddPhoneNumber($lglId, $phone);
                    if (isset($response['skipped']) && $response['skipped']) {
                        $this->helper->debug('✅ MembershipRegistrationService: Phone unchanged (Step 3/4)', [
                            'lgl_id' => $lglId,
                            'phone' => $phone['number'] ?? 'unknown'
                        ]);
                    } elseif (isset($response['updated']) && $response['updated']) {
                        $this->helper->debug('✅ MembershipRegistrationService: Phone updated (Step 3/4)', [
                            'lgl_id' => $lglId,
                            'success' => $response['success'] ?? false,
                            'response' => $response
                        ]);
                    } else {
                        $this->helper->debug('✅ MembershipRegistrationService: Phone added (Step 3/4)', [
                            'lgl_id' => $lglId,
                            'success' => $response['success'] ?? false,
                            'response' => $response
                        ]);
                    }
                }
            } else {
                $this->helper->debug('⚠️ MembershipRegistrationService: No phone data to add (Step 3/4)');
            }
        } else {
            $this->helper->debug('ℹ️ MembershipRegistrationService: Skipping email/phone (already handled by syncContactInfo())', [
                'lgl_id' => $lglId
            ]);
        }
        
        // STEP 4: Add street address (with duplicate checking)
        // Skip if syncContactInfo() already handled it (for updates)
        if (!$skipContactInfo) {
            $addressData = $this->constituents->getAddressData();
            $this->helper->debug('🔍 MembershipRegistrationService: Checking address data', [
                'lgl_id' => $lglId,
                'address_data_count' => count($addressData),
                'address_data' => $addressData
            ]);
            if (!empty($addressData)) {
                foreach ($addressData as $address) {
                    $response = $this->connection->addStreetAddressSafe($lglId, $address);
                    if (isset($response['skipped']) && $response['skipped']) {
                        $this->helper->debug('⚠️ MembershipRegistrationService: Address skipped (already exists)', [
                            'lgl_id' => $lglId,
                            'street' => $address['street'] ?? 'unknown'
                        ]);
                    } else {
                        $this->helper->debug('✅ MembershipRegistrationService: Address added (Step 4/4)', [
                            'lgl_id' => $lglId,
                            'success' => $response['success'] ?? false,
                            'response' => $response
                        ]);
                    }
                }
            } else {
                $this->helper->debug('⚠️ MembershipRegistrationService: No address data to add (Step 4/4)', [
                    'lgl_id' => $lglId,
                    'address_data' => $addressData
                ]);
            }
        } else {
            $this->helper->debug('ℹ️ MembershipRegistrationService: Skipping address (already handled by syncContactInfo())', [
                'lgl_id' => $lglId
            ]);
        }
        
        // STEP 5A: CRITICAL - Deactivate old active memberships BEFORE adding new one (skip for family member products)
        if (!$skipMembership) {
            $this->deactivateOldMemberships($lglId);
            
            // STEP 5B: Add new membership
            $membershipData = $this->constituents->getMembershipData();
            if (!empty($membershipData)) {
                $response = $this->connection->addMembership($lglId, $membershipData);
                $this->helper->debug('✅ MembershipRegistrationService: New membership added (Step 5/5)', [
                    'lgl_id' => $lglId,
                    'success' => $response['success'] ?? false,
                    'response' => $response
                ]);
            } else {
                $this->helper->debug('⚠️ MembershipRegistrationService: No membership data to add (Step 5/5)');
            }
        } else {
            $this->helper->debug('ℹ️ MembershipRegistrationService: Skipping membership creation/update (family member slot purchase)', [
                'lgl_id' => $lglId,
                'user_id' => $userId
            ]);
        }
    }
    
    /**
     * Deactivate old active memberships before adding a new one
     * 
     * This prevents duplicate active memberships and maintains clean data.
     * Called during both new registrations and renewals.
     * 
     * @param string $lglId LGL constituent ID
     * @return void
     */
    private function deactivateOldMemberships(string $lglId): void {
        try {
            // Get ALL memberships for this constituent
            $membershipsResponse = $this->connection->getMemberships($lglId);
            
            if (empty($membershipsResponse['success'])) {
                $this->helper->debug('⚠️ Could not fetch memberships to deactivate', ['lgl_id' => $lglId]);
                return;
            }
            
            // Extract memberships from response
            $memberships = [];
            if (isset($membershipsResponse['data']['items'])) {
                $memberships = $membershipsResponse['data']['items'];
            } elseif (isset($membershipsResponse['data']) && is_array($membershipsResponse['data'])) {
                $memberships = $membershipsResponse['data'];
            }
            
            if (empty($memberships)) {
                $this->helper->debug('ℹ️ No existing memberships to deactivate', ['lgl_id' => $lglId]);
                return;
            }
            
            // Find active memberships (no finish_date or finish_date is in the future)
            $today = date('Y-m-d');
            $todayTimestamp = strtotime($today);
            $activeMemberships = [];
            
            foreach ($memberships as $membership) {
                $finishDate = $membership['finish_date'] ?? null;
                if (!$finishDate || strtotime($finishDate) >= $todayTimestamp) {
                    $activeMemberships[] = $membership;
                }
            }
            
            if (empty($activeMemberships)) {
                $this->helper->debug('ℹ️ No active memberships to deactivate', ['lgl_id' => $lglId, 'total_memberships' => count($memberships)]);
                return;
            }
            
            $this->helper->debug('🔄 Deactivating old active memberships', [
                'lgl_id' => $lglId,
                'active_count' => count($activeMemberships),
                'total_count' => count($memberships)
            ]);
            
            // Deactivate each active membership
            foreach ($activeMemberships as $membership) {
                $membershipId = $membership['id'];
                $updatePayload = [
                    'id' => $membershipId,
                    'membership_level_id' => $membership['membership_level_id'],
                    'membership_level_name' => $membership['membership_level_name'],
                    'date_start' => $membership['date_start'],
                    'finish_date' => $today,  // CRITICAL: Must be >= date_start per LGL API validation
                    'note' => 'Membership ended via WooCommerce renewal on ' . date('Y-m-d')
                ];
                
                $result = $this->connection->updateMembership((string)$membershipId, $updatePayload);
                
                if (!empty($result['success'])) {
                    $this->helper->debug('✅ Deactivated membership', ['membership_id' => $membershipId]);
                } else {
                    $this->helper->debug('❌ Failed to deactivate membership', [
                        'membership_id' => $membershipId,
                        'error' => $result['error'] ?? 'Unknown error'
                    ]);
                }
            }
            
        } catch (\Exception $e) {
            $this->helper->debug('❌ Error deactivating old memberships', [
                'lgl_id' => $lglId,
                'error' => $e->getMessage()
            ]);
            // Don't throw - continue with adding new membership even if deactivation fails
        }
    }

    private function updateConstituent(int $userId, string $lglId, array $request = [], bool $skipMembership = false): void {
        $this->constituents->setData($userId);
        if (!empty($request['user_firstname']) || !empty($request['user_lastname'])) {
            $this->constituents->setName(
                $request['user_firstname'] ?? '',
                $request['user_lastname'] ?? ''
            );
        }
        if (!empty($request['user_email'])) {
            $this->constituents->setEmail($request['user_email']);
        }
        if (!empty($request['user_phone'])) {
            $this->constituents->setPhone($request['user_phone']);
        }
        
        // STEP 1: Update constituent personal data
        // Note: Constituents::updateConstituent() already calls syncContactInfo() which handles email/phone/address
        $payload = $this->constituents->updateConstituent($lglId);
        $response = $this->connection->updateConstituent($lglId, $payload);
        $this->helper->debug('✅ MembershipRegistrationService: Constituent updated (Step 1/5)', [
            'lgl_id' => $lglId,
            'http_code' => $response['http_code'] ?? null,
            'skip_membership' => $skipMembership
        ]);
        
        // STEP 2-5: Handle membership separately
        // Skip email/phone/address since syncContactInfo() already handled them (prevents duplicates)
        // Skip membership for family member products (slot purchases only)
        $this->addConstituentDetails($lglId, $userId, $skipMembership, true); // true = skip contact info
    }

    /**
     * Create payment in LGL
     * 
     * @param string $lglId LGL constituent ID
     * @param int $orderId WooCommerce order ID
     * @param float $amount Payment amount
     * @param string $paymentType Payment type
     * @return array|null Payment result with 'id' and 'success' keys, or null on failure
     */
    private function createPayment(string $lglId, int $orderId, float $amount, string $paymentType, $product = null, $productItemId = null): ?array {
        // Make external_id unique per product to prevent duplicate payment errors
        $externalId = $orderId;
        if ($productItemId) {
            $externalId = $orderId . '-' . $productItemId;
        } elseif ($product) {
            // Try to get product ID from product object
            $prodId = null;
            if (is_object($product) && method_exists($product, 'get_product_id')) {
                $prodId = $product->get_product_id();
            } elseif (is_object($product) && method_exists($product, 'get_id')) {
                $prodId = $product->get_id();
            }
            if ($prodId) {
                $externalId = $orderId . '-' . $prodId;
            }
        }
        
        $result = $this->payments->setupMembershipPayment($lglId, $externalId, $amount, date('Y-m-d'), $paymentType, $product);

        if (empty($result['success'])) {
            $this->helper->debug('⚠️ MembershipRegistrationService: Payment creation failed', $result);
            return null;
        }

        $paymentId = isset($result['id']) ? (int) $result['id'] : null;
        $this->helper->debug('✅ MembershipRegistrationService: Payment created', [
            'payment_id' => $paymentId,
            'lgl_id' => $lglId,
            'order_id' => $orderId,
            'external_id' => $externalId,
            'amount' => $amount
        ]);

        // Return full result array (includes 'id', 'success', 'data', etc.)
        return $result;
    }
}
