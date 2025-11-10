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

        if ($membershipLevelId) {
            \update_user_meta($userId, 'lgl_membership_level_id', (int) $membershipLevelId);
        }

        // CHECK USER META FIRST - Single source of truth for existing users
        $existingLglId = get_user_meta($userId, 'lgl_id', true);
        
        if ($existingLglId) {
            // User already has an LGL ID - use it directly (no API search needed)
            $this->helper->debug('✅ MembershipRegistrationService: Using existing lgl_id from user meta', [
                'user_id' => $userId,
                'lgl_id' => $existingLglId,
                'source' => 'user_meta'
            ]);
            
            $lglId = $existingLglId;
            $matchMethod = 'user_meta';
            $matchedEmail = null;
            $created = false;
            
            // Update the existing constituent
            $this->ensureUserProfileHasNames($userId, $request, $order);
            $this->updateConstituent($userId, (string) $lglId, $request);
            
        } else {
            // No existing LGL ID - search LGL database
            $this->helper->debug('🔍 MembershipRegistrationService: No lgl_id in user meta, searching LGL', [
                'user_id' => $userId,
                'search_name' => $searchName,
                'emails' => $emails
            ]);
            
            $match = $this->connection->searchByName($searchName, !empty($emails) ? $emails : $email);
            $matchMethod = $match['method'] ?? null;
            $matchedEmail = $match['email'] ?? null;
            $lglId = $match['id'] ?? null;
            $created = false;

            if (!$lglId) {
                // Not found in LGL - create new constituent
                $this->helper->debug('🆕 MembershipRegistrationService: Creating new constituent', [
                    'user_id' => $userId
                ]);
                $this->ensureUserProfileHasNames($userId, $request, $order);
                $lglId = $this->createConstituent($userId, $request);
                $created = true;
            } else {
                // Found in LGL - update existing constituent
                $this->helper->debug('✏️ MembershipRegistrationService: Updating matched constituent', [
                    'user_id' => $userId,
                    'lgl_id' => $lglId,
                    'match_method' => $matchMethod
                ]);
                $this->ensureUserProfileHasNames($userId, $request, $order);
                $this->updateConstituent($userId, (string) $lglId, $request);
            }
            
            // Save the LGL ID to user meta for future use
            $this->helper->debug('💾 MembershipRegistrationService: Saving lgl_id to user meta', [
                'user_id' => $userId,
                'lgl_id' => $lglId
            ]);
            \update_user_meta($userId, 'lgl_id', $lglId);
        }

        $constituentVerification = $this->connection->getConstituent((string) $lglId);
        \update_user_meta($userId, 'user-membership-type', $membershipLevel);

        $paymentId = null;
        $paymentVerification = null;
        if ($orderId > 0 && $price > 0 && !$isFamilyMember) {
            $paymentId = $this->createPayment((string) $lglId, $orderId, $price, $paymentType ?? 'online');
            if ($paymentId) {
                $paymentVerification = $this->connection->getPayment((string) $paymentId);
            }
        }

        $status = 'synced';
        if (empty($constituentVerification['success'])) {
            $status = 'unsynced';
        } elseif ($paymentId && ($paymentVerification === null || empty($paymentVerification['success']))) {
            $status = 'partial';
        }

        return [
            'lgl_id' => (string) $lglId,
            'created' => $created,
            'match_method' => $matchMethod,
            'matched_email' => $matchedEmail,
            'payment_id' => $paymentId,
            'constituent_response' => $constituentVerification,
            'payment_response' => $paymentVerification,
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

    private function createConstituent(int $userId, array $request = []): string {
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
            'user_id' => $userId
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
        $this->addConstituentDetails($lglId, $userId);

        return $lglId;
    }
    
    /**
     * Add constituent details via separate POST requests (matching legacy lgl_add_object)
     * 
     * @param string $lglId LGL constituent ID
     * @param int $userId WordPress user ID
     */
    private function addConstituentDetails(string $lglId, int $userId): void {
        // STEP 2: Add email address
        $emailData = $this->constituents->getEmailData();
        if (!empty($emailData)) {
            foreach ($emailData as $email) {
                $response = $this->connection->addEmailAddress($lglId, $email);
                $this->helper->debug('✅ MembershipRegistrationService: Email added (Step 2/4)', [
                    'lgl_id' => $lglId,
                    'success' => $response['success'] ?? false,
                    'response' => $response
                ]);
            }
        } else {
            $this->helper->debug('⚠️ MembershipRegistrationService: No email data to add (Step 2/4)');
        }
        
        // STEP 3: Add phone number
        $phoneData = $this->constituents->getPhoneData();
        if (!empty($phoneData)) {
            foreach ($phoneData as $phone) {
                $response = $this->connection->addPhoneNumber($lglId, $phone);
                $this->helper->debug('✅ MembershipRegistrationService: Phone added (Step 3/4)', [
                    'lgl_id' => $lglId,
                    'success' => $response['success'] ?? false,
                    'response' => $response
                ]);
            }
        } else {
            $this->helper->debug('⚠️ MembershipRegistrationService: No phone data to add (Step 3/4)');
        }
        
        // STEP 4: Add street address
        $addressData = $this->constituents->getAddressData();
        if (!empty($addressData)) {
            foreach ($addressData as $address) {
                $response = $this->connection->addStreetAddress($lglId, $address);
                $this->helper->debug('✅ MembershipRegistrationService: Address added (Step 4/4)', [
                    'lgl_id' => $lglId,
                    'success' => $response['success'] ?? false,
                    'response' => $response
                ]);
            }
        } else {
            $this->helper->debug('⚠️ MembershipRegistrationService: No address data to add (Step 4/4)');
        }
        
        // STEP 5A: CRITICAL - Deactivate old active memberships BEFORE adding new one
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

    private function updateConstituent(int $userId, string $lglId, array $request = []): void {
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
        $payload = $this->constituents->updateConstituent($lglId);
        $response = $this->connection->updateConstituent($lglId, $payload);
        $this->helper->debug('✅ MembershipRegistrationService: Constituent updated (Step 1/5)', [
            'lgl_id' => $lglId,
            'http_code' => $response['http_code'] ?? null
        ]);
        
        // STEP 2-5: Update email, phone, address, and membership separately (matching legacy pattern)
        // Note: For updates, we typically only add NEW data, not replace existing
        $this->addConstituentDetails($lglId, $userId);
    }

    private function createPayment(string $lglId, int $orderId, float $amount, string $paymentType): ?int {
        $result = $this->payments->setupMembershipPayment($lglId, $orderId, $amount, date('Y-m-d'), $paymentType);

        if (empty($result['success'])) {
            $this->helper->debug('⚠️ MembershipRegistrationService: Payment creation failed', $result);
            return null;
        }

        $paymentId = isset($result['id']) ? (int) $result['id'] : null;
        $this->helper->debug('✅ MembershipRegistrationService: Payment created', [
            'payment_id' => $paymentId,
            'lgl_id' => $lglId,
            'order_id' => $orderId,
            'amount' => $amount
        ]);

        return $paymentId;
    }
}
