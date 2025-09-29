<?php
/**
 * UI Memberships Shortcode
 * 
 * Modern shortcode handler for UI memberships functionality.
 * Replaces the legacy UI_Memberships shortcode with proper architecture.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\Shortcodes;

use UpstateInternational\LGL\LGL\Helper;
use UpstateInternational\LGL\Memberships\MembershipRenewalManager;
use UpstateInternational\LGL\Memberships\MembershipUserManager;

/**
 * UiMembershipsShortcode Class
 * 
 * Handles the [ui_memberships] shortcode with modern architecture
 */
class UiMembershipsShortcode {
    
    /**
     * Helper service
     * 
     * @var Helper
     */
    private Helper $helper;
    
    /**
     * Membership renewal manager
     * 
     * @var MembershipRenewalManager
     */
    private MembershipRenewalManager $renewalManager;
    
    /**
     * Membership user manager
     * 
     * @var MembershipUserManager
     */
    private MembershipUserManager $userManager;
    
    /**
     * Constructor
     * 
     * @param Helper $helper Helper service
     * @param MembershipRenewalManager $renewalManager Renewal manager
     * @param MembershipUserManager $userManager User manager
     */
    public function __construct(
        Helper $helper,
        MembershipRenewalManager $renewalManager,
        MembershipUserManager $userManager
    ) {
        $this->helper = $helper;
        $this->renewalManager = $renewalManager;
        $this->userManager = $userManager;
    }
    
    /**
     * Render the shortcode
     * 
     * @param array $atts Shortcode attributes
     * @return string Shortcode output
     */
    public function renderShortcode(array $atts = []): string {
        // Parse shortcode attributes
        $atts = shortcode_atts([
            'action' => 'run_update',
            'display' => 'results',
            'admin_only' => 'true'
        ], $atts, 'ui_memberships');
        
        // Check admin permissions if required
        if ($atts['admin_only'] === 'true' && !current_user_can('manage_options')) {
            return '<p><em>Access denied. Administrator privileges required.</em></p>';
        }
        
        $this->helper->debug('UI Memberships shortcode executed', $atts);
        
        try {
            switch ($atts['action']) {
                case 'run_update':
                    return $this->runMembershipUpdate($atts['display']);
                    
                case 'statistics':
                    return $this->displayStatistics();
                    
                case 'member_list':
                    return $this->displayMemberList();
                    
                default:
                    return $this->runMembershipUpdate($atts['display']);
            }
            
        } catch (\Exception $e) {
            $this->helper->debug('UI Memberships shortcode error: ' . $e->getMessage());
            return '<div class="notice notice-error"><p>Error: ' . esc_html($e->getMessage()) . '</p></div>';
        }
    }
    
    /**
     * Run membership renewal update
     * 
     * @param string $display Display format
     * @return string HTML output
     */
    private function runMembershipUpdate(string $display): string {
        $start_time = microtime(true);
        
        // Process all members
        $results = $this->renewalManager->processAllMembers();
        
        $execution_time = round((microtime(true) - $start_time) * 1000, 2);
        
        if ($display === 'silent') {
            return '<!-- UI Memberships update completed silently -->';
        }
        
        // Generate HTML output
        $output = '<div class="ui-memberships-results">';
        $output .= '<h3>🔄 UI Memberships Processing Complete</h3>';
        
        $output .= '<div class="results-summary">';
        $output .= '<p><strong>Execution Time:</strong> ' . $execution_time . 'ms</p>';
        $output .= '<p><strong>Members Processed:</strong> ' . $results['processed'] . '</p>';
        $output .= '<p><strong>Notifications Sent:</strong> ' . $results['notified'] . '</p>';
        $output .= '<p><strong>Memberships Deactivated:</strong> ' . $results['deactivated'] . '</p>';
        $output .= '<p><strong>Members Skipped:</strong> ' . $results['skipped'] . '</p>';
        $output .= '</div>';
        
        // Show errors if any
        if (!empty($results['errors'])) {
            $output .= '<div class="error-details">';
            $output .= '<h4>⚠️ Errors Encountered (' . count($results['errors']) . '):</h4>';
            $output .= '<ul>';
            foreach ($results['errors'] as $error) {
                $output .= '<li>User ID ' . $error['user_id'] . ': ' . esc_html($error['error']) . '</li>';
            }
            $output .= '</ul>';
            $output .= '</div>';
        }
        
        $output .= '</div>';
        
        // Add some basic styling
        $output .= '<style>
            .ui-memberships-results {
                background: #f9f9f9;
                border: 1px solid #ddd;
                padding: 20px;
                margin: 20px 0;
                border-radius: 5px;
            }
            .results-summary {
                background: #fff;
                padding: 15px;
                border-radius: 3px;
                margin: 10px 0;
            }
            .error-details {
                background: #ffeaea;
                padding: 15px;
                border-left: 4px solid #dc3232;
                margin: 10px 0;
            }
        </style>';
        
        return $output;
    }
    
    /**
     * Display membership statistics
     * 
     * @return string HTML output
     */
    private function displayStatistics(): string {
        $stats = $this->userManager->getMembershipStatistics();
        
        $output = '<div class="ui-memberships-statistics">';
        $output .= '<h3>📊 UI Memberships Statistics</h3>';
        
        $output .= '<div class="stats-grid">';
        
        // Total members
        $output .= '<div class="stat-card">';
        $output .= '<h4>Total Members</h4>';
        $output .= '<p class="stat-number">' . $stats['total_members'] . '</p>';
        $output .= '</div>';
        
        // By status
        $output .= '<div class="stat-card">';
        $output .= '<h4>By Status</h4>';
        foreach ($stats['by_status'] as $status => $count) {
            $status_label = ucwords(str_replace('_', ' ', $status));
            $output .= '<p>' . $status_label . ': <strong>' . $count . '</strong></p>';
        }
        $output .= '</div>';
        
        // By role
        $output .= '<div class="stat-card">';
        $output .= '<h4>By Role</h4>';
        foreach ($stats['by_role'] as $role => $count) {
            $role_label = ucwords(str_replace('_', ' ', $role));
            $output .= '<p>' . $role_label . ': <strong>' . $count . '</strong></p>';
        }
        $output .= '</div>';
        
        $output .= '</div>';
        $output .= '</div>';
        
        // Add styling
        $output .= '<style>
            .ui-memberships-statistics {
                background: #f9f9f9;
                border: 1px solid #ddd;
                padding: 20px;
                margin: 20px 0;
                border-radius: 5px;
            }
            .stats-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 15px;
                margin: 15px 0;
            }
            .stat-card {
                background: #fff;
                padding: 15px;
                border-radius: 3px;
                border: 1px solid #eee;
            }
            .stat-number {
                font-size: 2em;
                font-weight: bold;
                color: #2c5aa0;
                margin: 10px 0;
            }
        </style>';
        
        return $output;
    }
    
    /**
     * Display member list
     * 
     * @return string HTML output
     */
    private function displayMemberList(): string {
        $members = $this->userManager->getAllUiMembers();
        
        $output = '<div class="ui-memberships-list">';
        $output .= '<h3>👥 UI Members List</h3>';
        
        if (empty($members)) {
            $output .= '<p>No UI members found.</p>';
        } else {
            $output .= '<table class="widefat striped">';
            $output .= '<thead>';
            $output .= '<tr>';
            $output .= '<th>Name</th>';
            $output .= '<th>Email</th>';
            $output .= '<th>Role</th>';
            $output .= '<th>Status</th>';
            $output .= '<th>Renewal Date</th>';
            $output .= '<th>Days Until Renewal</th>';
            $output .= '</tr>';
            $output .= '</thead>';
            $output .= '<tbody>';
            
            foreach ($members as $member) {
                $status_class = $this->getStatusClass($member['membership_status']);
                $days_class = $member['days_until_renewal'] < 0 ? 'overdue' : 'current';
                
                $output .= '<tr>';
                $output .= '<td>' . esc_html($member['first_name'] . ' ' . $member['last_name']) . '</td>';
                $output .= '<td>' . esc_html($member['email']) . '</td>';
                $output .= '<td>' . esc_html(ucwords(str_replace('_', ' ', $member['primary_role']))) . '</td>';
                $output .= '<td><span class="status-badge ' . $status_class . '">' . 
                           esc_html(ucwords(str_replace('_', ' ', $member['membership_status']))) . '</span></td>';
                $output .= '<td>' . esc_html($member['renewal_date'] ?: 'Not set') . '</td>';
                $output .= '<td><span class="days-badge ' . $days_class . '">' . 
                           esc_html($member['days_until_renewal'] ?: 'N/A') . '</span></td>';
                $output .= '</tr>';
            }
            
            $output .= '</tbody>';
            $output .= '</table>';
        }
        
        $output .= '</div>';
        
        // Add styling
        $output .= '<style>
            .ui-memberships-list {
                margin: 20px 0;
            }
            .status-badge, .days-badge {
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 0.9em;
                font-weight: bold;
            }
            .status-badge.current { background: #d4edda; color: #155724; }
            .status-badge.due_soon { background: #fff3cd; color: #856404; }
            .status-badge.overdue { background: #f8d7da; color: #721c24; }
            .status-badge.expired { background: #f5c6cb; color: #721c24; }
            .days-badge.current { background: #d1ecf1; color: #0c5460; }
            .days-badge.overdue { background: #f8d7da; color: #721c24; }
        </style>';
        
        return $output;
    }
    
    /**
     * Get CSS class for membership status
     * 
     * @param string $status Membership status
     * @return string CSS class
     */
    private function getStatusClass(string $status): string {
        switch ($status) {
            case 'current':
                return 'current';
            case 'due_soon':
            case 'due_this_month':
                return 'due_soon';
            case 'overdue':
                return 'overdue';
            case 'expired':
                return 'expired';
            default:
                return 'unknown';
        }
    }
    
    /**
     * Get shortcode name
     * 
     * @return string Shortcode name
     */
    public function getName(): string {
        return 'ui_memberships';
    }
    
    /**
     * Get shortcode description
     * 
     * @return string Shortcode description
     */
    public function getDescription(): string {
        return 'Displays UI memberships processing results and statistics';
    }
}