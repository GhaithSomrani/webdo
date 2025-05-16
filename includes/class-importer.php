<?php
/**
 * WebDo Article Importer - Core Importer Class
 * 
 * Place this file in: /wp-content/plugins/webdo-article-importer/includes/class-importer.php
 */

class WAI_Importer {
    
    private $config = array(
        'skip_images' => false,
        'test_mode' => false,
        'batch_size' => 10,
        'preserve_authors' => true,
        'skip_lines' => 0
    );
    
    private $category_cache = array();
    private $tag_cache = array();
    private $author_cache = array();
    private $term_taxonomy_cache = array();
    
    public function set_config($config) {
        $this->config = array_merge($this->config, $config);
    }
    
    public function import_batch($json_path, $offset = 0, $batch_size = 10) {
        $result = array(
            'success' => false,
            'message' => '',
            'processed' => array(),
            'total' => 0,
            'has_more' => false
        );
        
        try {
            // Load and parse JSON file
            if (!file_exists($json_path)) {
                throw new Exception("JSON file not found: $json_path");
            }
            
            $json_content = file_get_contents($json_path);
            $articles = json_decode($json_content, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("JSON decode error: " . json_last_error_msg());
            }
            
            // Apply skip_lines to remove articles before the starting point
            $skip_lines = $this->config['skip_lines'];
            if ($skip_lines > 0) {
                $articles = array_slice($articles, $skip_lines);
            }
            
            // Get total count after skipping lines
            $result['total'] = count($articles);
            
            // Apply test mode limit
            if ($this->config['test_mode']) {
                $articles = array_slice($articles, 0, 5);
                $result['total'] = min(5, $result['total']);
            }
            
            // Adjust offset for the skipped lines
            $adjusted_offset = $offset - $skip_lines;
            if ($adjusted_offset < 0) {
                $adjusted_offset = 0;
            }
            
            // Get batch of articles
            $batch = array_slice($articles, $adjusted_offset, $batch_size);
            $result['has_more'] = ($adjusted_offset + $batch_size) < count($articles);
            
            // Process each article
            foreach ($batch as $article) {
                $article_result = $this->import_article($article);
                $result['processed'][] = $article_result;
            }
            
            $result['success'] = true;
            $result['message'] = sprintf('Processed %d articles', count($batch));
            
        } catch (Exception $e) {
            $result['message'] = $e->getMessage();
        }
        
        return $result;
    }
    
    private function import_article($article) {
        $result = array(
            'id' => $article['id'] ?? 0,
            'title' => $article['title'] ?? 'Unknown',
            'status' => 'failed',
            'message' => ''
        );
        
        try {
            $json_post_id = intval($article['id']);
            
            // First check if a post exists with this exact ID
            if ($json_post_id && get_post($json_post_id)) {
                $result['status'] = 'updated';
                $result['message'] = 'Post updated';
                $this->update_article($article);
            } else {
                // Check if a post exists with the same title but different ID
                $existing_post = $this->find_post_by_title($article['title']);
                
                if ($existing_post && $existing_post->ID != $json_post_id) {
                    // Post exists with different ID - need to change its ID
                    $result['message'] = sprintf('Post found with ID %d, changing to ID %d', $existing_post->ID, $json_post_id);
                    $this->change_post_id($existing_post->ID, $json_post_id);
                    $this->update_article($article);
                    $result['status'] = 'id_changed';
                } else {
                    // No existing post found - create new one
                    $result['status'] = 'created';
                    $result['message'] = 'Post created';
                    $this->create_article($article);
                }
            }
            
            $result['status'] = 'success';
            
        } catch (Exception $e) {
            $result['message'] = $e->getMessage();
        }
        
        return $result;
    }
    
    private function create_article($article) {
        // Prepare post data
        $author_id = 1;
        if (!empty($article['author_name'])) {
            $author_id = $this->get_or_create_user($article['author_name']);
        }
        
        $post_date = current_time('mysql');
        if (!empty($article['date_of_publish'])) {
            $post_date = date('Y-m-d H:i:s', strtotime($article['date_of_publish']));
        }
        
        $post_name = $article['slug'] ?? '';
        if (empty($post_name)) {
            $post_name = sanitize_title($article['title']);
        }
        
        // Create post array with specified ID
        $post_data = array(
            'post_author' => $author_id,
            'post_date' => $post_date,
            'post_content' => $this->clean_content($article['article_content'] ?? ''),
            'post_title' => $article['title'],
            'post_excerpt' => $article['meta_description'] ?? '',
            'post_status' => 'publish',
            'post_name' => $post_name,
            'post_type' => 'post',
            'comment_status' => 'open',
            'ping_status' => 'closed',
            'import_id' => intval($article['id']) // Use import_id instead of ID
        );
        
        // Insert post without specifying ID first
        $temp_post_id = wp_insert_post($post_data, true);
        
        if (is_wp_error($temp_post_id)) {
            throw new Exception("Failed to create post: " . $temp_post_id->get_error_message());
        }
        
        // Now change the ID to the desired one if specified
        $desired_id = intval($article['id']);
        if ($desired_id && $desired_id !== $temp_post_id) {
            try {
                $this->change_post_id($temp_post_id, $desired_id);
                $post_id = $desired_id;
            } catch (Exception $e) {
                // If ID change fails, continue with the temporary ID
                error_log("Warning: Could not change post ID from $temp_post_id to $desired_id: " . $e->getMessage());
                $post_id = $temp_post_id;
            }
        } else {
            $post_id = $temp_post_id;
        }
        
        // Add categories and tags
        $this->add_post_terms($post_id, $article);
        
        // Add meta data
        $this->add_post_meta($post_id, $article);
        
        // Handle featured image
        if (!$this->config['skip_images'] && !empty($article['image_link'])) {
            $this->set_featured_image($post_id, $article['image_link'], $article['image_alt'] ?? '');
        }
        
        return $post_id;
    }
    
    private function update_article($article) {
        $post_id = intval($article['id']);
        
        // Get existing post to preserve certain fields
        $existing_post = get_post($post_id);
        if (!$existing_post) {
            throw new Exception("Post with ID $post_id not found for update");
        }
        
        // Prepare update data
        $post_data = array(
            'ID' => $post_id,
            'post_content' => $this->clean_content($article['article_content'] ?? ''),
            'post_title' => $article['title'],
            'post_excerpt' => $article['meta_description'] ?? '',
            'post_modified' => current_time('mysql')
        );
        
        // Handle author based on configuration
        if (!$this->config['preserve_authors'] && !empty($article['author_name'])) {
            // Only update author if not preserving and author is specified
            $author_id = $this->get_or_create_user($article['author_name']);
            $post_data['post_author'] = $author_id;
        }
        // If preserve_authors is true or no author in JSON, keep the existing author
        
        $updated = wp_update_post($post_data, true);
        
        if (is_wp_error($updated)) {
            throw new Exception($updated->get_error_message());
        }
        
        // Update categories and tags
        $this->add_post_terms($post_id, $article, true);
        
        // Update meta data
        $this->add_post_meta($post_id, $article, true);
        
        return $post_id;
    }
    
    private function get_or_create_user($author_name) {
        if (isset($this->author_cache[$author_name])) {
            return $this->author_cache[$author_name];
        }
        
        // Check if user exists
        $user = get_user_by('display_name', $author_name);
        if ($user) {
            $this->author_cache[$author_name] = $user->ID;
            return $user->ID;
        }
        
        // Create new user
        $username = sanitize_user(strtolower(str_replace(' ', '_', $author_name)));
        $user_data = array(
            'user_login' => $username,
            'user_pass' => wp_generate_password(),
            'user_nicename' => sanitize_title($author_name),
            'user_email' => $username . '@example.com',
            'display_name' => $author_name,
            'role' => 'author'
        );
        
        $user_id = wp_insert_user($user_data);
        
        if (is_wp_error($user_id)) {
            return 1; // Default to admin if user creation fails
        }
        
        $this->author_cache[$author_name] = $user_id;
        return $user_id;
    }
    
    private function add_post_terms($post_id, $article, $update = false) {
        if ($update) {
            // Clear existing terms
            wp_set_post_categories($post_id, array());
            wp_set_post_tags($post_id, array());
        }
        
        // Add category
        if (!empty($article['section'])) {
            $category = get_category_by_slug(sanitize_title($article['section']));
            if (!$category) {
                $category_id = wp_create_category($article['section']);
            } else {
                $category_id = $category->term_id;
            }
            wp_set_post_categories($post_id, array($category_id));
        }
        
        // Add tags
        if (!empty($article['tags']) && is_array($article['tags'])) {
            wp_set_post_tags($post_id, $article['tags']);
        }
    }
    
    private function add_post_meta($post_id, $article, $update = false) {
        // Yoast SEO meta fields
        $meta_fields = array();
        
        // SEO Title
        if (!empty($article['meta_title'])) {
            $meta_fields['_yoast_wpseo_title'] = $article['meta_title'];
        }
        
        // Meta Description
        if (!empty($article['meta_description'])) {
            $meta_fields['_yoast_wpseo_metadesc'] = $article['meta_description'];
        }
        
        // Focus Keyword
        if (!empty($article['keywords'])) {
            $keywords = trim($article['keywords'], ' ,');
            if ($keywords) {
                $keyword_array = explode(',', $keywords);
                $first_keyword = trim($keyword_array[0]);
                if ($first_keyword) {
                    $meta_fields['_yoast_wpseo_focuskw'] = $first_keyword;
                }
            }
        }
        
        // Update meta fields
        foreach ($meta_fields as $meta_key => $meta_value) {
            update_post_meta($post_id, $meta_key, $meta_value);
        }
    }
    
    private function set_featured_image($post_id, $image_url, $image_alt = '') {
        // Check if image already exists
        $attachment_id = $this->get_attachment_by_url($image_url);
        
        if (!$attachment_id) {
            // Download and create attachment
            require_once(ABSPATH . 'wp-admin/includes/media.php');
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            
            // Download image
            $tmp = download_url($image_url);
            
            if (!is_wp_error($tmp)) {
                $file_array = array(
                    'name' => basename($image_url),
                    'tmp_name' => $tmp
                );
                
                // Create attachment
                $attachment_id = media_handle_sideload($file_array, $post_id, $image_alt);
                
                if (!is_wp_error($attachment_id)) {
                    // Set alt text
                    update_post_meta($attachment_id, '_wp_attachment_image_alt', $image_alt);
                }
            }
        }
        
        if ($attachment_id && !is_wp_error($attachment_id)) {
            set_post_thumbnail($post_id, $attachment_id);
        }
    }
    
    private function get_attachment_by_url($url) {
        global $wpdb;
        
        $attachment = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM $wpdb->posts WHERE post_type='attachment' AND guid='%s'",
            $url
        ));
        
        return $attachment;
    }
    
    private function find_post_by_title($title) {
        global $wpdb;
        
        $query = $wpdb->prepare(
            "SELECT * FROM {$wpdb->posts} WHERE post_title = %s AND post_type = 'post' LIMIT 1",
            $title
        );
        
        return $wpdb->get_row($query);
    }
    
    private function change_post_id($old_id, $new_id) {
        global $wpdb;
        
        // Check if the new ID is already in use
        if (get_post($new_id)) {
            throw new Exception("Cannot change to ID $new_id - it's already in use");
        }
        
        // Start transaction
        $wpdb->query('START TRANSACTION');
        
        try {
            // Update the main post ID
            $wpdb->update(
                $wpdb->posts,
                array('ID' => $new_id),
                array('ID' => $old_id),
                array('%d'),
                array('%d')
            );
            
            // Update all related tables
            $tables_to_update = array(
                'postmeta' => 'post_id',
                'comments' => 'comment_post_ID',
                'term_relationships' => 'object_id'
            );
            
            foreach ($tables_to_update as $table => $column) {
                $wpdb->update(
                    $wpdb->$table,
                    array($column => $new_id),
                    array($column => $old_id),
                    array('%d'),
                    array('%d')
                );
            }
            
            // Update parent references in posts table
            $wpdb->update(
                $wpdb->posts,
                array('post_parent' => $new_id),
                array('post_parent' => $old_id),
                array('%d'),
                array('%d')
            );
            
            // Update GUID if it contains the old ID
            $old_guid = get_post_field('guid', $old_id);
            if (strpos($old_guid, "p=$old_id") !== false) {
                $new_guid = str_replace("p=$old_id", "p=$new_id", $old_guid);
                $wpdb->update(
                    $wpdb->posts,
                    array('guid' => $new_guid),
                    array('ID' => $new_id),
                    array('%s'),
                    array('%d')
                );
            }
            
            // Commit transaction
            $wpdb->query('COMMIT');
            
            // Clear caches
            clean_post_cache($old_id);
            clean_post_cache($new_id);
            wp_cache_flush();
            
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            throw new Exception("Failed to change post ID: " . $e->getMessage());
        }
    }
    
    private function clean_content($content) {
        if (empty($content)) {
            return '';
        }
        
        // Remove excess tabs and newlines
        $content = preg_replace('/\t+/', ' ', $content);
        $content = preg_replace('/\n{3,}/', "\n\n", $content);
        
        return trim($content);
    }
}