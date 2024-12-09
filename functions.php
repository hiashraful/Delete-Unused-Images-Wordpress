// Add the buttons to the Media Library toolbar
function add_image_management_buttons() {
    if (!current_user_can('manage_options')) {
        return;
    }
 
    echo '<div class="alignright actions">';
    echo '<button class="button button-primary" id="mark-unused-images-button">';
    echo 'Mark Unused Images';
    echo '</button>';
    echo '<button class="button button-secondary" id="remove-delete-prefix-button">';
    echo 'Remove Delete_ Prefix';
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
                            location.reload();
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
 
            $("#remove-delete-prefix-button").on("click", function () {
                const button = $(this);
                button.prop("disabled", true).text("Processing...");
 
                $.ajax({
                    url: "<?php echo admin_url('admin-ajax.php'); ?>",
                    type: "POST",
                    dataType: "json",
                    data: {
                        action: "remove_delete_prefix",
                        security: "<?php echo wp_create_nonce('remove_delete_prefix_action'); ?>"
                    },
                    success: function (response) {
                        if (response.success) {
                            alert(response.data.message + "\nUpdated Images: " + response.data.updated_count);
                            location.reload();
                        } else {
                            alert("Error: " + response.data.message);
                        }
                        button.prop("disabled", false).text("Remove Delete_ Prefix");
                    },
                    error: function () {
                        alert("An error occurred while processing. Please check the console for details.");
                        button.prop("disabled", false).text("Remove Delete_ Prefix");
                    },
                });
            });
        });
    </script>
    <?php
}
add_action('restrict_manage_posts', 'add_image_management_buttons');
 
// Ensure the buttons only appear in the Media Library
function show_button_in_media_library($post_type) {
    if ($post_type !== 'attachment') {
        remove_action('restrict_manage_posts', 'add_image_management_buttons');
    }
}
add_action('load-upload.php', function () {
    show_button_in_media_library(get_current_screen()->post_type);
});
 
// AJAX handler: Mark unused images
function mark_unused_images_ajax_handler() {
    check_ajax_referer('mark_unused_images_action', 'security');
 
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'You do not have sufficient permissions.']);
    }
 
    global $wpdb;
 
    $used_images = []; // Initialize array to track used images
    $unused_count = 0; // Counter for unused images
    $used_count = 0;   // Counter for used images
 
    // Site logo and favicon
    $site_logo_id = get_theme_mod('custom_logo');
    $site_icon_id = get_option('site_icon');
    $ids_to_include = array_filter([$site_logo_id, $site_icon_id]);
 
    foreach ($ids_to_include as $id) {
        $url = untrailingslashit(esc_url_raw(wp_get_attachment_url($id)));
        if ($url) $used_images[] = $url;
    }
 
    // WooCommerce images
    if (class_exists('WooCommerce')) {
        $products = wc_get_products(['limit' => -1]);
        foreach ($products as $product) {
            $used_images[] = untrailingslashit(esc_url_raw(wp_get_attachment_url(get_post_thumbnail_id($product->get_id()))));
            $gallery_image_ids = $product->get_gallery_image_ids();
            foreach ($gallery_image_ids as $id) {
                $used_images[] = untrailingslashit(esc_url_raw(wp_get_attachment_url($id)));
            }
        }
    }
 
    // Posts and Pages including drafts and private posts
    $posts = $wpdb->get_results("SELECT ID, post_content FROM $wpdb->posts WHERE post_status IN ('publish', 'draft', 'private')", ARRAY_A);
    foreach ($posts as $post) {
        preg_match_all('/https?:\/\/[^\s"]+\.(jpg|jpeg|png|gif|webp)/i', $post['post_content'], $matches);
        if (!empty($matches[0])) $used_images = array_merge($used_images, $matches[0]);
 
        $thumbnail_id = get_post_thumbnail_id($post['ID']);
        if ($thumbnail_id) {
            $used_images[] = untrailingslashit(esc_url_raw(wp_get_attachment_url($thumbnail_id)));
        }
 
        // Elementor data in meta fields
        $meta_fields = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_value FROM $wpdb->postmeta WHERE post_id = %d",
            $post['ID']
        ), ARRAY_A);
 
        foreach ($meta_fields as $meta) {
            $meta_data = maybe_unserialize($meta['meta_value']);
            if (is_string($meta_data) && json_decode($meta_data, true)) {
                $decoded_data = json_decode($meta_data, true);
                $used_images = array_merge($used_images, extract_image_urls_from_elementor_data($decoded_data));
            }
        }
    }
 
    // Post Categories and Product Categories
    $taxonomies = ['category', 'product_cat'];
    foreach ($taxonomies as $taxonomy) {
        $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
        foreach ($terms as $term) {
            $thumbnail_id = get_term_meta($term->term_id, 'thumbnail_id', true);
            if ($thumbnail_id) {
                $used_images[] = untrailingslashit(esc_url_raw(wp_get_attachment_url($thumbnail_id)));
            }
        }
    }
 
    // Mark unused images in Media Library
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
                if (strpos($current_title, 'Delete_') !== 0) {
                    wp_update_post(['ID' => $media_id, 'post_title' => "Delete_" . $current_title]);
                    $unused_count++;
                }
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
 
// AJAX handler: Remove Delete_ prefix
function remove_delete_prefix_ajax_handler() {
    check_ajax_referer('remove_delete_prefix_action', 'security');
 
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'You do not have sufficient permissions.']);
    }
 
    global $wpdb;
 
    $updated_count = 0;
 
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
            $current_title = get_the_title($media_id);
 
            if (strpos($current_title, 'Delete_') === 0) {
                $new_title = substr($current_title, 7); // Remove "Delete_" prefix
                wp_update_post(['ID' => $media_id, 'post_title' => $new_title]);
                $updated_count++;
            }
        }
    }
 
    wp_reset_postdata();
 
    wp_send_json_success([
        'message'       => 'Delete_ prefix removal complete.',
        'updated_count' => $updated_count,
    ]);
}
add_action('wp_ajax_remove_delete_prefix', 'remove_delete_prefix_ajax_handler');
 
// Helper function to extract image URLs from Elementor data
function extract_image_urls_from_elementor_data($data) {
    $urls = [];
    foreach ($data as $element) {
        if (isset($element['settings'])) {
            foreach ($element['settings'] as $key => $value) {
                if (is_string($value) && preg_match('/https?:\/\/[^\s"]+\.(jpg|jpeg|png|gif|webp)/i', $value)) {
                    $urls[] = $value;
                }
                if (is_array($value) && isset($value['url'])) {
                    $urls[] = $value['url'];
                }
            }
        }
        if (isset($element['elements']) && is_array($element['elements'])) {
            $urls = array_merge($urls, extract_image_urls_from_elementor_data($element['elements']));
        }
    }
    return $urls;
}
