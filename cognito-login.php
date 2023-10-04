<?php
/*
  Plugin Name: Cognito Login
  description: WordPress plugin for integrating with AWS Cognito for User Pools
  Version: 1.0
  Author: Makeen-Shan
  Author URI: https://makeen.io
*/

define( 'PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
include_once( PLUGIN_PATH . 'settings.php' );

// --- Include Utilities ---
include_once( PLUGIN_PATH . 'includes/utils/generate-strings.php' );

// --- Include Units ---
include_once( PLUGIN_PATH . 'includes/units/auth.php' );
include_once( PLUGIN_PATH . 'includes/units/programmatic-login.php' );
include_once( PLUGIN_PATH . 'includes/units/user.php' );

/**
 * General initialization function container
 */
class Cognito_Login{
  /**
   * The default shortcode returns an "a" tag, or a logout link, depending on if the user is
   * logged in
   */
  public static function shortcode_default( $atts ) {
    $atts = shortcode_atts( array(
      'text' => NULL,
      'class' => NULL
    ), $atts );
    $user = wp_get_current_user();

    if ( $user->{'ID'} !== 0 ) {
      return Cognito_Login_Generate_Strings::already_logged_in( $user->{'user_login'} );
    }

    return Cognito_Login_Generate_Strings::a_tag( $atts );
  }

  /**
   * Handler for the "parse_query" action. This is the "main" function that listens for the
   * correct query variable that will trigger a login attempt
   */
  public static function parse_query_handler() {
    // Remove this function from the action queue - it should only run once
    remove_action( 'parse_query', array('Cognito_Login', 'parse_query_handler') );

    // Try to get a code from the url query and abort if we don't find one, or the user is already logged in
    $code = Cognito_Login_Auth::get_code();
    if ( $code === FALSE ) return;
    if ( is_user_logged_in() ) return;

    // Attempt to exchange the code for a token, abort if we weren't able to
    $token = Cognito_Login_Auth::get_token( $code );
    if ( $token === FALSE) return;

    // Parse the token
    $parsed_token = Cognito_Login_Auth::parse_jwt( $token['id_token'] );

    // Determine user existence
    if ( !in_array( get_option( 'username_attribute' ), $parsed_token ) ) return;
    $username = $parsed_token[get_option('username_attribute')];

    $user = get_user_by( 'login', $username );

    if ( $user === FALSE ) {
      // Also check for a user that only matches the first part of the email
      $non_email_username = substr( $username, 0, strpos( $username, '@' ) );
      $user = get_user_by( 'login', $non_email_username );

      if ( $user !== FALSE ) $username = $non_email_username;
    }

    if ( $user === FALSE ) {
      // Create a new user only if the setting is turned on
      if ( get_option( 'create_new_user' ) !== 'true' ) return;

      // Create a new user and abort on failure
      $user = Cognito_Login_User::create_user( $parsed_token );
      if ( $user === FALSE ) return;
    }

    // Log the user in! Exit if the login fails
    if ( Cognito_Login_Programmatic_Login::login( $username ) === FALSE ) return;

    // Redirect the user to the "homepage", if it is set (this will hide all `print` statements)
    $homepage = get_option('homepage');
    if ( !empty( $homepage ) ) {
      Cognito_Login_Auth::redirect_to( $homepage );
    }
  }

  /**
   * Will disable the default WordPress login experience, replacing the login interface with
   * a link to the Cognito login page. Will only activate if the disable_wp_login setting
   * is set to `true`
   *
   * This method should be added to the `login_head` action
   */
  public static function disable_wp_login() {
    if ( get_option( 'disable_wp_login' ) !== 'true' ) return;

    wp_enqueue_style( 'cognito-login-wp-login', plugin_dir_url(__FILE__) . 'public/css/cognito-login-wp-login.css' );

    $loginLink = Cognito_Login_Generate_Strings::a_tag( array(
      'text' => NULL,
      'class' => NULL
    ));
    ?>
      <script>
        window.addEventListener('load', function() {
          // Get the form
          var loginForm = document.querySelector('body.login div#login form#loginform');

          // Fully disable the form
          loginForm.action = '/';

          // Modify the inner HTML, adding the login link and removing everything else
          loginForm.innerHTML = '<?php echo $loginLink ?>';

          // Also get rid of the nav, password resets are not handled by WordPress
          var nav = document.querySelector('#nav');
          nav.parentNode.removeChild(nav);
        });
      </script>
    <?php
  }
}

// --- Add Shortcodes ---
add_shortcode( 'cognito_login', array('Cognito_Login', 'shortcode_default') );

// --- Add Actions ---
add_action( 'parse_query', array('Cognito_Login', 'parse_query_handler') );
add_action( 'login_head', array('Cognito_Login', 'disable_wp_login') );
