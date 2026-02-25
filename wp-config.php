<?php
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the installation.
 * You don't have to use the website, you can copy this file to "wp-config.php"
 * and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * Database settings
 * * Secret keys
 * * Database table prefix
 * * ABSPATH
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/
 *
 * @package WordPress
 */

// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', 'wordpress-demo-app' );

/** Database username */
define( 'DB_USER', 'root' );

/** Database password */
define( 'DB_PASSWORD', '' );

/** Database hostname */
define( 'DB_HOST', 'localhost' );

/** Database charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8mb4' );

/** The database collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );

/**#@+
 * Authentication unique keys and salts.
 *
 * Change these to different unique phrases! You can generate these using
 * the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}.
 *
 * You can change these at any point in time to invalidate all existing cookies.
 * This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define( 'AUTH_KEY',         'v.b9%rMlu7xq?DZyLcsY|c1Ei+U~6y&b6TtKMd+N[.~O7#Z}y.$f4U}|R/b)j.ML' );
define( 'SECURE_AUTH_KEY',  ',S@|f@YyB6]}~Y,(y|ibI,3E[@Y)shc]I|X=,-5E-l0Q31&?}Kdfs+SFKStrxlDD' );
define( 'LOGGED_IN_KEY',    'Q.r|]l3lw>jC>ii;^)@W>^ocJAq ;A=b} Iarq*h27dtET:n`xeUg;0xJRIp?~1W' );
define( 'NONCE_KEY',        'S=}9DF2eJ^?]h.8r[56!AY^|@VHF01{UDpMLAC=CZSp$3yDP<AQjA$LCgifW//pX' );
define( 'AUTH_SALT',        '*3-{{|u`lls)F9M}`NrTY)rLELJrgv7%UDsb-_HOK1sI/w^FFAl}<VHjAqw~$(B)' );
define( 'SECURE_AUTH_SALT', '7MD~4NRGSuVwE]QDt0(2VYO1Iu3Z:&f=u|~e^pc>?[v E8_`#MK2K6cT<nL.(e~[' );
define( 'LOGGED_IN_SALT',   'ec_QSfz(KEN~C5aM}%ZMfRk(v/ZidL@Hq! ~/J5;E,PdB/0f9gskD!3oG_eutt1]' );
define( 'NONCE_SALT',       '>Ww]y]nF-@c@<lJ5:1:ASx9VB}( 9@}I4oDgj{%T(zt520LnM=:;#.$x1/]gp-Aq' );

/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 *
 * At the installation time, database tables are created with the specified prefix.
 * Changing this value after WordPress is installed will make your site think
 * it has not been installed.
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/#table-prefix
 */
$table_prefix = 'wp_';

/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the documentation.
 *
 * @link https://developer.wordpress.org/advanced-administration/debug/debug-wordpress/
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );

/* Add any custom values between this line and the "stop editing" line. */

define('FS_METHOD', 'direct');

if ( ! defined( 'PAYU_ENCRYPTION_KEY' ) ) {
	define( 'PAYU_ENCRYPTION_KEY', 'f3b2e1c4d5a6978877665544332211aaff00bbccddeeff1122334455667788aa' );
}

/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

define( 'WP_HOME', 'https://tall-tongue.outray.app/woocommerce-payu-integration/' );
define( 'WP_SITEURL', 'https://tall-tongue.outray.app/woocommerce-payu-integration/' );

// If your ngrok is HTTPS and you want WP to treat requests as HTTPS:
if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    $_SERVER['HTTPS'] = 'on';
}


/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';