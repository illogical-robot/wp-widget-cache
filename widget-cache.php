<?php
/*
Plugin Name: WP Widget Cache
Plugin URI: https://github.com/illogical-robot/wp-widget-cache/
Description: Cache the output of your blog widgets. Usually it will significantly reduce the sql queries to your database and speed up your site.
Author: Apmirror.com, Andrew Zhang
Version: 0.26.12.1
Author URI: https://github.com/rooseve/wp-widget-cache
*/

class WidgetCache
{

    public $plugin_name = 'WP Widget Cache';

    public $plugin_version = '0.26.12.1';

    public $wcache;
    public $wadmin;

    public $cachedir;

    public $wgcOptions;

    public $wgcTriggers;

    public $wgcSettings;

    public $wgcVaryParams;

    public $wgcEnabled = true;

    public $wgcAutoExpireEnabled = true;

    public $wgcVaryParamsEnabled = false;

    public $triggerActions = [];

    public $varyParams = [];

    public function __construct()
    {
        require_once(__DIR__ . "/inc/wcache.class.php");
        $this->cachedir = WP_CONTENT_DIR . '/widget-cache';

        $url_info = parse_url(site_url());

        $shost = $this->array_element($url_info, 'host');
        $spath = $this->array_element($url_info, 'path');

        $shost = apply_filters('wgc_cache_host', $shost);

        //maybe got many blogs under the same source
        $this->cachedir .= '/' . $shost . ($spath ? '_' . md5($spath) : '');

        $this->wcache = new WCache($this->cachedir);

        if (!(is_dir($this->cachedir) && is_writable($this->cachedir))) {
            add_action('admin_notices', array(
                &$this,
                'widget_cache_warning'
            ));
            return;
        }

        $this->__wgc_load_opts();

        if ($this->wgcEnabled) {
            if ($this->wgcAutoExpireEnabled) {
                $this->triggerActions = array(
                    "category" => array(
                        "add_category",
                        "create_category",
                        "edit_category",
                        "delete_category"
                    ),
                    "comment" => array(
                        "comment_post",
                        "edit_comment",
                        "delete_comment",
                        "pingback_post",
                        "trackback_post",
                        "wp_set_comment_status"
                    ),
                    "link" => array(
                        "add_link",
                        "edit_link",
                        "delete_link"
                    ),
                    "post publish/unpublish" => array(
                        "publish_post",
                        "publish_to_draft",
                        "publish_to_pending",
                        "publish_to_trash",
                        "publish_to_future"
                    ),
                    "post update" => array(
                        "publish_to_publish"
                    ),
                    "tag" => array(
                        "create_term",
                        "edit_term",
                        "delete_term"
                    )
                );
                $this->triggerActions = apply_filters('wgc_trigger_actions', $this->triggerActions);
            }
            if ($this->wgcVaryParamsEnabled) {
                $this->varyParams = array(
                    "userLevel" => array(
                        'WidgetCache',
                        'get_user_level'
                    ),
                    "userLoggedIn" => array(
                        'WidgetCache',
                        'get_is_user_logged_in'
                    ),
                    "userAgent" => array(
                        'WidgetCache',
                        'get_user_agent'
                    ),
                    "currentCategory" => array(
                        'WidgetCache',
                        'get_current_category'
                    ),
                    "amp" => array(
                        'WidgetCache',
                        'get_amp_vary_param'
                    )
                );
                $this->varyParams = apply_filters('wgc_vary_params', $this->varyParams);
            }
            add_action('widget_display_callback', array(
                &$this,
                'widget_display_callback'
            ), PHP_INT_MAX, 3);
        }
        $this->admin_actions();
    }


    public function get_expire_ts($id)
    {
        return (isset($this->wgcOptions[$id]) && isset($this->wgcOptions[$id]['expire_ts'])) ? intval($this->wgcOptions[$id]['expire_ts']) : -1;
    }
    public function array_element($array, $ele)
    {
        return $array[$ele] ?? false;
    }

    private function admin_actions()
    {
        if (is_admin()) {
            require_once(__DIR__ . "/inc/admin.class.php");
            $this->wadmin = new WCache_Admin($this);
        }
    }

    private function wgc_get_option($key, $default = array())
    {
        $ops = get_option($key);

        if (!$ops) {
            $ops = $default;
        } else {
            foreach ($default as $k => $v) {
                if (!isset($ops[$k])) {
                    $ops[$k] = $v;
                }
            }
        }

        return $ops;
    }


    public function __wgc_load_opts()
    {
        $this->wgcSettings = $this->wgc_get_option(
            "widget_cache_settings",
            array(
                'wgc_disabled' => 0,
                'wgc_ae_ops_disabled' => 0,
                'wgc_vary_by_params_enabled' => 0
            )
        );

        $this->wgcOptions = $this->wgc_get_option('widget_cache');
        $this->wgcTriggers = $this->wgc_get_option('widget_cache_action_trigger');
        $this->wgcVaryParams = $this->wgc_get_option('widget_cache_vary_param');

        $this->wgcOptions = apply_filters('wc_options', $this->wgc_get_option('widget_cache'));
        
        // convert the old settings to support also the sidebar id
        // @todo: delete it, once all options are updated?
        if (is_array($this->wgcOptions)) {
            $this->wgcOptions = array_map(function($item){
                if (is_array($item)) {
                    return $item;
                }
                return [
                    'expire_ts' => $item,
                    'sidebar_id' => 'wp_inactive_widgets'
                ];
            }, $this->wgcOptions);
        }

        $this->wgcTriggers = apply_filters('wc_triggers', $this->wgc_get_option('widget_cache_action_trigger'));
        $this->wgcVaryParams = apply_filters('wc_varyparams', $this->wgc_get_option('widget_cache_vary_param'));

        $this->wgcEnabled = $this->wgcSettings["wgc_disabled"] != "1";

        $this->wgcAutoExpireEnabled = $this->wgcSettings["wgc_ae_ops_disabled"] != "1";

        $this->wgcVaryParamsEnabled = $this->wgcSettings["wgc_vary_by_params_enabled"] == "1";
    }


    public function widget_cache_warning()
    {
        $pdir = WP_CONTENT_DIR . '/';

        if (is_dir($this->cachedir)) {
            $wmsg = "'$this->cachedir' is not writable, please check '$this->cachedir' permissions, and give your web server the permission to create directory and file.";
        } else {
            $wmsg = "'$this->cachedir' cann't be created, please check '$pdir' permissions, and give your web server the permission to create directory and file.";
        }

        echo "<div id='widget-cache-warning' class='updated fade'><p><strong>WP Widget Cache not work!</strong><br/>$wmsg</p></div>";
    }

    public function widget_display_callback($instance, $widget_object, $args)
    {
        if (is_user_logged_in() && current_user_can('manage_options')) {
            echo '<style>.render-time{padding-left: 10px;padding-right: 10px;display: block;text-align: right;font-size: 11px;color: #666}</style>';
        }
        $id = $widget_object->id;
        $expire_ts = $this->get_expire_ts($id);

        if (!($expire_ts > 0)) {
            return $instance;
        }
        if (false === $instance || !is_subclass_of($widget_object, 'WP_Widget')) {
            return $instance;
        }

        $this->widget_cache_save($instance, $widget_object, $args);
        return false;
    }

    private static function get_user_level($all = false)
    {
        if ($all) {
            return [10, 7, 2, 1, 0];
        }
        $current_user = wp_get_current_user();
        $roles = $current_user->roles ?? '';
        if (in_array('administrator', $roles)) {
            return 10;
        }
        if (in_array('editor', $roles)) {
            return 7;
        }
        if (in_array('author', $roles)) {
            return 2;
        }
        if (in_array('contributor', $roles)) {
            return 1;
        }
        if (in_array('subscriber', $roles)) {
            return 0;
        }

        return '-1';
    }

    public static function get_is_user_logged_in($all = false)
    {
        if ($all) {
            return ['logged', 'not_logged'];
        }
        return (is_user_logged_in() ? 'logged' : 'not_logged');
    }

    public static function get_user_agent($all = false)
    {
        if ($all) {
            return false;
        }
        return $_SERVER['HTTP_USER_AGENT'];
    }

    public static function get_amp_vary_param($all = false)
    {
        if ($all) {
            return ['amp', 'non-amp'];
        }
        if (function_exists('is_amp_endpoint') && is_amp_endpoint()) {
            return 'amp';
        } else {
            return 'non-amp';
        }
    }
    public static function get_current_category($all = false)
    {
        if ($all) {
            return false;
        }
        if (is_single()) {
            global $post;
            $categories = get_the_category($post->ID);
            $cidArr = array();
            foreach ($categories as $category) {
                $cidArr[] = $category->cat_ID;
            }
            return join(",", $cidArr);
        } elseif (is_category()) {
            return get_query_var('cat');
        }

        return false;
    }

    public function get_widget_cache_key($id)
    {
        $wckey = "wgcache_" . $id;

        if ($this->wgcVaryParamsEnabled && isset($this->wgcVaryParams[$id])) {
            foreach ($this->wgcVaryParams[$id] as $vparam) {
                if ($this->varyParams[$vparam]) {
                    if (is_callable($this->varyParams[$vparam])) {
                        $temv = call_user_func($this->varyParams[$vparam]);
                        if ($temv) {
                            $wckey .= "_" . $temv;
                        }
                    }
                }
            }
        }

        return $wckey;
    }

    /**
     * Get all cache keys with all vary params for given id.
     *
     * @param   string  $id  
     *
     * @return  array   Array of cache keys with vary params.
     */
    public function get_all_widget_cache_keys($id)
    {
        $wckey = "wgcache_" . $id;
        $wckeys = [];

        if ($this->wgcVaryParamsEnabled && isset($this->wgcVaryParams[$id])) {
            foreach ($this->wgcVaryParams[$id] as $vparam) {
                if ($this->varyParams[$vparam]) {
                    if (is_callable($this->varyParams[$vparam])) {
                        $temvs = call_user_func($this->varyParams[$vparam], true);
                        if (is_array($temvs)) {
                            foreach ($temvs as $temv) {
                                $wckeys[] = $wckey . "_" . $temv;
                            }
                        }
                    }
                }
            }
        }
        if (empty($wckeys)) {
            $wckeys = [$wckey];
        }

        return $wckeys;
    }

    public function widget_cache_save($instance, $widget_object, $args, $output = true)
    {
        $id = $widget_object->id;
        $update = apply_filters('wgc_force_update', false);

        $wc_options = $this->wgcOptions;
        $expire_ts = $this->get_expire_ts($id);

        if ($output) {
            echo "<!--$this->plugin_name $this->plugin_version Begin -->\n";

            echo "<!--Cache $id for $expire_ts second(s)-->\n";
        } else {
            $this->wcache->disable_output = true;
        }

        if (is_user_logged_in()) {
            $time_start = microtime(true);
        }
        while ($this->wcache->save($this->get_widget_cache_key($id), $expire_ts, null, $id, $update)) {
            $widget_object->widget($args, $instance);
        }
        if ($output) {
            if (is_user_logged_in()) {
                global $widget_rendering_time;
                $widget_rendering_time = $widget_rendering_time ?? [];
                $time_stop =  microtime(true);
                $time_took =  number_format(($time_stop - $time_start), 5);

                $widget_rendering_time['widget-' . $id]['start'] = $time_start;
                $widget_rendering_time['widget-' . $id]['stop'] = $time_stop;
                $widget_rendering_time['widget-' . $id]['took'] = $time_took;
                if (current_user_can('manage_options')) {
?>
                    <script>
                        window.widget_rendering_time = window.widget_rendering_time || [];
                        var widget_rendering_time_current_widget = {
                            id: "<?php echo $id; ?>",
                            time: "<?php echo $time_took; ?>"
                        };
                        widget_rendering_time.push(widget_rendering_time_current_widget);
                        var widget_rendering_time_container = document.createElement("div");
                        widget_rendering_time_container.className = "render-time";
                        widget_rendering_time_container.textContent = "Rendering time: " + widget_rendering_time_current_widget.time + "s";
                        var widget_container = document.getElementById(widget_rendering_time_current_widget.id) || (document.currentScript && document.currentScript.previousElementSibling) || false;
                        if (widget_container) {
                            widget_container.appendChild(widget_rendering_time_container);
                        }
                    </script>
<?php
                }
            }

            echo "<!--$this->plugin_name End -->\n";
        }

        $this->wcache->disable_output = false;
    }
}

function widget_cache_hook_trigger()
{
    $widget_cache = get_WidgetCache_instance();
    if ($widget_cache->wgcAutoExpireEnabled && isset($widget_cache->wgcTriggers) && $widget_cache->wgcTriggers) {
        foreach ($widget_cache->wgcTriggers as $wgid => $wgacts) {
            foreach ($wgacts as $wact) {
                if (isset($widget_cache->triggerActions[$wact])) {
                    foreach ($widget_cache->triggerActions[$wact] as $wpaction) {
                        add_action(
                            $wpaction,
                            function () use ($wgid) {
                                $widget_cache = get_WidgetCache_instance();
                                if (!apply_filters('wgc_cache_remove', false, (string) addslashes($wgid), $widget_cache)) {
                                    $widget_cache->wcache->remove_group($wgid);
                                }
                            },
                            10,
                            1
                        );
                    }
                }
            }
        }
    }
}

function get_WidgetCache_instance()
{
    static $widget_cache = false;
    if (!$widget_cache) {
        $widget_cache = new WidgetCache();
    }
    return $widget_cache;
}

add_action('init', function () {
    widget_cache_hook_trigger();
});
