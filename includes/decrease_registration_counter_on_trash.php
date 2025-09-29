<?php
/**
* File Name => decrease_registration_counter_on_trash.php
* Version => 1.0
* Plugin URI =>  https =>//github.com/askinne2/Little-Green-Light-API
* Description => This class defines a Class for LGL Payment Management
* Author URI => http =>//github.com/askinne2
*/

require_once 'lgl-helper.php';

function decrease_registration_counter_on_trash($post_id) {
    $helper = LGL_Helper::get_instance();

     // Check if the trashed post is a "class_registrations" post.
     $trashed_post_type = get_post_type($post_id);
    // $helper->debug('post_type: ', $trashed_post_type);

     if ($trashed_post_type === 'class_registrations') {
            // Determine the "ui-classes" post associated with this "class_registrations" post.
            $ui_class_id = get_post_meta($trashed_post_id, 'ui_class_association', true);

            if ($ui_class_id) {
                $helper->debug('UI Class ID: ',  $ui_class_id);

                // Decrease the counter inside the "ui-classes" post.
                // You'll need to implement your logic to update the counter.
                // Example:
                // $current_counter = get_post_meta($ui_class_id, 'registration_counter', true);
                // if ($current_counter > 0) {
                //     update_post_meta($ui_class_id, 'registration_counter', $current_counter - 1);
                // }
            }
        }

}
add_action('delete_post', 'decrease_registration_counter_on_trash');
