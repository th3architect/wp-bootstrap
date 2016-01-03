<?php

namespace Wpbootstrap;

class ExportMenus extends ExportBase
{
    public function __construct()
    {
        parent::__construct();
        $container = Container::getInstance();
        $this->exportPosts = $container->getExportPosts();
        $this->exportTaxonomies = $container->getExportTaxonomies();
    }

    public function export()
    {
        if (!isset($this->appSettings->content->menus)) {
            return;
        }

        foreach ($this->appSettings->content->menus as $menu => $locations) {
            $menuItems = array();
            wp_set_current_user(1);
            $loggedInmenuItems = wp_get_nav_menu_items($menu);
            if (is_array($loggedInmenuItems)) {
                $menuItems = array_merge($menuItems, $loggedInmenuItems);
            }
            wp_set_current_user(0);
            $notloggedInmenuItems = wp_get_nav_menu_items($menu);
            if (is_array($notloggedInmenuItems)) {
                $menuItems = @array_merge($menuItems, $notloggedInmenuItems);
            }
            $menuItems = $this->helpers->uniqueObjectArray($menuItems, 'ID');

            $dir = BASEPATH.'/bootstrap/menus/'.$menu;
            array_map('unlink', glob("$dir/*"));
            @mkdir($dir, 0777, true);

            foreach ($menuItems as $menuItem) {
                $obj = get_post($menuItem->ID);
                $obj->post_meta = get_post_meta($obj->ID);

                switch ($obj->post_meta['_menu_item_type'][0]) {
                    case 'post_type':
                        $postType = $obj->post_meta['_menu_item_object'][0];
                        $postId = $obj->post_meta['_menu_item_object_id'][0];
                        $objPost = get_post($postId);
                        $this->exportPosts->addPost($postType, $objPost->post_name);
                        break;
                    case 'taxonomy':
                        $id = $obj->post_meta['_menu_item_object_id'][0];
                        $taxonomy = $obj->post_meta['_menu_item_object'][0];
                        $objTerm = get_term($id, $taxonomy);
                        if (!is_wp_error($objTerm)) {
                            $this->exportTaxonomies->addTerm($taxonomy, $objTerm->slug);
                        }
                        break;
                }
                $this->helpers->fieldSearchReplace($obj, $this->baseUrl, Bootstrap::NETURALURL);

                $file = $dir.'/'.$menuItem->post_name;
                file_put_contents($file, serialize($obj));
            }
        }
    }
}