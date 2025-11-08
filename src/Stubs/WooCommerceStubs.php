<?php

namespace {
    if (!class_exists('WC_Order')) {
        /**
         * Minimal WooCommerce order stub for development/testing contexts.
         * Ensures type hints resolve when WooCommerce is not loaded.
         */
        class WC_Order {}
    }
}
