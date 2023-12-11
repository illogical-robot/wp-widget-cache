<?php

class WCache_Admin
{
    private $WidgetCache = false;

    public function __construct($WidgetCache)
    {
        $this->WidgetCache = $WidgetCache;
        add_action('admin_menu', array(
            &$this,
            'wp_add_options_page'
        ));

        if ($this->WidgetCache->wgcEnabled) {
            add_action(
                'sidebar_admin_page',
                array(
                    &$this,
                    'widget_cache_options_filter'
                )
            );
        }

        add_action(
            'sidebar_admin_setup',
            array(
                &$this,
                'widget_cache_expand_control'
            )
        );

        if (isset($_GET["wgdel"])) {
            add_action('admin_notices', array(
                &$this,
                'widget_wgdel_notice'
            ));
        }
    }


    private function wgc_update_option($key, $value)
    {
        update_option($key, $value);
    }

    private function wp_load_default_settings()
    {
        return $this->wgc_update_option('widget_cache_settings', []);
    }


    public function widget_cache_expand_control()
    {
        global $wp_registered_widgets, $wp_registered_widget_controls;

        $wc_options = $this->WidgetCache->wgcOptions;
        $wc_trigers = $this->WidgetCache->wgcTriggers;
        $wc_varyparams = $this->WidgetCache->wgcVaryParams;

        if ($this->WidgetCache->wgcEnabled) {
            foreach ($wp_registered_widgets as $id => $widget) {
                if (!$wp_registered_widget_controls[$id]) {
                    wp_register_widget_control(
                        $id,
                        $widget['name'],
                        array(
                            &$this,
                            'widget_cache_empty_control'
                        )
                    );
                }

                if (
                    !array_key_exists(0, $wp_registered_widget_controls[$id]['params']) ||
                    is_array($wp_registered_widget_controls[$id]['params'][0])
                ) {
                    $wp_registered_widget_controls[$id]['params'][0]['id_for_wc'] = $id;
                } else {
                    array_push($wp_registered_widget_controls[$id]['params'], $id);
                    $wp_registered_widget_controls[$id]['height'] += 40;
                }

                $wp_registered_widget_controls[$id]['callback_wc_redirect'] = $wp_registered_widget_controls[$id]['callback'];
                $wp_registered_widget_controls[$id]['callback'] = array(
                    &$this,
                    'widget_cache_extra_control'
                );
            }

            if ('post' == strtolower($_SERVER['REQUEST_METHOD'])) {
                if (isset($_POST["widget_cache-clear"])) {
                    $this->WidgetCache->wcache->clear();
                    wp_redirect(add_query_arg('message', 'wgc#wgcoptions'));
                    exit();
                }

                foreach ((array)$_POST['widget-id'] as $widget_number => $widget_id) {
                    if (isset($_POST[$widget_id . '-wgc-expire'])) {
                        $wc_options[$widget_id] = [
                            'expire_ts' => intval($_POST[$widget_id . '-wgc-expire']),
                            'sidebar_id' => esc_attr($_POST['sidebar'])
                        ];
                    }
                    if ($this->WidgetCache->wgcAutoExpireEnabled) {
                        if (isset($_POST[$widget_id . '-wgc-trigger'])) {
                            $wc_trigers[$widget_id] = ($_POST[$widget_id . '-wgc-trigger']);
                        } else {
                            unset($wc_trigers[$widget_id]);
                        }
                    }
                    if ($this->WidgetCache->wgcVaryParamsEnabled) {
                        if (isset($_POST[$widget_id . '-widget_cache-varyparam'])) {
                            $wc_varyparams[$widget_id] = ($_POST[$widget_id . '-widget_cache-varyparam']);
                        } else {
                            unset($wc_varyparams[$widget_id]);
                        }
                    }
                }

                $regd_plus_new = array_merge(
                    array_keys($wp_registered_widgets),
                    array_values((array)$_POST['widget-id'])
                );
                foreach (array_keys($wc_options) as $key) {
                    if (!in_array($key, $regd_plus_new)) {
                        unset($wc_options[$key]);
                        if ($this->WidgetCache->wgcAutoExpireEnabled) {
                            unset($wc_trigers[$key]);
                        }
                        if ($this->WidgetCache->wgcVaryParamsEnabled) {
                            unset($wc_varyparams[$key]);
                        }
                    }
                }

                $this->wgc_update_option('widget_cache', $wc_options);

                if ($this->WidgetCache->wgcAutoExpireEnabled) {
                    $this->wgc_update_option('widget_cache_action_trigger', $wc_trigers);
                }

                if ($this->WidgetCache->wgcVaryParamsEnabled) {
                    $this->wgc_update_option('widget_cache_vary_param', $wc_varyparams);
                }

                $this->WidgetCache->__wgc_load_opts();
            }
        }
    }

    public function widget_cache_empty_control()
    {
    }

    public function widget_cache_extra_control()
    {
        global $wp_registered_widget_controls;
        $params = func_get_args();

        $id = (is_array($params[0])) ? $params[0]['id_for_wc'] : array_pop($params);

        $id_disp = $id;

        if ($this->WidgetCache->wgcEnabled) // WP Widget Cache enabled
        {
            $value = $this->WidgetCache->get_expire_ts($id);

            if (is_array($params[0]) && isset($params[0]['number'])) {
                $number = $params[0]['number'];

                if ($number == -1) {
                    $number = "%i%";
                    $value = "";
                }

                $id_disp = $wp_registered_widget_controls[$id]['id_base'] . '-' . $number;
            }

            $value = intval($value);
            if ($value <= 0) {
                $value = "";
            }

            $this->output_widget_options_panel($id_disp, $value);
        } else {
            echo '<label style="color: gray; font-style: italic; margin-bottom: 10px; line-height: 150%;">WP Widget Cache disabled</label>';
        }

        $callback = $wp_registered_widget_controls[$id]['callback_wc_redirect'];
        if (is_callable($callback)) {
            call_user_func_array($callback, $params);
        }
    }

    public function widget_wgdel_notice()
    {
        $id = $_GET["wgdel"];
        $this->WidgetCache->wcache->remove_group($id);
        echo '<div id="widget-cache-notice" class="updated fade"><p>Delete widget cache: ' . esc_html($id) . '</p></div>';
    }


    public function wp_add_options_page()
    {
        if (function_exists('add_options_page')) {
            add_options_page(
                $this->WidgetCache->plugin_name,
                $this->WidgetCache->plugin_name,
                'manage_options',
                'wp-widget-cache-settings',
                array(
                    &$this,
                    'wp_options_subpanel'
                )
            );
        }
    }

    /**
     * Clear all cache in admin->widgets edit page
     */
    public function widget_cache_options_filter()
    {
?>
        <div class="wrap">
            <form method="POST">
                <a name="wgcoptions"></a>
                <h2><?php echo $this->WidgetCache->plugin_name; ?> Options</h2>
                <p style="line-height: 30px;">
                    <span class="submit"> <input type="submit" name="widget_cache-clear" class="button" id="widget_cache-options-submit" value="Clear all widgets cache(<?php echo $this->WidgetCache->wcache->cachecount(); ?>)" />
                    </span>
                </p>
            </form>
        </div>
    <?php
    }



    private function output_widget_options_panel($id_disp, $expire_ts)
    {
    ?>
        <fieldset style="border: 1px solid #2583AD; padding: 3px 0 3px 5px; margin-bottom: 10px; line-height: 150%;">
            <legend><?php echo $this->WidgetCache->plugin_name; ?></legend>
            <div>
                Expire in <input type='text' name='<?php echo $id_disp; ?>-wgc-expire' id='<?php echo $id_disp; ?>-wgc-expire' value='<?php echo $expire_ts; ?>' size=6 style="padding: 0" />
                second(s) <br />(Left empty means no cache) <br /> <a href='widgets.php?wgdel=<?php echo urlencode($id_disp) ?>'>Delete
                    cache of this widget</a>
            </div>
            <?php if ($this->WidgetCache->wgcAutoExpireEnabled) : ?>
                <div style="margin-top: 5px; border-top: 1px solid #ccc; clear: both;">
                    <div>Auto expire when these things changed:</div>
                    <?php foreach ($this->WidgetCache->triggerActions as $tkey => $actArr) : ?>
                        <?php
                        $checked = "";
                        if (
                            $this->WidgetCache->array_element($this->WidgetCache->wgcTriggers, $id_disp) &&
                            in_array($tkey, $this->WidgetCache->wgcTriggers[$id_disp])
                        ) {
                            $checked = "checked=\"checked\"";
                        }
                        ?>
                        <div style="float: left; display: inline; margin: 2px 1px 1px 0;" nowrap="nowrap">
                            <input type="checkbox" <?php echo $checked; ?> id="<?php echo $id_disp; ?>-wgc-trigger-<?php echo $tkey; ?>" name="<?php echo $id_disp; ?>-wgc-trigger[]" value="<?php echo $tkey; ?>" /> <label for="<?php echo $id_disp; ?>-wgc-trigger-<?php echo $tkey; ?>"><?php echo ucfirst($tkey); ?></label> &nbsp;
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if ($this->WidgetCache->wgcVaryParamsEnabled) : ?>
                <div style="margin-top: 5px; border-top: 1px solid #ccc; clear: both;">
                    <div>Vary by:</div>
                    <?php foreach ($this->WidgetCache->varyParams as $vparam => $vfunc) : ?>
                        <?php
                        $checked = "";
                        if (
                            $this->WidgetCache->array_element($this->WidgetCache->wgcVaryParams, $id_disp) &&
                            in_array($vparam, $this->WidgetCache->wgcVaryParams[$id_disp])
                        ) {
                            $checked = "checked=\"checked\"";
                        }
                        ?>
                        <div style="float: left; display: inline; margin: 2px 1px 1px 0;" nowrap="nowrap">
                            <input type="checkbox" <?php echo $checked; ?> id="<?php echo $id_disp; ?>-widget_cache-varyparam-<?php echo $vparam; ?>" name="<?php echo $id_disp; ?>-widget_cache-varyparam[]" value="<?php echo $vparam; ?>" /> <label for="<?php echo $id_disp; ?>-widget_cache-varyparam-<?php echo $vparam; ?>"><?php echo ucwords($vparam); ?></label> &nbsp;
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </fieldset>
    <?php
    }

    public function wp_options_subpanel()
    {
        if (isset($_POST["widget_cache-clear"]) || isset($_GET['clear']) && $_GET['clear'] == "1") {
            $this->WidgetCache->wcache->clear();
            echo '<div id="message" class="updated fade"><p>Cache Cleared</p></div>';
        }

        if (isset($_POST["wp_wgc_submit"])) {
            $wp_settings = array(
                "wgc_disabled" => $this->WidgetCache->array_element($_POST, 'wgc_enabled') ? false : "1",
                "wgc_ae_ops_disabled" => $this->WidgetCache->array_element($_POST, 'wgc_ae_ops_enabled') ? false : "1",
                "wgc_vary_by_params_enabled" => $this->WidgetCache->array_element($_POST, 'wgc_vary_by_params_enabled') ? '1' : false
            );
            $this->wgc_update_option("widget_cache_settings", $wp_settings);
            echo '<div id="message" class="updated fade"><p>Options Updated</p></div>';
        } else {
            if (isset($_POST["wp_wgc_load_default"])) {
                $wp_settings = $this->wp_load_default_settings();
                echo '<div id="message" class="updated fade"><p>Options Reset</p></div>';
            } else {
                $wp_settings = $this->WidgetCache->wgcSettings;
            }
        }
    ?>
        <div class="wrap">
            <form action="<?php echo $_SERVER['PHP_SELF']; ?>?page=wp-widget-cache-settings" method="post">
                <h2><?php echo $this->WidgetCache->plugin_name; ?> Options</h2>
                <table class="form-table">
                    <tr valign="top">
                        <td><input name="wgc_enabled" type="checkbox" id="wgc_enabled" value="1" <?php checked('1', !($this->WidgetCache->array_element($wp_settings, "wgc_disabled") == "1")); ?> />
                            <label for=wgc_enabled><strong>Enable Widget Cache</strong> </label>
                        </td>
                    </tr>
                    <tr valign="top">
                        <td><input name="wgc_ae_ops_enabled" type="checkbox" id="wgc_ae_ops_enabled" value="1" <?php checked(
                                                                                                                    '1',
                                                                                                                    !($this->WidgetCache->array_element($wp_settings, "wgc_ae_ops_disabled") == "1")
                                                                                                                ); ?> />
                            <label for=wgc_ae_ops_enabled><strong>Enable auto expire options
                                    (e.g. When categories, comments, posts, tags changed)</strong> </label>
                        </td>
                    </tr>
                    <tr valign="top">
                        <td><input name="wgc_vary_by_params_enabled" type="checkbox" id="wgc_vary_by_params_enabled" value="1" <?php checked(
                                                                                                                                    '1',
                                                                                                                                    ($this->WidgetCache->array_element($wp_settings, "wgc_vary_by_params_enabled") == "1")
                                                                                                                                ); ?> />
                            <label for=wgc_vary_by_params_enabled><strong>Enable vary by params
                                    options (e.g. Vary by user levels, user agents)</strong> </label>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <input type="submit" name="wp_wgc_load_default" value="Reset to Default Options &raquo;" class="button" onclick="return confirm('Are you sure to reset options?')" /> <input type="submit" name="wp_wgc_submit" value="Save Options &raquo;" class="button" style="margin-left: 15px;" />
                </p>
                <p>
                    <input type="submit" name="widget_cache-clear" class="button" value="Clear all widgets cache(<?php echo $this->WidgetCache->wcache->cachecount(); ?>)" />
                </p>
            </form>
        </div>
<?php
    }
}
