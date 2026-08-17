<?php
/**
 * WebberZone Image Optimizer Options API.
 *
 * @since 0.9.0
 *
 * @package WebberZone\Image_Optimizer
 */

namespace WebberZone\Image_Optimizer;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Options API Class.
 *
 * @since 0.9.0
 */
class Options_API {

	/**
	 * Settings option name.
	 *
	 * @since 0.9.0
	 * @var string
	 */
	const SETTINGS_OPTION = 'wzio_settings';

	/**
	 * Filter prefix.
	 *
	 * @since 0.9.0
	 * @var string
	 */
	const FILTER_PREFIX = 'wzio';

	/**
	 * Per-request settings cache, keyed by blog ID.
	 *
	 * Keyed rather than a single array so that a `switch_to_blog()` in the same
	 * request reads that blog's settings instead of the ones cached before the
	 * switch. On single site the key is always 0. Caching here matters more than
	 * in most plugins: `get_settings()` calls `get_settings_defaults()`, which
	 * builds every field definition, on each uncached call.
	 *
	 * @since 0.10.0
	 * @var array<int, array>
	 */
	private static $settings_cache = array();

	/**
	 * Cache key for the current blog.
	 *
	 * @since 0.10.0
	 *
	 * @return int Blog ID on multisite, 0 otherwise.
	 */
	private static function cache_key() {
		return is_multisite() ? get_current_blog_id() : 0;
	}

	/**
	 * Flush the per-request settings cache.
	 *
	 * Call after any write that bypasses this class (e.g. a direct
	 * `update_option()` call) so a subsequent read in the same request sees the
	 * new value. Pass a blog ID to flush a single blog, or nothing to flush all.
	 *
	 * @since 0.10.0
	 *
	 * @param int|null $blog_id Blog ID to flush. Null flushes every cached blog.
	 * @return void
	 */
	public static function flush_cache( $blog_id = null ) {
		if ( null === $blog_id ) {
			self::$settings_cache = array();
			return;
		}

		unset( self::$settings_cache[ (int) $blog_id ] );
	}

	/**
	 * Get all plugin settings.
	 *
	 * @since 0.9.0
	 * @return array WebberZone Image Optimizer settings
	 */
	public static function get_settings() {
		$cache_key = self::cache_key();

		if ( ! array_key_exists( $cache_key, self::$settings_cache ) ) {
			$settings = get_option( self::SETTINGS_OPTION, array() );

			if ( ! is_array( $settings ) ) {
				$settings = array();
			}

			$settings = wp_parse_args( $settings, self::get_settings_defaults() );

			/**
			 * Settings array
			 *
			 * Retrieves all plugin settings
			 *
			 * @since 0.9.0
			 * @param array $settings Settings array
			 */
			self::$settings_cache[ $cache_key ] = apply_filters( self::FILTER_PREFIX . '_get_settings', $settings ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
		}

		return self::$settings_cache[ $cache_key ];
	}

	/**
	 * Get an option or its default.
	 *
	 * @since 0.9.0
	 *
	 * @param string $key           Option to fetch.
	 * @param mixed  $default_value Default option.
	 * @return mixed
	 */
	public static function get_option( $key = '', $default_value = null ) {
		$settings = self::get_settings();

		$value = isset( $settings[ $key ] ) ? $settings[ $key ] : null;

		if ( is_null( $value ) ) {
			if ( is_null( $default_value ) ) {
				$default_value = self::get_default_option( $key );
			}
			$value = $default_value;
		}

		/**
		 * Filter the value for the option being fetched.
		 *
		 * @since 0.9.0
		 *
		 * @param mixed $value         Value of the option.
		 * @param mixed $key           Name of the option.
		 * @param mixed $default_value Default value.
		 */
		$value = apply_filters( self::FILTER_PREFIX . '_get_option', $value, $key, $default_value ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound

		/**
		 * Key specific filter for the value of the option being fetched.
		 *
		 * @since 0.9.0
		 *
		 * @param mixed $value         Value of the option.
		 * @param mixed $key           Name of the option.
		 * @param mixed $default_value Default value.
		 */
		return apply_filters( self::FILTER_PREFIX . "_get_option_{$key}", $value, $key, $default_value ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
	}

	/**
	 * Update an option and the in-request cache.
	 *
	 * @since 0.9.0
	 *
	 * @param  string          $key   The Key to update.
	 * @param  string|bool|int $value The value to set the key to.
	 * @return boolean True if updated, false if not.
	 */
	public static function update_option( $key = '', $value = false ) {
		// If no key, exit.
		if ( empty( $key ) ) {
			return false;
		}

		// First let's grab the current settings.
		$options = get_option( self::SETTINGS_OPTION, array() );

		// Let's let devs alter that value coming in.
		$value = apply_filters( self::FILTER_PREFIX . '_update_option', $value, $key ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound

		// Next let's try to update the value.
		$options[ $key ] = $value;
		$did_update      = update_option( self::SETTINGS_OPTION, $options );

		// If it updated, let's update the static variable.
		if ( $did_update ) {
			self::$settings_cache[ self::cache_key() ] = $options;
		}

		return $did_update;
	}

	/**
	 * Update all settings at once.
	 *
	 * @since 0.9.0
	 *
	 * @param array $settings  Settings array to save.
	 * @param bool  $merge     Whether to merge with existing settings. Default true.
	 * @param bool  $autoload  Whether to autoload the option. Default true.
	 * @return bool True if updated, false otherwise.
	 */
	public static function update_settings( array $settings, bool $merge = true, bool $autoload = true ): bool {
		// Merge incoming array into existing settings if requested.
		if ( $merge ) {
			$existing = (array) self::get_settings();
			$settings = array_merge( $existing, $settings );
		}
		$did_update = update_option( self::SETTINGS_OPTION, $settings, $autoload );
		if ( $did_update ) {
			self::$settings_cache[ self::cache_key() ] = $settings;
		}
		return $did_update;
	}

	/**
	 * Remove an option and update the in-request cache.
	 *
	 * @since 0.9.0
	 *
	 * @param  string $key The Key to delete.
	 * @return boolean True if updated, false if not.
	 */
	public static function delete_option( $key = '' ) {
		// If no key, exit.
		if ( empty( $key ) ) {
			return false;
		}

		// First let's grab the current settings.
		$options = get_option( self::SETTINGS_OPTION, array() );

		// Next let's try to update the value.
		if ( isset( $options[ $key ] ) ) {
			unset( $options[ $key ] );
		}

		$did_update = update_option( self::SETTINGS_OPTION, $options );

		// If it updated, let's update the static variable.
		if ( $did_update ) {
			self::$settings_cache[ self::cache_key() ] = $options;
		}

		return $did_update;
	}

	/**
	 * Default settings.
	 *
	 * @since 0.9.0
	 *
	 * @return array Default settings
	 */
	public static function get_settings_defaults() {
		return Admin\Settings::settings_defaults();
	}

	/**
	 * Get the default option for a specific key
	 *
	 * @since 0.9.0
	 *
	 * @param string $key Key of the option to fetch.
	 * @return mixed
	 */
	public static function get_default_option( $key = '' ) {
		/**
		 * Filter the default settings array.
		 *
		 * Mirrors the filter applied in `Admin\Settings::settings_defaults()` so that
		 * this translation-free path honours the same hook. `Admin\Settings::get_defaults()`
		 * is unfiltered, so the filter runs exactly once on each path.
		 *
		 * @since 0.9.0
		 *
		 * @param array $defaults Default settings.
		 */
		$default_settings = apply_filters(
			self::FILTER_PREFIX . '_settings_defaults',
			Admin\Settings::get_defaults()
		);

		if ( array_key_exists( $key, $default_settings ) ) {
			return $default_settings[ $key ];
		}

		return false;
	}

	/**
	 * Reset settings.
	 *
	 * @since 0.9.0
	 *
	 * @return bool True if updated, false if not.
	 */
	public static function reset_settings(): bool {
		$defaults   = self::get_settings_defaults();
		$did_update = update_option( self::SETTINGS_OPTION, $defaults );

		// If it updated, let's update the static variable.
		if ( $did_update ) {
			self::$settings_cache[ self::cache_key() ] = $defaults;
		}

		return $did_update;
	}
}
