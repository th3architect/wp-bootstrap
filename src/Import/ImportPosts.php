<?php

namespace Wpbootstrap;

/**
 * Class ImportPosts
 * @package Wpbootstrap
 */
class ImportPosts
{
    /**
     * @var array
     */
    public $posts = array();

    /**
     * @var array
     */
    public $media = array();

    /**
     * @var Import
     */
    private $import;

    /**
     * @var \Monolog\Logger
     */
    private $log;

    /**
     * @var Helpers
     */
    private $helpers;

    /**
     * ImportPosts constructor.
     */
    public function __construct()
    {
        $container = Container::getInstance();

        $this->log = $container->getLog();
        $this->helpers = $container->getHelpers();
        $this->import = $container->getImport();

        $dir = BASEPATH.'/bootstrap/posts';
        foreach ($this->helpers->getFiles($dir) as $postType) {
            foreach ($this->helpers->getFiles($dir.'/'.$postType) as $slug) {
                $newPost = new \stdClass();
                $newPost->done = false;
                $newPost->id = 0;
                $newPost->parentId = 0;
                $newPost->slug = $slug;
                $newPost->type = $postType;
                $newPost->tries = 0;

                $file = BASEPATH."/bootstrap/posts/$postType/$slug";
                $newPost->post = unserialize(file_get_contents($file));

                $this->posts[] = $newPost;
            }
        }

        $this->helpers->fieldSearchReplace($this->posts, Bootstrap::NEUTRALURL, $this->import->baseUrl);
        $this->process();
    }

    /**
     * The main import process
     */
    private function process()
    {
        remove_all_actions('transition_post_status');

        $done = false;
        while (!$done) {
            $deferred = 0;
            foreach ($this->posts as &$post) {
                $post->tries++;
                if (!$post->done) {
                    $parentId = $this->parentId($post->post->post_parent, $this->posts);
                    if ($parentId || $post->post->post_parent == 0 || $post->tries > 9) {
                        $this->updatePost($post, $parentId);
                        $post->done = true;
                    } else {
                        $deferred++;
                    }
                }
            }
            if ($deferred == 0) {
                $done = true;
            }
        }

        $this->importMedia();
    }

    /**
     * Update individual post
     *
     * @param $post
     * @param $parentId
     */
    private function updatePost(&$post, $parentId)
    {
        global $wpdb;

        $postId = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT ID FROM $wpdb->posts WHERE post_name = %s AND post_type = %s",
                $post->slug,
                $post->type
            )
        );
        $args = array(
          'post_type' => $post->post->post_type,
          'post_mime_type' => $post->post->post_mime_type,
          'post_parent' => $parentId,
          'post_title' => $post->post->post_title,
          'post_content' => $post->post->post_content,
          'post_status' => $post->post->post_status,
          'post_name' => $post->post->post_name,
          'post_exerp' => $post->post->post_exerp,
          'ping_status' => $post->post->ping_status,
          'pinged' => $post->post->pinged,
          'comment_status' => $post->post->comment_status,
          'post_date' => $post->post->post_date,
          'post_date_gmt' => $post->post->post_date_gmt,
          'post_modified' => $post->post->post_modified,
          'post_modified_gmt' => $post->post->post_modified_gmt,
        );

        if (!$postId) {
            $postId = wp_insert_post($args);
        } else {
            $args['ID'] = $postId;
            wp_update_post($args);
        }

        $existingMeta = get_post_meta($postId);
        foreach ($post->post->post_meta as $meta => $value) {
            if (isset($existingMeta[$meta])) {
                $existingMetaItem = $existingMeta[$meta];
            }
            $i = 0;
            foreach ($value as $val) {
                if ($this->helpers->isSerialized($val)) {
                    $val = unserialize($val);
                }
                if (isset($existingMetaItem[$i])) {
                    update_post_meta($postId, $meta, $val, $existingMetaItem[$i]);
                } else {
                    update_post_meta($postId, $meta, $val);
                }
            }
        }
        $post->id = $postId;
    }

    /**
     * Import media file and meta data
     */
    private function importMedia()
    {
        // check all the media.
        foreach (glob(BASEPATH.'/bootstrap/media/*') as $dir) {
            $item = unserialize(file_get_contents("$dir/meta"));
            //$include = false;

            // does this image have an imported post as it's parent?
            $parentId = $this->parentId($item->post_parent, $this->posts);
            if ($parentId != 0) {
                $this->log->addDebug('Media is attached to post', array($item->ID, $parentId));
                //$include = true;
            }

            // does an imported post have this image as thumbnail?
            $isAThumbnail = $this->isAThumbnail($item->ID);
            if ($isAThumbnail) {
                $this->log->addDebug('Media is thumbnail to (at least) one post', array($item->id));
                //$include = true;
            }

            // is this the payload for an imported attachment?
            /*$isAttachment = $this->isAnAttachment($item->ID);
            if ($isAttachment) {
                $this->log->addDebug('Media is payload for an included attachment', array($item->id));
                //$include = true;
            }*/

            /*if (!$include) {
                continue;
            }*/

            $args = array(
                'name' => $item->post_name,
                'post_type' => $item->post_type,
            );

            $file = $item->post_meta['_wp_attached_file'][0];

            $existing = new \WP_Query($args);
            if (!$existing->have_posts()) {
                $args = array(
                    'post_title' => $item->post_title,
                    'post_name' => $item->post_name,
                    'post_type' => $item->post_type,
                    'post_parent' => $parentId,
                    'post_status' => $item->post_status,
                    'post_mime_type' => $item->post_mime_type,
                    'guid' => $this->import->uploadDir['basedir'].'/'.$file,
                );
                $id = wp_insert_post($args);
            } else {
                $id = $existing->post->ID;
            }
            update_post_meta($id, '_wp_attached_file', $file);

            // move the file
            $src = $dir.'/'.basename($file);
            $trg = $this->import->uploadDir['basedir'].'/'.$file;
            @mkdir($this->import->uploadDir['basedir'].'/'.dirname($file), 0777, true);

            // Add it to collection
            $mediaItem = new \stdClass();
            $mediaItem->meta = $item;
            $mediaItem->id = $id;
            $this->media[] = $mediaItem;

            if (file_exists($src) && file_exists($trg)) {
                if (filesize($src) == filesize($trg)) {
                    // set this image as a thumbnail and then exit
                    if ($isAThumbnail) {
                        $this->setAsThumbnail($item->ID, $id);
                    }
                    continue;
                }
            }

            if (file_exists($src)) {
                copy($src, $trg);
                // create metadata and other sizes
                $attachData = wp_generate_attachment_metadata($id, $trg);
                wp_update_attachment_metadata($id, $attachData);
            }

            // set this image as a thumbnail if needed
            if ($isAThumbnail) {
                $this->setAsThumbnail($item->ID, $id);
            }
        }
    }

    /**
     * Finds a post parent based on it's original id. If found, returns the new (after import) id
     *
     * @param int $foreignParentId
     * @param array $objects
     * @return int
     */
    private function parentId($foreignParentId, $objects)
    {
        foreach ($objects as $object) {
            if ($object->post->ID == $foreignParentId) {
                return $object->id;
            }
        }

        return 0;
    }

    /**
     * Checks if a media/attachment is serves as a thumbnail for another post
     *
     * @param int $id
     * @return bool
     */
    private function isAThumbnail($id)
    {
        foreach ($this->posts as $post) {
            if (isset($post->post->post_meta['_thumbnail_id'])) {
                $thumbId = $post->post->post_meta['_thumbnail_id'][0];
                if ($thumbId == $id) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Checks if a post id is an attachment
     *
     * @param int $id
     * @return bool
     */
    private function isAnAttachment($id)
    {
        foreach ($this->posts as $post) {
            if ($post->post->post_type == 'attachment' && $post->post->ID == $id) {
                return true;
            }
        }

        return false;
    }

    /**
     * Assigns an attachment as a thumbnail
     *
     * @param int $oldId
     * @param int $newId
     */
    private function setAsThumbnail($oldId, $newId)
    {
        foreach ($this->posts as $post) {
            if (isset($post->post->post_meta['_thumbnail_id'])) {
                $thumbId = $post->post->post_meta['_thumbnail_id'][0];
                if ($thumbId == $oldId) {
                    set_post_thumbnail($post->id, $newId);
                }
            }
        }
    }

    /**
     * Finds a post based on it's original id. If found, returns the new (after import) id
     *
     * @param $target
     * @return int
     */
    public function findTargetPostId($target)
    {
        foreach ($this->posts as $post) {
            if ($post->post->ID == $target) {
                return $post->id;
            }
        }

        foreach ($this->media as $media) {
            if ($media->meta->ID == $target) {
                return $media->id;
            }
        }

        return 0;
    }
}