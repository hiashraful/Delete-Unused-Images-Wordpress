// Add the button to the Media Library toolbar
function add_mark_unused_images_button() {
    if (!current_user_can('manage_options')) {
        return;
    }
 
    echo '<div class="alignright actions">';
    echo '<button class="button button-primary" id="mark-unused-images-button">';
    echo 'Mark Unused Images';
    echo '</button>';
    echo '</div>';
 
    // Add inline script for AJAX functionality
    ?>
    <script>
        jQuery(document).ready(function ($) {
            $("#mark-unused-images-button").on("click", function () {
                const button = $(this);
                button.prop("disabled", true).text("Processing...");
 
                $.ajax({
                    url: "<?php echo admin_url('admin-ajax.php'); ?>",
                    type: "POST",
                    dataType: "json",
                    data: {
                        action: "mark_unused_images",
                        security: "<?php echo wp_create_nonce('mark_unused_images_action'); ?>"
                    },
                    success: function (response) {
                        if (response.success) {
                            alert(response.data.message + "\nUsed Images: " + response.data.used_count + "\nUnused Images: " + response.data.unused_count);
                            location.reload(); // Refresh the page after processing
                        } else {
                            alert("Error: " + response.data.message);
                        }
                        button.prop("disabled", false).text("Mark Unused Images");
                    },
                    error: function () {
                        alert("An error occurred while processing. Please check the console for details.");
                        button.prop("disabled", false).text("Mark Unused Images");
                    },
                });
            });
        });
    </script>
    <?php
}
add_action('restrict_manage_posts', 'add_mark_unused_images_button');
 
// Ensure the button only appears in the Media Library
function show_button_in_media_library($post_type) {
    if ($post_type !== 'attachment') {
        remove_action('restrict_manage_posts', 'add_mark_unused_images_button');
    }
}
add_action('load-upload.php', function () {
    show_button_in_media_library(get_current_screen()->post_type);
});
 
// AJAX handler for marking unused images
function mark_unused_images_ajax_handler() {
    check_ajax_referer('mark_unused_images_action', 'security');
 
    // Ensure user has permissions
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'You do not have sufficient permissions.']);
    }
 
    global $wpdb;
 
    $used_images = []; // Initialize array to track used images
    $unused_count = 0; // Counter for unused images
    $used_count = 0;   // Counter for used images
 
    // Collect images from WooCommerce (if WooCommerce is active)
    if (class_exists('WooCommerce')) {
        $products = wc_get_products(['limit' => -1]);
        foreach ($products as $product) {
            // Add featured image
            $featured_image = get_post_thumbnail_id($product->get_id());
            if ($featured_image) {
                $used_images[] = untrailingslashit(esc_url_raw(wp_get_attachment_url($featured_image)));
            }
 
            // Add gallery images
            $gallery_image_ids = $product->get_gallery_image_ids();
            foreach ($gallery_image_ids as $gallery_image_id) {
                $used_images[] = untrailingslashit(esc_url_raw(wp_get_attachment_url($gallery_image_id)));
            }
        }
    }
 
    // Collect images from posts and pages
    $posts = $wpdb->get_results("SELECT ID, post_content FROM $wpdb->posts WHERE post_status = 'publish'", ARRAY_A);
    foreach ($posts as $post) {
        preg_match_all('/https?:\/\/[^\s"]+\.(jpg|jpeg|png|gif|webp)/i', $post['post_content'], $matches);
        if (!empty($matches[0])) {
            $used_images = array_merge($used_images, $matches[0]);
        }
    }
 
    // Collect featured images from posts, pages, and custom post types
    $post_ids = $wpdb->get_col("SELECT ID FROM $wpdb->posts WHERE post_status = 'publish'");
    foreach ($post_ids as $post_id) {
        $thumbnail_id = get_post_thumbnail_id($post_id);
        if ($thumbnail_id) {
            $used_images[] = untrailingslashit(esc_url_raw(wp_get_attachment_url($thumbnail_id)));
        }
    }
 
    // Process media library images
    $media_query = new WP_Query([
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'post_mime_type' => 'image',
        'posts_per_page' => -1,
    ]);
 
    if ($media_query->have_posts()) {
        while ($media_query->have_posts()) {
            $media_query->the_post();
            $media_id = get_the_ID();
            $media_url = untrailingslashit(esc_url_raw(wp_get_attachment_url($media_id)));
            $current_title = get_the_title($media_id);
 
            if (!in_array($media_url, $used_images)) {
                // Mark unused image
                $new_title = "Delete_" . $current_title;
                wp_update_post([
                    'ID' => $media_id,
                    'post_title' => $new_title,
                ]);
                $unused_count++;
            } else {
                $used_count++;
            }
        }
    }
 
    wp_reset_postdata();
 
    wp_send_json_success([
        'message'     => 'Image analysis complete.',
        'used_count'  => $used_count,
        'unused_count' => $unused_count,
    ]);
}
add_action('wp_ajax_mark_unused_images', 'mark_unused_images_ajax_handler');
