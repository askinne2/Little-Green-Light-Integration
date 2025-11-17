<?php
/**
 * LGL API Rate Limiter
 * 
 * Enforces LGL API rate limits (300 calls per 5 minutes) to prevent
 * hitting API limits and ensure reliable operation.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\LGL;

/**
 * RateLimiter Class
 * 
 * Tracks API calls in a sliding 5-minute window and enforces rate limits
 */
class RateLimiter {
    
    /**
     * Maximum API calls per window
     */
    const RATE_LIMIT = 300;
    
    /**
     * Time window in seconds (5 minutes)
     */
    const WINDOW_SECONDS = 300;
    
    /**
     * Minimum delay between requests (milliseconds)
     */
    const MIN_DELAY_MS = 1100;
    
    /**
     * Transient key for storing request history
     */
    const TRANSIENT_KEY = 'lgl_rate_limiter_requests';
    
    /**
     * Transient key for last request timestamp
     */
    const LAST_REQUEST_KEY = 'lgl_rate_limiter_last';
    
    /**
     * Class instance
     * 
     * @var RateLimiter|null
     */
    private static $instance = null;
    
    /**
     * Get instance
     * 
     * @return RateLimiter
     */
    public static function getInstance(): RateLimiter {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        
        return self::$instance;
    }
    
    /**
     * Check if a request can be made without exceeding rate limit
     * 
     * @return bool True if request can be made
     */
    public function canMakeRequest(): bool {
        $requests = $this->getRecentRequests();
        return count($requests) < self::RATE_LIMIT;
    }
    
    /**
     * Record a new API request
     * 
     * @return void
     */
    public function recordRequest(): void {
        $requests = $this->getRecentRequests();
        $requests[] = time();
        
        // Keep only requests within the window
        $cutoff = time() - self::WINDOW_SECONDS;
        $requests = array_filter($requests, function($timestamp) use ($cutoff) {
            return $timestamp > $cutoff;
        });
        
        // Store updated request history
        set_transient(self::TRANSIENT_KEY, $requests, self::WINDOW_SECONDS + 60);
        set_transient(self::LAST_REQUEST_KEY, time(), self::WINDOW_SECONDS + 60);
        
        Helper::getInstance()->debug('Rate Limiter: Request recorded. Total in window: ' . count($requests));
    }
    
    /**
     * Wait if necessary to respect rate limits
     * 
     * Ensures minimum delay between requests
     * 
     * @return void
     */
    public function waitIfNeeded(): void {
        $last_request = get_transient(self::LAST_REQUEST_KEY);
        
        if ($last_request === false) {
            return; // No previous request
        }
        
        $elapsed = (microtime(true) * 1000) - ($last_request * 1000);
        $wait_time = self::MIN_DELAY_MS - $elapsed;
        
        if ($wait_time > 0) {
            Helper::getInstance()->debug("Rate Limiter: Waiting {$wait_time}ms before next request");
            usleep($wait_time * 1000); // Convert ms to microseconds
        }
    }
    
    /**
     * Block and wait until rate limit allows a request
     * 
     * @param int $max_wait Maximum time to wait in seconds (default: 60)
     * @return bool True if can proceed, false if timeout
     */
    public function waitForAvailability(int $max_wait = 60): bool {
        $start_time = time();
        
        while (!$this->canMakeRequest()) {
            if (time() - $start_time > $max_wait) {
                Helper::getInstance()->debug('Rate Limiter: Timeout waiting for availability');
                return false;
            }
            
            $oldest_request = $this->getOldestRequestTime();
            $wait_until = $oldest_request + self::WINDOW_SECONDS + 1;
            $wait_seconds = $wait_until - time();
            
            if ($wait_seconds > 0 && $wait_seconds <= $max_wait) {
                Helper::getInstance()->debug("Rate Limiter: Waiting {$wait_seconds}s for rate limit window");
                sleep(min($wait_seconds, $max_wait - (time() - $start_time)));
            } else {
                sleep(1); // Check again in 1 second
            }
        }
        
        return true;
    }
    
    /**
     * Get recent API requests within the time window
     * 
     * @return array Array of timestamps
     */
    public function getRecentRequests(): array {
        $requests = get_transient(self::TRANSIENT_KEY);
        
        if ($requests === false) {
            return [];
        }
        
        // Filter out requests older than the window
        $cutoff = time() - self::WINDOW_SECONDS;
        $requests = array_filter($requests, function($timestamp) use ($cutoff) {
            return $timestamp > $cutoff;
        });
        
        return array_values($requests); // Re-index array
    }
    
    /**
     * Get timestamp of oldest request in current window
     * 
     * @return int|null Timestamp or null if no requests
     */
    private function getOldestRequestTime(): ?int {
        $requests = $this->getRecentRequests();
        
        if (empty($requests)) {
            return null;
        }
        
        return min($requests);
    }
    
    /**
     * Get current rate limit status
     * 
     * @return array Status information
     */
    public function getStatus(): array {
        $requests = $this->getRecentRequests();
        $count = count($requests);
        $remaining = max(0, self::RATE_LIMIT - $count);
        $percentage = ($count / self::RATE_LIMIT) * 100;
        
        $oldest = $this->getOldestRequestTime();
        $window_reset = $oldest ? $oldest + self::WINDOW_SECONDS : null;
        $seconds_until_reset = $window_reset ? max(0, $window_reset - time()) : 0;
        
        return [
            'limit' => self::RATE_LIMIT,
            'used' => $count,
            'remaining' => $remaining,
            'percentage_used' => round($percentage, 1),
            'window_seconds' => self::WINDOW_SECONDS,
            'reset_in_seconds' => $seconds_until_reset,
            'reset_at' => $window_reset,
            'can_make_request' => $this->canMakeRequest(),
            'is_near_limit' => $percentage > 80,
            'is_at_limit' => $count >= self::RATE_LIMIT
        ];
    }
    
    /**
     * Reset rate limiter (clear all request history)
     * 
     * @return void
     */
    public function reset(): void {
        delete_transient(self::TRANSIENT_KEY);
        delete_transient(self::LAST_REQUEST_KEY);
        
        Helper::getInstance()->debug('Rate Limiter: Reset - all request history cleared');
    }
    
    /**
     * Get formatted status message for admin display
     * 
     * @return string Status message
     */
    public function getStatusMessage(): string {
        $status = $this->getStatus();
        
        $message = sprintf(
            'API Rate Limit: %d/%d requests used (%.1f%%) - %d remaining',
            $status['used'],
            $status['limit'],
            $status['percentage_used'],
            $status['remaining']
        );
        
        if ($status['is_at_limit']) {
            $message .= sprintf(' - LIMIT REACHED! Reset in %ds', $status['reset_in_seconds']);
        } elseif ($status['is_near_limit']) {
            $message .= sprintf(' - WARNING: Approaching limit! Reset in %ds', $status['reset_in_seconds']);
        }
        
        return $message;
    }
    
    /**
     * Check if an admin warning should be displayed
     * 
     * @return bool True if warning should be shown
     */
    public function shouldShowWarning(): bool {
        $status = $this->getStatus();
        return $status['is_near_limit'] || $status['is_at_limit'];
    }
    
    /**
     * Get recommended delay between requests (in milliseconds)
     * 
     * Calculates delay to stay safely under the rate limit
     * 
     * @return int Delay in milliseconds
     */
    public function getRecommendedDelay(): int {
        $status = $this->getStatus();
        
        if ($status['percentage_used'] > 90) {
            // Near limit - be more conservative
            return 2000; // 2 seconds
        } elseif ($status['percentage_used'] > 75) {
            // Getting close - add buffer
            return 1500; // 1.5 seconds
        }
        
        // Normal operation
        return self::MIN_DELAY_MS; // 1.1 seconds
    }
}

