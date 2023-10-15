<?php
/**
 * @package Cubicast
 * @version 0.1.0
 * Plugin Name: Cubicast
 * Plugin URI: http://www.cubicast.com/integrations/wordpress/
 * Description: Use visual feedback to help and understand your users. See through the eyes of your users and identify issues. Discover <strong>who, when, and why</strong>.
 * Author: Cubicast
 * Version: 0.1.0
 * Author URI: https://www.cubicast.com
 *
 * Text Domain: cubicast
 * Domain Path: /languages/
*/

// Make sure we don't expose any info if called directly
if ( !function_exists( 'add_action' ) ) {
  echo 'Hi there!  I\'m just a plugin, not much I can do when called directly.';
  exit;
}

define( 'CUBICAST_API_HOST', 'https://api.cubicast.com' );
define( 'CUBICAST_APP_HOST', 'https://app.cubicast.com' );

add_action('admin_menu', 'cubicast_create_menu');

function cubicast_create_menu() {
  add_menu_page(
    __( 'Cubicast', 'cubicast' ),
    __( 'Cubicast', 'cubicast' ),
    'administrator',
    __FILE__,
    'cubicast_plugin_settings_page',
    plugin_dir_url( __FILE__ ) . 'assets/images/cubicast_icon_white.svg'
  );
  add_action( 'admin_init', 'register_cubicast_plugin_settings' );
}

function register_cubicast_plugin_settings() {
  register_setting( 'cubicast-plugin-settings-group', 'cubicast_api_key' );
  add_option( 'cubicast_user_data', array() );
}

function cubicast_sync_wordpress_user() {
  $cubicast_user_data = get_option( 'cubicast_user_data' );
  $sync_name = in_array( 'name', $cubicast_user_data );

  if (is_user_logged_in()) {
    $current_user = wp_get_current_user();
  }

  $user_props = array();

  // sync Wordpress user data
  if ( isset( $current_user ) ) {
    $email = $current_user->user_email;

    if ( !empty( $email ) ) {
      $user_id = $email;
      $user_props['email'] = $user_id;
    }

    if ( isset( $current_user->display_name ) && in_array( 'nickname', $cubicast_user_data ) ) {
      $user_props['nickname'] = $current_user->display_name;
    }
  
    if ( $sync_name ) {
      $first_name = $current_user->first_name;
      $last_name = $current_user->last_name;
      $user_props['first_name'] = $first_name;
      $user_props['last_name'] = $last_name;
    }
  }

  // sync WooCommerce customer data
  if ( class_exists('WooCommerce') && !is_admin() ) {
    $customer = WC()->session->get('customer');
    if ($customer != NULL) {
      if ( !isset( $user_id ) && !empty($customer['email']) ) {
        // user not logged in
        $user_id = $customer['email'];
        $user_props['email'] = $user_id;
      }
      if ($sync_name) {
        if ( isset( $customer['first_name'] ) && !empty( $customer['first_name'] ) ) {
          $user_props['first_name'] = $customer['first_name'];
        }
        if ( isset( $customer['last_name'] ) && !empty( $customer['last_name'] ) ) {
          $user_props['last_name'] = $customer['last_name'];
        }
      }
      if (
        isset( $customer['phone'] )
        && !empty( $customer['phone'] )
        && in_array( 'phone', $cubicast_user_data )
      ) {
        $user_props['phone'] = $customer['phone'];
      }
      if (
        isset( $customer['company'] )
        && !empty( $customer['company'] )
        && in_array( 'company', $cubicast_user_data )
      ) {
        $user_props['company'] = $customer['company'];
      }
      if ( in_array( 'address', $cubicast_user_data ) ) {
        $address_lines = array();
        if ( isset( $customer['address'] ) && !empty( $customer['address'] ) ) {
          $address_lines[] = $customer['address'];
        }
        if ( isset( $customer['address_2'] ) && !empty( $customer['address_2'] ) ) {
          $address_lines[] = $customer['address_2'];
        }
        // get city, postcode and country
        $last_line = array();
        $address_keys = array(
          'city',
          'postcode',
          'country',
        );
        foreach ($address_keys as $key) {
          if ( isset( $customer[$key] ) && !empty( $customer[$key] ) ) {
            $last_line[] = $customer[$key];
          }
        }
        if ( count( $last_line ) > 0 ) {
          $address_lines[] = implode(', ', $last_line);
        }
        if ( count( $address_lines ) > 0 ) {
          $user_props['address'] = implode("\n", $address_lines);
        }
      }
    }
  }

  if ( isset($user_id) ) {
    return "cubicast.identify("
      . json_encode($user_id)
      . ", "
      . json_encode($user_props, JSON_FORCE_OBJECT)
      . ");";
  }
  return '';
}

function cubicast_settings_updated__success() {
  ?>
  <div id="setting-error-settings_updated" class="notice notice-success is-dismissible"> 
    <p>
      <strong><?php
        _e('Settings saved.', 'cubicast');
      ?></strong>
    </p>
  </div>
  <?php
}

function cubicast_incative__warning() {
  ?>
  <div id="setting-error-settings_updated" class="notice notice-warning is-dismissible"> 
    <p>
      <strong>
        <?php
          _e('Cubicast is currently inactive.', 'cubicast');
          echo(' ');
          _e('To activate Cubicast, you need a valid API key.', 'cubicast');
        ?>
      </strong>
    </p>
  </div>
  <?php
}

function cubicast_notice__error($message) {
  ?>
  <div id="setting-error-settings_updated" class="notice notice-error is-dismissible"> 
    <p>
      <strong><?php
        _e($message, 'cubicast');
      ?></strong>
    </p>
  </div>
  <?php
}

function cubicast_validate_api_key($cubicast_api_key) {
  $response = wp_remote_get( CUBICAST_API_HOST . '/api/v1/widget/' . $cubicast_api_key . '/workspace' );
  $status = wp_remote_retrieve_response_code( $response );
  $workspace_id = NULL;
  if ($status == 200) {
    $body = wp_remote_retrieve_body( $response );
    $json_response = json_decode($body);
    $workspace_id = $json_response->workspaceId;
  }
  return $workspace_id;
}

function cubicast_get_workspace_url($workspace_id) {
  return CUBICAST_APP_HOST . "/workspaces/$workspace_id/recordings";
}

function cubicast_plugin_settings_page() {
  $key_updated = false;
  $workspace_id = NULL;
  if (
    isset( $_POST['_wpnonce'] ) 
    && wp_verify_nonce( $_POST['_wpnonce'], 'update_cubicast_settings' ) 
  ) {
    if ( isset( $_POST['cubicast_api_key'] ) ) {
      $cubicast_api_key = sanitize_text_field($_POST['cubicast_api_key']);
      $key_updated = true;
    }
    if ( isset( $_POST['cubicast_user_data'] ) ) {
      $cubicast_user_data = $_POST['cubicast_user_data'];
      $valid_values = array( 'name', 'nickname', 'phone', 'address', 'company' );
      $invalid_values = array_diff( $cubicast_user_data, $valid_values );
      if ( $invalid_values ) {
        // remove invalid values
        $cubicast_user_data = array_diff( $cubicast_user_data, $invalid_values );
      }
    } else {
      $cubicast_user_data = array();
    }
    update_option('cubicast_user_data', $cubicast_user_data);
  } else {
    $cubicast_api_key = get_option( 'cubicast_api_key' );
    $cubicast_user_data = get_option( 'cubicast_user_data' );
  }

  if ( !empty( $cubicast_api_key ) ) {
    $workspace_id = cubicast_validate_api_key( $cubicast_api_key );
  
    if ( $key_updated ) {
      if ( $workspace_id != NULL ) {
        // valid key provided
        add_action( 'user_admin_notices', 'cubicast_settings_updated__success' );
      } else {
        // invalid key
       $cubicast_api_key = '';
      }
      update_option('cubicast_api_key', $cubicast_api_key);
    }
  
    if ( !isset( $workspace_id ) ) {
      $message = 'Invalid Cubicast API key.';
      add_action(
        'user_admin_notices',
        function() use ( $message ) {
          cubicast_notice__error( $message );
        }
      );
    }
  } else if ( $key_updated ) {
    // cubicast key cleared
    update_option('cubicast_api_key', $cubicast_api_key);
    add_action( 'user_admin_notices', 'cubicast_settings_updated__success' );
  }

  if ( empty( $cubicast_api_key ) || !isset( $workspace_id ) ) {
    add_action( 'user_admin_notices', 'cubicast_incative__warning' );
  }
  ?>
  <div class="wrap">
    <h1><?php
      _e('Cubicast Settings', 'cubicast');
    ?></h1>
    <?php
      do_action('user_admin_notices');
    ?>
    <form method="POST">
      <?php
        wp_nonce_field( 'update_cubicast_settings' );
      ?>
      <table class="form-table" role="presentation">
        <tr>
          <th scope="row">
            <label for="cubicast_api_key"><?php
              _e('Cubicast Workspace API Key', 'cubicast');
            ?></label>
          </th>
          <td>
            <input
              name="cubicast_api_key"
              type="text"
              id="cubicast_api_key"
              value="<?php echo $cubicast_api_key; ?>"
              class="regular-text"
              maxlength="32"
            />
            <?php
            if ($workspace_id) {
            ?>
              <input
                type="button"
                class="button"
                value="<?php _e('View Sessions', 'cubicast') ?>"
                onclick="javascript:window.open('<?php echo cubicast_get_workspace_url( $workspace_id ); ?>', 'cubicast')"
              />
            <?php
            }
            ?>
          </td>
        </tr>
        <tr>
          <th scope="row">
            <?php _e('Sync User Data with Cubicast', 'cubicast'); ?>
          </th>
          <td>
            <fieldset>
              <label>
                <input type="checkbox" name="cubicast_user_data[]" value="name"
                  <?php if ( in_array( 'name', $cubicast_user_data ) ) echo 'checked' ?>
                />
                <span>
                  <?php _e('User First Name and Last Name', 'cubicast'); ?>
                </span>
              </label><br>
              <label>
                <input type="checkbox" name="cubicast_user_data[]" value="nickname"
                  <?php if ( in_array( 'nickname', $cubicast_user_data ) ) echo 'checked' ?>
                />
                <span>
                  <?php _e('User Nickname', 'cubicast'); ?>
                </span>
              </label><br>
              <label>
                <input type="checkbox" name="cubicast_user_data[]" value="phone"
                  <?php if ( in_array( 'phone', $cubicast_user_data ) ) echo 'checked' ?>
                />
                <span>
                  <?php _e('Customer Phone', 'cubicast'); ?> (WooCommerce)
                </span>
              </label><br>
              <label>
                <input type="checkbox" name="cubicast_user_data[]" value="company"
                  <?php if ( in_array( 'company', $cubicast_user_data ) ) echo 'checked' ?>
                />
                <span>
                  <?php _e('Customer Company', 'cubicast'); ?> (WooCommerce)
                </span>
              </label><br>
              <label>
                <input type="checkbox" name="cubicast_user_data[]" value="address"
                  <?php if ( in_array( 'address', $cubicast_user_data ) ) echo 'checked' ?>
                />
                <span>
                  <?php _e('Customer Address', 'cubicast'); ?> (WooCommerce)
                </span>
              </label>
            </fieldset>
          </td>
        </tr>
      </table>
      <p class="submit">
        <input
          type="submit"
          name="submit"
          id="submit"
          class="button button-primary"
          value="<?php _e('Save Changes', 'cubicast') ?>"
        />
      </p>
    </form>
  </div>
  <?php
}

add_action( 'wp_head', 'cubicast_hook_head', 1 );

function cubicast_hook_head() {
  $cubicast_api_key = get_option('cubicast_api_key');

  if ( !isset( $cubicast_api_key ) || empty( $cubicast_api_key ) ) {
    return;
  }

  $output="<link rel=\"preconnect\" href=\"https://api.cubicast.com\">
  <link rel=\"preconnect\" href=\"https://static.cubicast.com\">
  <script data-cfasync=\"false\">!function(){ var cw=window.cubicast=window.cubicast||{ loaded:false, invoked:false, methods:['identify','track','err','showSupportRequestDialog'], config:{}, callQueue:[], methodFactory:function(method){ return function(){ var args=Array.prototype.slice.call(arguments); args.unshift(method); cw.callQueue.push(args); return cw; } }, load:function(k){ if(cw.loaded) return; var d=document; var s=d.createElement('script'); s.async=!0; s.src='https://static.cubicast.com/js/widget/widget.js'; var poll=setInterval(function(){ var w = window.cubicast; if(w.error || w.log){ clearInterval(poll); if(window.console && console.error){ if(w.error){ console.error(w.error); }else if(w.log){ console.log(w.log); } } }else if(w.loaded){ clearInterval(poll); w._fq(cw.callQueue); } }, 500); d.querySelector('head').appendChild(s); cw.config={apiKey:k}; } }; if(cw.invoked){ if(window.console && console.error) { console.error('Cubicast widget snippet included twice.'); } return; } cw.invoked=true; for(var i=0; i<cw.methods.length; i+=1){ var key=cw.methods[i]; cw[key]=cw.methodFactory(key); } cw.load('$cubicast_api_key'); }();";

  $output .= cubicast_sync_wordpress_user();
  $output .= "</script>";
  
  echo $output;
}
