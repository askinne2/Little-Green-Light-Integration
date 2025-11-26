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
        
        $this->helper->debug('📋 MembershipRegistrationService::register() - Received context', [
            'user_id' => $userId,
            'membership_level' => $membershipLevel,
            'membership_level_id' => $membershipLevelId,
            'membership_level_type' => gettype($membershipLevel),
            'order_id' => $orderId,
            'context_keys' => array_keys($context),
            'user_roles_before' => get_userdata($userId)->roles ?? [],
            'note' => 'This is the membership level that will be stored in user-membership-type meta'
        ]);
        $paymentType = $context['payment_type'] ?? null;
        $isFamilyMember = (bool) ($context['is_family_member'] ?? false);
        $request = $context['request'] ?? [];
        $order = $context['order'] ?? null;

        $this->helper->info('LGL MembershipRegistrationService: Processing membership registration', [
            'user_id' => $userId,
            'order_id' => $orderId,
            'membership_level' => $membershipLevel
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
                $this->helper->debug('LGL MembershipRegistrationService: Reusing LGL ID from order', [
                    'order_id' => $orderId,
                    'lgl_id' => $order_lgl_id
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
                // Verify the constituent exists in LGL
                $verification = $this->connection->getConstituent((string) $lglId);
                if (!empty($verification['success']) && !empty($verification['data'])) {
                    $matchMethod = 'user_meta';
                    // Get email from verified constituent for logging
                    $email_addresses = $verification['data']['email_addresses'] ?? [];
                    if (!empty($email_addresses) && is_array($email_addresses)) {
                        $first_email = reset($email_addresses);
                        $matchedEmail = is_array($first_email) ? ($first_email['address'] ?? null) : ($first_email->address ?? null);
                    }
                } else {
                    $this->helper->warning('LGL MembershipRegistrationService: LGL ID in user meta not found in LGL, will search', [
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
            $this->helper->debug('LGL MembershipRegistrationService: Searching by email/name', [
                'user_id' => $userId
            ]);
            
            $match = $this->connection->searchByName($searchName, !empty($emails) ? $emails : $email);
            $matchMethod = $match['method'] ?? null;
            $matchedEmail = $match['email'] ?? null;
            $lglId = $match['id'] ?? null;
        }

        // STEP 3: Create or update constituent (only if not already found in order meta)
        if (!$lglId) {
            $this->ensureUserProfileHasNames($userId, $request, $order);
            $lglId = $this->createConstituent($userId, $request, $isFamilyMember);
            $created = true;
            $this->helper->info('LGL MembershipRegistrationService: Created new constituent', [
                'lgl_id' => $lglId,
                'user_id' => $userId
            ]);
        } elseif ($matchMethod !== 'order_meta') {
            // Only update constituent if we found it via user meta or search (not order meta)
            // If found via order meta, constituent was already created/updated for first product
            $this->ensureUserProfileHasNames($userId, $request, $order);
            $this->updateConstituent($userId, (string) $lglId, $request, $isFamilyMember);
            $this->helper->info('LGL MembershipRegistrationService: Updated existing constituent', [
                'lgl_id' => $lglId,
                'user_id' => $userId
            ]);
        }

        // Store membership level ID if provided (but skip for family member products)
        if ($membershipLevelId && !$isFamilyMember) {
            \update_user_meta($userId, 'lgl_membership_level_id', (int) $membershipLevelId);
        }

        $constituentVerification = $this->connection->getConstituent((string) $lglId);

        // Save LGL ID to user meta (canonical field: lgl_id) - ensure it's set even if we found via order meta
        \update_user_meta($userId, 'lgl_id', $lglId);
        
        // Process pending group syncs (from coupon-based role assignments)
        $this->processPendingGroupSyncs($userId, $lglId);
        
        // Only update membership type if NOT a family member product (family member = slot purchase only)
        if (!$isFamilyMember) {
            $existing_membership_type = \get_user_meta($userId, 'user-membership-type', true);
            $this->helper->debug('💾 MembershipRegistrationService: Storing user-membership-type meta', [
                'user_id' => $userId,
                'membership_level' => $membershipLevel,
                'membership_level_type' => gettype($membershipLevel),
                'membership_level_id' => $membershipLevelId,
                'existing_membership_type' => $existing_membership_type,
                'is_family_member' => $isFamilyMember,
                'order_id' => $orderId,
                'user_roles' => get_userdata($userId)->roles ?? [],
                'note' => 'This is where user-membership-type meta is set - should be membership level name, NOT role name'
            ]);
            
            // Force update by deleting first, then adding (ensures cache is cleared)
            \delete_user_meta($userId, 'user-membership-type');
            \update_user_meta($userId, 'user-membership-type', $membershipLevel);
            
            // Clear WordPress object cache for this user
            \clean_user_cache(\get_user_by('id', $userId));
            
            // Verify what was stored (bypass cache)
            \wp_cache_delete($userId, 'user_meta');
            $stored_value = \get_user_meta($userId, 'user-membership-type', true);
            
            $this->helper->debug('💾 MembershipRegistrationService: Verified stored user-membership-type', [
                'user_id' => $userId,
                'value_stored' => $stored_value,
                'value_expected' => $membershipLevel,
                'match' => $stored_value === $membershipLevel,
                'cache_cleared' => true
            ]);
            
            // Double-check by reading directly from database if still wrong
            if ($stored_value !== $membershipLevel) {
                global $wpdb;
                $direct_db_value = $wpdb->get_var($wpdb->prepare(
                    "SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = 'user-membership-type' LIMIT 1",
                    $userId
                ));
                $this->helper->warning('💾 MembershipRegistrationService: Value mismatch detected!', [
                    'user_id' => $userId,
                    'expected' => $membershipLevel,
                    'get_user_meta_result' => $stored_value,
                    'direct_db_value' => $direct_db_value,
                    'note' => 'Cache may be interfering - direct DB read shows actual stored value'
                ]);
            }
        } else {
            $this->helper->debug('💾 MembershipRegistrationService: Skipping user-membership-type update (family member product)', [
                'user_id' => $userId,
                'membership_level' => $membershipLevel,
                'is_family_member' => $isFamilyMember
            ]);
        }

        $paymentId = null;
        $paymentResponse = null;
        // Create payment for all orders (including family member products)
        // Also create $0 payments when coupons are used (to record coupon info in gift note)
        $hasCoupons = false;
        if ($orderId > 0 && $order instanceof \WC_Order) {
            $couponCodes = $order->get_coupon_codes();
            $hasCoupons = !empty($couponCodes);
        }
        
        if ($orderId > 0 && ($price > 0 || $hasCoupons)) {
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
            $this->helper->warning('LGL MembershipRegistrationService: Payment creation failed', [
                'order_id' => $orderId,
                'lgl_id' => $lglId
            ]);
        }

        $this->helper->info('LGL MembershipRegistrationService: Registration completed', [
            'user_id' => $userId,
            'lgl_id' => $lglId,
            'status' => $status,
            'created' => $created
        ]);

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
        
        // Only override with request data if WordPress user object fields are empty
        // Priority: WordPress user object (what setData() already read) > request data
        $user = get_userdata($userId);
        if ($user && (empty($user->first_name) || empty($user->last_name))) {
            // WordPress user object is missing names, use request data as fallback
            if (!empty($request['user_firstname']) || !empty($request['user_lastname'])) {
                $this->constituents->setName(
                    $request['user_firstname'] ?? '',
                    $request['user_lastname'] ?? ''
                );
            }
        }
        // Email: only override if WordPress user email is empty
        if ($user && empty($user->user_email) && !empty($request['user_email'])) {
            $this->constituents->setEmail($request['user_email']);
        }
        if (!empty($request['user_phone'])) {
            $this->constituents->setPhone($request['user_phone']);
        }
        
        // STEP 1: Create constituent with ONLY personal data (matching legacy pattern)
        // Constituents::createConstituent() both builds AND sends the payload
        $response = $this->constituents->createConstituent();

        $httpCode = $response['http_code'] ?? 0;
        if (
            !is_array($response)
            || empty($response['success'])
            || $httpCode < 200
            || $httpCode >= 300
            || empty($response['data']['id'])
        ) {
            $error = $response['error'] ?? 'Unknown error creating constituent';
            $this->helper->error('LGL MembershipRegistrationService: Constituent creation failed', [
                'user_id' => $userId,
                'error' => $error
            ]);
            throw new \RuntimeException($error);
        }

        $lglId = (string) $response['data']['id'];

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
                    $this->connection->updateOrAddEmailAddress($lglId, $email);
                }
            }
            
            // STEP 3: Add/update phone number (use updateOrAdd to prevent duplicates)
            $phoneData = $this->constituents->getPhoneData();
            if (!empty($phoneData)) {
                foreach ($phoneData as $phone) {
                    $this->connection->updateOrAddPhoneNumber($lglId, $phone);
                }
            }
        }
        
        // STEP 4: Add street address (with duplicate checking)
        // Skip if syncContactInfo() already handled it (for updates)
        if (!$skipContactInfo) {
            $addressData = $this->constituents->getAddressData();
            if (!empty($addressData)) {
                foreach ($addressData as $address) {
                    $this->connection->addStreetAddressSafe($lglId, $address);
                }
            }
        }
        
        // STEP 5A: CRITICAL - Deactivate old active memberships BEFORE adding new one (skip for family member products)
        if (!$skipMembership) {
            $this->deactivateOldMemberships($lglId);
            
            // STEP 5B: Add new membership
            $membershipData = $this->constituents->getMembershipData();
            if (!empty($membershipData)) {
                $response = $this->connection->addMembership($lglId, $membershipData);
                if (!empty($response['success'])) {
                    $this->helper->info('LGL MembershipRegistrationService: Membership added', [
                        'lgl_id' => $lglId
                    ]);
                } else {
                    $this->helper->error('LGL MembershipRegistrationService: Failed to add membership', [
                        'lgl_id' => $lglId,
                        'error' => $response['error'] ?? 'Unknown error'
                    ]);
                }
            }
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
                return;
            }
            
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
                
                if (empty($result['success'])) {
                    $this->helper->error('LGL MembershipRegistrationService: Failed to deactivate membership', [
                        'membership_id' => $membershipId,
                        'lgl_id' => $lglId,
                        'error' => $result['error'] ?? 'Unknown error'
                    ]);
                }
            }
            
            if (!empty($activeMemberships)) {
                $this->helper->info('LGL MembershipRegistrationService: Deactivated old memberships', [
                    'lgl_id' => $lglId,
                    'count' => count($activeMemberships)
                ]);
            }
            
        } catch (\Exception $e) {
            $this->helper->error('LGL MembershipRegistrationService: Error deactivating old memberships', [
                'lgl_id' => $lglId,
                'error' => $e->getMessage()
            ]);
            // Don't throw - continue with adding new membership even if deactivation fails
        }
    }

    private function updateConstituent(int $userId, string $lglId, array $request = [], bool $skipMembership = false): void {
        $this->constituents->setData($userId);
        
        // Only override with request data if WordPress user object fields are empty
        // Priority: WordPress user object (what setData() already read) > request data
        $user = get_userdata($userId);
        if ($user && (empty($user->first_name) || empty($user->last_name))) {
            // WordPress user object is missing names, use request data as fallback
            if (!empty($request['user_firstname']) || !empty($request['user_lastname'])) {
                $this->constituents->setName(
                    $request['user_firstname'] ?? '',
                    $request['user_lastname'] ?? ''
                );
            }
        }
        // Email: only override if WordPress user email is empty
        if ($user && empty($user->user_email) && !empty($request['user_email'])) {
            $this->constituents->setEmail($request['user_email']);
        }
        if (!empty($request['user_phone'])) {
            $this->constituents->setPhone($request['user_phone']);
        }
        
        // STEP 1: Update constituent personal data
        // Note: Constituents::updateConstituent() already calls syncContactInfo() which handles email/phone/address
        $payload = $this->constituents->updateConstituent($lglId);
        $response = $this->connection->updateConstituent($lglId, $payload);
        
        if (empty($response['success'])) {
            $this->helper->error('LGL MembershipRegistrationService: Failed to update constituent', [
                'lgl_id' => $lglId,
                'error' => $response['error'] ?? 'Unknown error'
            ]);
        }
        
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
            $this->helper->error('LGL MembershipRegistrationService: Payment creation failed', [
                'lgl_id' => $lglId,
                'order_id' => $orderId,
                'error' => $result['error'] ?? 'Unknown error'
            ]);
            return null;
        }

        $paymentId = isset($result['id']) ? (int) $result['id'] : null;
        $this->helper->info('LGL MembershipRegistrationService: Payment created', [
            'payment_id' => $paymentId,
            'lgl_id' => $lglId,
            'order_id' => $orderId,
            'amount' => $amount
        ]);

        // Return full result array (includes 'id', 'success', 'data', etc.)
        return $result;
    }
    
    /**
     * Process pending group syncs (from coupon-based role assignments)
     * 
     * @param int $userId WordPress user ID
     * @param string $lglId LGL constituent ID
     * @return void
     */
    private function processPendingGroupSyncs(int $userId, string $lglId): void {
        try {
            $container = \UpstateInternational\LGL\Core\ServiceContainer::getInstance();
            if ($container->has('woocommerce.role_assignment_handler')) {
                $roleHandler = $container->get('woocommerce.role_assignment_handler');
                $roleHandler->processPendingGroupSyncs($userId, $lglId);
            }
        } catch (\Exception $e) {
            $this->helper->error('LGL MembershipRegistrationService: Error processing pending group syncs', [
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'lgl_id' => $lglId
            ]);
            // Don't throw - group sync failure shouldn't break registration
        }
    }
}
