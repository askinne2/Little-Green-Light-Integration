<?php
/**
 * Coupon Role Meta Handler
 * 
 * Adds role assignment meta field to WooCommerce coupons.
 * Allows each coupon to specify which WordPress role and LGL group to assign.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\WooCommerce;

use UpstateInternational\LGL\LGL\Helper;

class CouponRoleMeta {
    
    /**
     * LGL Helper service
     * 
     * @var Helper
     */
    private Helper $helper;
    
    /**
     * Meta key for role assignment
     */
    const META_KEY_ROLE = '_ui_lgl_assigned_role';
    
    /**
     * Meta key for scholarship type (if applicable)
     */
    const META_KEY_SCHOLARSHIP_TYPE = '_ui_lgl_scholarship_type';
    
    /**
     * Constructor
     * 
     * @param Helper $helper LGL helper service
     */
    public function __construct(Helper $helper) {
        $this->helper = $helper;
    }
    
    /**
     * Initialize hooks
     * 
     * @return void
     */
    public function init(): void {
        // Add custom fields to coupon edit screen
        add_action('woocommerce_coupon_options', [$this, 'addCouponRoleFields'], 10, 2);
        
        // Save custom fields
        add_action('woocommerce_coupon_options_save', [$this, 'saveCouponRoleFields'], 10, 1);
    }
    
    /**
     * Add role assignment fields to coupon edit screen
     * 
     * @param int $coupon_id Coupon ID
     * @param \WC_Coupon $coupon Coupon object
     * @return void
     */
    public function addCouponRoleFields(int $coupon_id, \WC_Coupon $coupon): void {
        $assigned_role = $coupon->get_meta(self::META_KEY_ROLE, true);
        $scholarship_type = $coupon->get_meta(self::META_KEY_SCHOLARSHIP_TYPE, true);
        
        // Get available roles from settings
        $role_mappings = $this->getAvailableRoles();
        
        ?>
        <div class="options_group">
            <p class="form-field">
                <label for="<?php echo esc_attr(self::META_KEY_ROLE); ?>">
                    <?php esc_html_e('Assign WordPress Role', 'integrate-lgl'); ?>
                </label>
                <select id="<?php echo esc_attr(self::META_KEY_ROLE); ?>" 
                        name="<?php echo esc_attr(self::META_KEY_ROLE); ?>" 
                        class="select short"
                        style="width: 100%;">
                    <option value=""><?php esc_html_e('None (No role assignment)', 'integrate-lgl'); ?></option>
                    <?php foreach ($role_mappings as $role_slug => $role_config): ?>
                        <option value="<?php echo esc_attr($role_slug); ?>" 
                                <?php selected($assigned_role, $role_slug); ?>>
                            <?php echo esc_html($role_config['label']); ?>
                            <?php if (!empty($role_config['lgl_group_name'])): ?>
                                (LGL: <?php echo esc_html($role_config['lgl_group_name']); ?>)
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="description">
                    <?php esc_html_e('When this coupon is applied, assign the selected WordPress role and sync to the corresponding LGL Group.', 'integrate-lgl'); ?>
                </span>
            </p>
            
            <p class="form-field">
                <label for="<?php echo esc_attr(self::META_KEY_SCHOLARSHIP_TYPE); ?>">
                    <?php esc_html_e('Scholarship Type (Optional)', 'integrate-lgl'); ?>
                </label>
                <select id="<?php echo esc_attr(self::META_KEY_SCHOLARSHIP_TYPE); ?>" 
                        name="<?php echo esc_attr(self::META_KEY_SCHOLARSHIP_TYPE); ?>" 
                        class="select short"
                        style="width: 100%;">
                    <option value=""><?php esc_html_e('Not a Scholarship', 'integrate-lgl'); ?></option>
                    <option value="partial" <?php selected($scholarship_type, 'partial'); ?>>
                        <?php esc_html_e('Partial Scholarship (100-200% poverty level)', 'integrate-lgl'); ?>
                    </option>
                    <option value="full" <?php selected($scholarship_type, 'full'); ?>>
                        <?php esc_html_e('Full Scholarship (Below 100% poverty level)', 'integrate-lgl'); ?>
                    </option>
                </select>
                <span class="description">
                    <?php esc_html_e('If this is a scholarship coupon, select the scholarship type. This will be tracked in order notes.', 'integrate-lgl'); ?>
                </span>
            </p>
        </div>
        <?php
    }
    
    /**
     * Save coupon role fields
     * 
     * @param int $coupon_id Coupon ID
     * @return void
     */
    public function saveCouponRoleFields(int $coupon_id): void {
        $coupon = new \WC_Coupon($coupon_id);
        
        // Save role assignment
        if (isset($_POST[self::META_KEY_ROLE])) {
            $role = sanitize_text_field($_POST[self::META_KEY_ROLE]);
            $coupon->update_meta_data(self::META_KEY_ROLE, $role);
        } else {
            $coupon->delete_meta_data(self::META_KEY_ROLE);
        }
        
        // Save scholarship type
        if (isset($_POST[self::META_KEY_SCHOLARSHIP_TYPE])) {
            $scholarship_type = sanitize_text_field($_POST[self::META_KEY_SCHOLARSHIP_TYPE]);
            $coupon->update_meta_data(self::META_KEY_SCHOLARSHIP_TYPE, $scholarship_type);
        } else {
            $coupon->delete_meta_data(self::META_KEY_SCHOLARSHIP_TYPE);
        }
        
        $coupon->save_meta_data();
        
        $this->helper->debug('💾 CouponRoleMeta: Saved coupon role fields', [
            'coupon_id' => $coupon_id,
            'coupon_code' => $coupon->get_code(),
            'assigned_role' => $coupon->get_meta(self::META_KEY_ROLE, true),
            'scholarship_type' => $coupon->get_meta(self::META_KEY_SCHOLARSHIP_TYPE, true)
        ]);
    }
    
    /**
     * Get available roles from settings
     * 
     * @return array Role mappings with labels
     */
    private function getAvailableRoles(): array {
        // Get role mappings from settings
        $container = \UpstateInternational\LGL\Core\ServiceContainer::getInstance();
        if (!$container->has('admin.settings_manager')) {
            return [];
        }
        
        $settingsManager = $container->get('admin.settings_manager');
        $role_mappings = $settingsManager->get('role_group_mappings') ?? [];
        
        // Fetch group names dynamically from LGL API
        $group_names = $this->getGroupNamesFromApi();
        
        // Build role options with labels
        $roles = [];
        
        // Add standard roles
        $teacher_group_id = $role_mappings['ui_teacher']['lgl_group_id'] ?? null;
        $roles['ui_teacher'] = [
            'label' => 'Teacher',
            'lgl_group_id' => $teacher_group_id,
            'lgl_group_name' => $group_names[$teacher_group_id] ?? 'Teacher'
        ];
        
        $board_group_id = $role_mappings['ui_board']['lgl_group_id'] ?? null;
        $roles['ui_board'] = [
            'label' => 'Board Member',
            'lgl_group_id' => $board_group_id,
            'lgl_group_name' => $group_names[$board_group_id] ?? 'Board Member'
        ];
        
        $vip_group_id = $role_mappings['ui_vip']['lgl_group_id'] ?? null;
        $roles['ui_vip'] = [
            'label' => 'VIP',
            'lgl_group_id' => $vip_group_id,
            'lgl_group_name' => $group_names[$vip_group_id] ?? 'Staff'
        ];
        
        // Add member role (for scholarships)
        $roles['ui_member'] = [
            'label' => 'Member (No LGL Group)',
            'lgl_group_id' => null,
            'lgl_group_name' => null
        ];
        
        return $roles;
    }
    
    /**
     * Fetch group names from LGL API
     * 
     * @return array Map of group_id => group_name
     */
    private function getGroupNamesFromApi(): array {
        $group_names = [];
        
        try {
            $container = \UpstateInternational\LGL\Core\ServiceContainer::getInstance();
            if (!$container->has('lgl.connection')) {
                return $group_names;
            }
            
            $connection = $container->get('lgl.connection');
            $response = $connection->makeRequest('groups.json', 'GET');
            
            if ($response['success']) {
                $groups_data = $response['data'] ?? [];
                $groups = $groups_data['items'] ?? (is_array($groups_data) && isset($groups_data[0]['id']) ? $groups_data : []);
                
                foreach ($groups as $group) {
                    $group_id = (int) ($group['id'] ?? 0);
                    $group_name = $group['name'] ?? '';
                    if ($group_id > 0 && !empty($group_name)) {
                        $group_names[$group_id] = $group_name;
                    }
                }
            }
        } catch (\Exception $e) {
            $this->helper->debug('⚠️ CouponRoleMeta: Failed to fetch group names from API', [
                'error' => $e->getMessage()
            ]);
        }
        
        return $group_names;
    }
    
    /**
     * Get role assignment from coupon
     * 
     * @param string $coupon_code Coupon code
     * @return array|null Role assignment config or null if not set
     */
    public function getCouponRoleAssignment(string $coupon_code): ?array {
        $coupon = new \WC_Coupon($coupon_code);
        
        if (!$coupon->get_id()) {
            $this->helper->debug('⚠️ CouponRoleMeta: Coupon not found', [
                'coupon_code' => $coupon_code
            ]);
            return null;
        }
        
        $assigned_role = $coupon->get_meta(self::META_KEY_ROLE, true);
        $scholarship_type = $coupon->get_meta(self::META_KEY_SCHOLARSHIP_TYPE, true);
        
        if (empty($assigned_role)) {
            $this->helper->debug('⚠️ CouponRoleMeta: No role assigned in coupon meta', [
                'coupon_code' => $coupon_code,
                'coupon_id' => $coupon->get_id()
            ]);
            return null;
        }
        
        $this->helper->debug('✅ CouponRoleMeta: Found role assignment in coupon meta', [
            'coupon_code' => $coupon_code,
            'coupon_id' => $coupon->get_id(),
            'assigned_role' => $assigned_role,
            'scholarship_type' => $scholarship_type
        ]);
        
        // Get role mapping from settings
        $container = \UpstateInternational\LGL\Core\ServiceContainer::getInstance();
        if (!$container->has('admin.settings_manager')) {
            $this->helper->debug('⚠️ CouponRoleMeta: SettingsManager not available');
            // Use default group IDs as fallback
            return $this->getDefaultRoleAssignment($assigned_role, $scholarship_type);
        }
        
        $settingsManager = $container->get('admin.settings_manager');
        $role_mappings = $settingsManager->get('role_group_mappings') ?? [];
        
        $role_config = $role_mappings[$assigned_role] ?? null;
        
        if (!$role_config) {
            $this->helper->debug('⚠️ CouponRoleMeta: Role not in role_group_mappings, using defaults', [
                'assigned_role' => $assigned_role,
                'role_mappings_count' => count($role_mappings)
            ]);
            // Use default group IDs as fallback
            return $this->getDefaultRoleAssignment($assigned_role, $scholarship_type);
        }
        
        $this->helper->debug('✅ CouponRoleMeta: Role assignment retrieved', [
            'wp_role' => $assigned_role,
            'lgl_group_id' => $role_config['lgl_group_id'] ?? null,
            'lgl_group_key' => $role_config['lgl_group_key'] ?? null
        ]);
        
        return [
            'wp_role' => $assigned_role,
            'lgl_group_id' => $role_config['lgl_group_id'] ?? null,
            'lgl_group_key' => $role_config['lgl_group_key'] ?? null,
            'scholarship_type' => $scholarship_type
        ];
    }
    
    /**
     * Get default role assignment when role_group_mappings is not configured
     * 
     * @param string $role Role slug
     * @param string|null $scholarship_type Scholarship type
     * @return array|null Role assignment config
     */
    private function getDefaultRoleAssignment(string $role, ?string $scholarship_type = null): ?array {
        // Get group IDs from settings (dynamically synced from LGL)
        $container = \UpstateInternational\LGL\Core\ServiceContainer::getInstance();
        if (!$container->has('admin.settings_manager')) {
            return null;
        }
        
        $settingsManager = $container->get('admin.settings_manager');
        $role_mappings = $settingsManager->get('role_group_mappings') ?? [];
        
        // Handle scholarship groups
        if ($role === 'ui_member' && !empty($scholarship_type)) {
            if ($scholarship_type === 'partial') {
                $group_id = $settingsManager->get('group_id_scholarship_partial');
                if ($group_id) {
                    return [
                        'wp_role' => $role,
                        'lgl_group_id' => (int) $group_id,
                        'lgl_group_key' => 'scholarship_partial',
                        'scholarship_type' => $scholarship_type
                    ];
                }
            } elseif ($scholarship_type === 'full') {
                $group_id = $settingsManager->get('group_id_scholarship_full');
                if ($group_id) {
                    return [
                        'wp_role' => $role,
                        'lgl_group_id' => (int) $group_id,
                        'lgl_group_key' => 'scholarship_full',
                        'scholarship_type' => $scholarship_type
                    ];
                }
            }
        }
        
        // Standard role mappings
        $role_config = $role_mappings[$role] ?? null;
        if (!$role_config) {
            // Fallback for roles not in mappings
            $default_keys = [
                'ui_teacher' => 'teacher',
                'ui_board' => 'board_member',
                'ui_vip' => 'staff',
            ];
            
            if (!isset($default_keys[$role])) {
                return null;
            }
            
            return [
                'wp_role' => $role,
                'lgl_group_id' => null,
                'lgl_group_key' => $default_keys[$role],
                'scholarship_type' => $scholarship_type
            ];
        }
        
        $this->helper->debug('✅ CouponRoleMeta: Using role assignment from settings', [
            'wp_role' => $role,
            'lgl_group_id' => $role_config['lgl_group_id'] ?? null,
            'lgl_group_key' => $role_config['lgl_group_key'] ?? null
        ]);
        
        return [
            'wp_role' => $role,
            'lgl_group_id' => $role_config['lgl_group_id'] ?? null,
            'lgl_group_key' => $role_config['lgl_group_key'] ?? null,
            'scholarship_type' => $scholarship_type
        ];
    }
}

