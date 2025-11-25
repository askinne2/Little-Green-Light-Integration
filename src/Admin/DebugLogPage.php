<?php
/**
 * Debug Log Page
 *
 * Secure admin interface for viewing LGL plugin debug logs.
 * Only accessible to administrators with proper security checks.
 *
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\Admin;

use UpstateInternational\LGL\LGL\Helper;

class DebugLogPage {
    private Helper $helper;
    
    /**
     * Maximum lines to display (for performance)
     */
    const MAX_LINES = 1000;
    
    /**
     * Lines to read from end of file
     */
    const TAIL_LINES = 500;

    public function __construct(Helper $helper) {
        $this->helper = $helper;
    }

    /**
     * Render the debug log admin page.
     */
    public function render(): void {
        // Security check
        if (!current_user_can('manage_options')) {
            wp_die('Sorry, you are not allowed to access this page.');
        }

        // Handle log actions
        $this->handleActions();

        // Get log file path
        $log_file = $this->getLogFilePath();
        $log_exists = file_exists($log_file);
        
        // Get filter parameters
        $level_filter = isset($_GET['level']) ? sanitize_text_field($_GET['level']) : 'all';
        $search_term = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
        $lines_limit = isset($_GET['lines']) ? absint($_GET['lines']) : self::TAIL_LINES;
        
        // Read and filter logs
        $logs = $log_exists ? $this->readLogs($log_file, $level_filter, $search_term, $lines_limit) : [];
        $log_size = $log_exists ? filesize($log_file) : 0;
        
        ?>
        <div class="wrap lgl-admin-page">
            <h1>🔍 LGL Debug Log</h1>
            
            <?php 
            // Display environment notice
            if (function_exists('lgl_render_environment_notice')) {
                echo lgl_render_environment_notice();
                if (function_exists('lgl_render_environment_notice_script')) {
                    echo lgl_render_environment_notice_script();
                }
            }
            ?>
            
            <div class="lgl-debug-log-header">
                <p>View plugin debug logs. Logs are only written when debug mode is enabled in settings.</p>
                
                <?php if ($log_exists) : ?>
                    <div class="lgl-log-info">
                        <strong>Log File:</strong> <code><?php echo esc_html(basename($log_file)); ?></code>
                        <strong>Size:</strong> <?php echo esc_html(size_format($log_size, 2)); ?>
                        <?php if ($log_size > 0) : ?>
                            <strong>Last Modified:</strong> <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), filemtime($log_file))); ?>
                        <?php endif; ?>
                    </div>
                <?php else : ?>
                    <div class="notice notice-info">
                        <p>No log file found. Debug logging may be disabled in settings.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Filters -->
            <form method="get" class="lgl-debug-filters">
                <?php foreach ($_GET as $key => $value) : ?>
                    <?php if (in_array($key, ['level', 'search', 'lines', 'action'])) { continue; } ?>
                    <input type="hidden" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($value); ?>">
                <?php endforeach; ?>
                
                <div class="lgl-filter-row">
                    <div class="lgl-filter-field">
                        <label for="level">Log Level:</label>
                        <select name="level" id="level" onchange="this.form.submit()">
                            <option value="all" <?php selected($level_filter, 'all'); ?>>All Levels</option>
                            <option value="debug" <?php selected($level_filter, 'debug'); ?>>Debug</option>
                            <option value="info" <?php selected($level_filter, 'info'); ?>>Info</option>
                            <option value="warning" <?php selected($level_filter, 'warning'); ?>>Warning</option>
                            <option value="error" <?php selected($level_filter, 'error'); ?>>Error</option>
                        </select>
                    </div>
                    
                    <div class="lgl-filter-field">
                        <label for="search">Search:</label>
                        <input type="text" name="search" id="search" value="<?php echo esc_attr($search_term); ?>" placeholder="Search log messages...">
                    </div>
                    
                    <div class="lgl-filter-field">
                        <label for="lines">Lines:</label>
                        <select name="lines" id="lines" onchange="this.form.submit()">
                            <option value="100" <?php selected($lines_limit, 100); ?>>Last 100</option>
                            <option value="250" <?php selected($lines_limit, 250); ?>>Last 250</option>
                            <option value="500" <?php selected($lines_limit, 500); ?>>Last 500</option>
                            <option value="1000" <?php selected($lines_limit, 1000); ?>>Last 1000</option>
                        </select>
                    </div>
                    
                    <div class="lgl-filter-field">
                        <button type="submit" class="button">Filter</button>
                        <a href="<?php echo esc_url(remove_query_arg(['level', 'search', 'lines'])); ?>" class="button">Reset</a>
                    </div>
                </div>
            </form>

            <!-- Actions -->
            <div class="lgl-log-actions">
                <a href="<?php echo esc_url(add_query_arg('action', 'refresh')); ?>" class="button">🔄 Refresh</a>
                <?php if ($log_exists && $log_size > 0) : ?>
                    <a href="<?php echo esc_url(add_query_arg(['action' => 'download', '_wpnonce' => wp_create_nonce('lgl_download_log')])); ?>" class="button">📥 Download Log</a>
                    <a href="<?php echo esc_url(add_query_arg(['action' => 'clear', '_wpnonce' => wp_create_nonce('lgl_clear_log')])); ?>" 
                       class="button button-link-delete" 
                       onclick="return confirm('Are you sure you want to clear the log file? This cannot be undone.');">🗑️ Clear Log</a>
                <?php endif; ?>
            </div>

            <!-- Log Display -->
            <?php if (empty($logs)) : ?>
                <div class="notice notice-info">
                    <p>No log entries found<?php echo $log_exists ? ' matching your filters.' : '.'; ?></p>
                </div>
            <?php else : ?>
                <div class="lgl-log-viewer">
                    <div class="lgl-log-stats">
                        Showing <?php echo count($logs); ?> of <?php echo $this->countTotalLines($log_file); ?> total log entries
                    </div>
                    <div class="lgl-log-content">
                        <?php foreach ($logs as $log) : ?>
                            <div class="lgl-log-entry lgl-log-<?php echo esc_attr($log['level']); ?>">
                                <span class="lgl-log-time"><?php echo esc_html($log['timestamp']); ?></span>
                                <span class="lgl-log-level">[<?php echo esc_html(strtoupper($log['level'])); ?>]</span>
                                <div class="lgl-log-message">
                                    <?php 
                                    // Check if message contains array/object data (multi-line or Array/Object keywords)
                                    $has_multiline = strpos($log['message'], "\n") !== false;
                                    $has_array = strpos($log['message'], 'Array') !== false || strpos($log['message'], 'stdClass') !== false;
                                    $has_structure = preg_match('/\s*\([\s\S]*\)/s', $log['message']);
                                    
                                    if ($has_multiline || $has_array || $has_structure) {
                                        echo '<pre class="lgl-log-array">' . esc_html($log['message']) . '</pre>';
                                    } else {
                                        echo '<span>' . esc_html($log['message']) . '</span>';
                                    }
                                    ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <style>
            .lgl-debug-log-header {
                background: #fff;
                border: 1px solid #ccd0d4;
                border-left: 4px solid #2271b1;
                padding: 15px;
                margin: 20px 0;
            }
            .lgl-log-info {
                margin-top: 10px;
                font-size: 13px;
            }
            .lgl-log-info strong {
                margin-right: 15px;
                margin-left: 10px;
            }
            .lgl-debug-filters {
                background: #fff;
                border: 1px solid #ccd0d4;
                padding: 15px;
                margin: 20px 0;
            }
            .lgl-filter-row {
                display: flex;
                gap: 15px;
                align-items: flex-end;
                flex-wrap: wrap;
            }
            .lgl-filter-field {
                display: flex;
                flex-direction: column;
                gap: 5px;
            }
            .lgl-filter-field label {
                font-weight: 600;
                font-size: 12px;
            }
            .lgl-filter-field select,
            .lgl-filter-field input[type="text"] {
                min-width: 150px;
            }
            .lgl-log-actions {
                margin: 15px 0;
                display: flex;
                gap: 10px;
            }
            .lgl-log-viewer {
                background: #fff;
                border: 1px solid #ccd0d4;
                margin: 20px 0;
            }
            .lgl-log-stats {
                background: #f6f7f7;
                padding: 10px 15px;
                border-bottom: 1px solid #ccd0d4;
                font-size: 12px;
                color: #646970;
            }
            .lgl-log-content {
                max-height: 600px;
                overflow-y: auto;
                font-family: 'Courier New', monospace;
                font-size: 12px;
                line-height: 1.6;
            }
            .lgl-log-entry {
                padding: 8px 15px;
                border-bottom: 1px solid #f0f0f1;
                display: flex;
                gap: 10px;
                align-items: flex-start;
            }
            .lgl-log-entry:hover {
                background: #f6f7f7;
            }
            .lgl-log-time {
                color: #646970;
                white-space: nowrap;
                font-size: 11px;
            }
            .lgl-log-level {
                font-weight: 600;
                white-space: nowrap;
                min-width: 60px;
            }
            .lgl-log-message {
                flex: 1;
                word-break: break-word;
            }
            .lgl-log-message pre.lgl-log-array {
                background: #f8f9fa;
                padding: 10px;
                border-radius: 4px;
                margin: 4px 0;
                font-size: 11px;
                max-height: 400px;
                overflow-y: auto;
                white-space: pre-wrap;
                word-wrap: break-word;
                border: 1px solid #e0e0e0;
                font-family: 'Courier New', 'Monaco', 'Consolas', monospace;
            }
            .lgl-log-message pre.lgl-log-array:hover {
                background: #f0f0f0;
            }
            .lgl-log-debug .lgl-log-level { color: #646970; }
            .lgl-log-info .lgl-log-level { color: #2271b1; }
            .lgl-log-warning .lgl-log-level { color: #dba617; }
            .lgl-log-error .lgl-log-level { color: #d63638; }
            .lgl-log-error {
                background: #fcf0f1;
            }
            .lgl-log-warning {
                background: #fcf9e8;
            }
        </style>
        <?php
    }

    /**
     * Handle log actions (download, clear, etc.)
     */
    private function handleActions(): void {
        if (!isset($_GET['action'])) {
            return;
        }

        $action = sanitize_text_field($_GET['action']);
        
        switch ($action) {
            case 'download':
                if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'lgl_download_log')) {
                    wp_die('Security check failed');
                }
                $this->downloadLog();
                break;
                
            case 'clear':
                if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'lgl_clear_log')) {
                    wp_die('Security check failed');
                }
                $this->clearLog();
                break;
        }
    }

    /**
     * Get log file path
     * Uses the same path construction as Helper class
     */
    private function getLogFilePath(): string {
        // Helper uses: plugin_dir_path(__DIR__) where __DIR__ is src/LGL
        // Since Helper.php is at src/LGL/Helper.php, __DIR__ is src/LGL/
        // plugin_dir_path() expects a file path, so Helper might be using it incorrectly
        // or WordPress handles directories. Let's use Helper's actual file path.
        $helper_reflection = new \ReflectionClass(Helper::class);
        $helper_file = $helper_reflection->getFileName();
        // Get the directory containing Helper.php (src/LGL/)
        $helper_dir = dirname($helper_file);
        // Go up one level to src/, then append the log file path
        $src_dir = dirname($helper_dir);
        return $src_dir . '/' . Helper::LOG_FILE;
    }

    /**
     * Read and filter logs from file
     * Handles multi-line log entries (arrays, objects)
     */
    private function readLogs(string $file_path, string $level_filter, string $search_term, int $lines_limit): array {
        if (!file_exists($file_path) || !is_readable($file_path)) {
            return [];
        }

        // Read entire file content (for multi-line parsing)
        $content = file_get_contents($file_path);
        if ($content === false) {
            return [];
        }

        // Split into lines but preserve multi-line entries
        $all_lines = explode("\n", $content);
        $logs = [];
        $current_entry = null;
        $array_depth = 0;

        // Process lines in forward order to properly build multi-line entries
        foreach ($all_lines as $line_index => $line) {
            $line = rtrim($line); // Remove trailing newline but keep leading spaces
            $trimmed_line = trim($line);
            $is_empty = empty($trimmed_line);
            $is_new_entry = preg_match('/^\[([^\]]+)\] \[([^\]]+)\]\s*(.+)$/', $line, $matches);

            // Check if this is a new log entry
            if ($is_new_entry) {
                // Save previous entry if exists
                if ($current_entry !== null) {
                    $this->addLogEntry($logs, $current_entry, $level_filter, $search_term);
                }

                // Start new entry
                $timestamp = $matches[1];
                $level = strtolower($matches[2]);
                $message = trim($matches[3]);

                // Check if message contains "Array" or "stdClass" - indicates multi-line structure
                $has_array_keyword = (strpos($message, 'Array') !== false || strpos($message, 'stdClass') !== false);
                
                // Calculate depth from the message line itself
                $array_depth = substr_count($message, '(') - substr_count($message, ')');

                $current_entry = [
                    'timestamp' => $timestamp,
                    'level' => $level,
                    'message' => $message
                ];
                
                // If message contains Array/stdClass, expect array content on following lines
                // Don't continue - let the next iteration handle it
            } elseif ($current_entry !== null) {
                // Continuation of previous entry
                
                // Check if this looks like array content
                $is_opening_paren = $trimmed_line === '(';
                $is_closing_paren = $trimmed_line === ')';
                // Check for indented array keys (4 spaces + [key] or just indented content)
                $is_indented_key = (strpos($line, '    [') === 0) || 
                                   (strpos($line, '    (') === 0) ||
                                   (strlen($line) > 4 && $line[0] === ' ' && $line[1] === ' ' && $line[2] === ' ' && $line[3] === ' ' && strpos($trimmed_line, '[') === 0);
                $has_array_arrow = strpos($trimmed_line, '=>') !== false;
                
                // Check if current entry message contains "Array" or "stdClass" - if so, following lines are likely array content
                $entry_has_array_keyword = (strpos($current_entry['message'], 'Array') !== false || strpos($current_entry['message'], 'stdClass') !== false);
                
                // Check if next line starts a new entry
                $next_is_new_entry = false;
                if (isset($all_lines[$line_index + 1])) {
                    $next_line_trimmed = trim($all_lines[$line_index + 1]);
                    $next_is_new_entry = preg_match('/^\[/', $next_line_trimmed);
                }
                
                // Determine if this line is part of array structure
                // If entry has "Array" keyword and we haven't started tracking depth yet,
                // the next non-empty line should be array content (opening paren or first key)
                $should_treat_as_array = $entry_has_array_keyword && $array_depth <= 0 && !$is_empty && !$next_is_new_entry;
                
                $is_array_content = (
                    $array_depth > 0 ||           // Already inside array
                    $is_opening_paren ||           // Opening paren
                    $is_indented_key ||            // Indented array key
                    $has_array_arrow ||            // Contains array arrow
                    $is_closing_paren ||           // Closing paren
                    $should_treat_as_array        // Entry has Array keyword, expect array content
                );
                
                if ($is_array_content) {
                    // This is part of an array/object structure - always append
                    $current_entry['message'] .= "\n" . $line;
                    
                    // Update depth
                    $array_depth += substr_count($line, '(') - substr_count($line, ')');
                    
                    // If array is closed and next line is empty or new entry, entry is complete
                    if ($array_depth <= 0 && ($is_empty || $next_is_new_entry)) {
                        $array_depth = 0;
                    }
                } elseif ($is_empty) {
                    // Empty line
                    if ($array_depth <= 0) {
                        // Not in array, empty line ends the entry
                        // Don't append, just move on
                        continue;
                    } else {
                        // Still in array, append empty line
                        $current_entry['message'] .= "\n" . $line;
                    }
                } elseif ($next_is_new_entry) {
                    // Next line is a new entry, save current one
                    $this->addLogEntry($logs, $current_entry, $level_filter, $search_term);
                    $current_entry = null;
                    $array_depth = 0;
                } else {
                    // Regular continuation line - append it
                    $current_entry['message'] .= "\n" . $line;
                }
            }
        }

        // Don't forget the last entry
        if ($current_entry !== null) {
            $this->addLogEntry($logs, $current_entry, $level_filter, $search_term);
        }

        // Reverse to show newest first, then limit
        $logs = array_reverse($logs);
        return array_slice($logs, 0, $lines_limit);
    }

    /**
     * Add log entry if it passes filters
     */
    private function addLogEntry(array &$logs, array $entry, string $level_filter, string $search_term): void {
        // Apply level filter
        if ($level_filter !== 'all' && $entry['level'] !== $level_filter) {
            return;
        }

        // Apply search filter
        if (!empty($search_term) && stripos($entry['message'], $search_term) === false) {
            return;
        }

        $logs[] = $entry;
    }

    /**
     * Read last N lines from file efficiently
     */
    private function readLastLines(string $file_path, int $lines): array {
        $handle = fopen($file_path, 'r');
        if (!$handle) {
            return [];
        }

        // Seek to end
        fseek($handle, 0, SEEK_END);
        $file_size = ftell($handle);
        
        // Read backwards
        $buffer = '';
        $line_count = 0;
        $position = $file_size - 1;

        while ($position >= 0 && $line_count < $lines) {
            fseek($handle, $position);
            $char = fgetc($handle);
            
            if ($char === "\n" && $position < $file_size - 1) {
                $line_count++;
            }
            
            $buffer = $char . $buffer;
            $position--;
        }

        fclose($handle);
        
        return array_filter(explode("\n", $buffer));
    }

    /**
     * Count total lines in log file
     */
    private function countTotalLines(string $file_path): int {
        if (!file_exists($file_path)) {
            return 0;
        }
        
        $count = 0;
        $handle = fopen($file_path, 'r');
        if ($handle) {
            while (!feof($handle)) {
                fgets($handle);
                $count++;
            }
            fclose($handle);
        }
        return $count;
    }

    /**
     * Download log file
     */
    private function downloadLog(): void {
        $log_file = $this->getLogFilePath();
        
        if (!file_exists($log_file)) {
            wp_die('Log file not found.');
        }

        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="lgl-debug-' . date('Y-m-d-His') . '.log"');
        header('Content-Length: ' . filesize($log_file));
        
        readfile($log_file);
        exit;
    }

    /**
     * Clear log file
     */
    private function clearLog(): void {
        $log_file = $this->getLogFilePath();
        
        if (file_exists($log_file)) {
            file_put_contents($log_file, '');
            add_action('admin_notices', function() {
                echo '<div class="notice notice-success is-dismissible"><p>Log file cleared successfully.</p></div>';
            });
        }
    }
}

