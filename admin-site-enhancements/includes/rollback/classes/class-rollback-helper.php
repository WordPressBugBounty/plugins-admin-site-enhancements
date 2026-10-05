<?php
/**
 * Rollback helper class.
 *
 * @since 8.7.3
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class ASENHA_Rollback_Helper
 */
class ASENHA_Rollback_Helper {

	/**
	 * Extra option key.
	 *
	 * @var string
	 */
	private $extra_option_key;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->extra_option_key = ASENHA_SLUG_U . '_extra';
	}

	/**
	 * Get the full ASE extra option as an array.
	 *
	 * @return array
	 */
	public function get_extra_option() {
		$options_extra = get_option( $this->extra_option_key, array() );

		return is_array( $options_extra ) ? $options_extra : array();
	}

	/**
	 * Get rollback module data from the ASE extra option.
	 *
	 * @return array
	 */
	public function get_module_data() {
		$options_extra = $this->get_extra_option();

		if ( ! isset( $options_extra['rollback'] ) || ! is_array( $options_extra['rollback'] ) ) {
			return array();
		}

		return $options_extra['rollback'];
	}

	/**
	 * Persist rollback module data into the ASE extra option.
	 *
	 * @param array $module_data Rollback module data.
	 * @return bool
	 */
	public function update_module_data( $module_data ) {
		$options_extra             = $this->get_extra_option();
		$options_extra['rollback'] = is_array( $module_data ) ? $module_data : array();

		return update_option( $this->extra_option_key, $options_extra, true );
	}

	/**
	 * Get the catalog entries for an asset type.
	 *
	 * @param string $asset_type Asset type.
	 * @return array
	 */
	public function get_catalog( $asset_type ) {
		$module_data = $this->get_module_data();

		if ( ! isset( $module_data['catalog'][ $asset_type ] ) || ! is_array( $module_data['catalog'][ $asset_type ] ) ) {
			return array();
		}

		return $module_data['catalog'][ $asset_type ];
	}

	/**
	 * Get a single catalog entry.
	 *
	 * @param string $asset_type Asset type.
	 * @param string $asset_key  Asset key.
	 * @return array
	 */
	public function get_catalog_entry( $asset_type, $asset_key ) {
		$catalog = $this->get_catalog( $asset_type );

		if ( isset( $catalog[ $asset_key ] ) && is_array( $catalog[ $asset_key ] ) ) {
			return $catalog[ $asset_key ];
		}

		return array();
	}

	/**
	 * Persist catalog entries for an asset type.
	 *
	 * @param string $asset_type Asset type.
	 * @param array  $entries    Catalog entries.
	 * @return bool
	 */
	public function set_catalog( $asset_type, $entries ) {
		$module_data = $this->get_module_data();

		if ( ! isset( $module_data['catalog'] ) || ! is_array( $module_data['catalog'] ) ) {
			$module_data['catalog'] = array();
		}

		$module_data['catalog'][ $asset_type ] = is_array( $entries ) ? $entries : array();
		$module_data['updated_at']              = time();

		return $this->update_module_data( $module_data );
	}

	/**
	 * Persist a single catalog entry.
	 *
	 * @param string $asset_type Asset type.
	 * @param string $asset_key  Asset key.
	 * @param array  $entry      Catalog entry.
	 * @return bool
	 */
	public function set_catalog_entry( $asset_type, $asset_key, $entry ) {
		$catalog               = $this->get_catalog( $asset_type );
		$catalog[ $asset_key ] = is_array( $entry ) ? $entry : array();

		return $this->set_catalog( $asset_type, $catalog );
	}

	/**
	 * Get the dedicated storage hash for rollback archives.
	 *
	 * @return string
	 */
	public function get_storage_hash() {
		$module_data = $this->get_module_data();

		if ( isset( $module_data['storage_hash'] ) && is_string( $module_data['storage_hash'] ) && '' !== $module_data['storage_hash'] ) {
			return sanitize_key( $module_data['storage_hash'] );
		}

		$storage_hash                = strtolower( wp_generate_password( 12, false, false ) );
		$module_data['storage_hash'] = $storage_hash;
		$this->update_module_data( $module_data );

		return $storage_hash;
	}

	/**
	 * Get the rollback storage root directory.
	 *
	 * @return string
	 */
	public function get_storage_root() {
		return trailingslashit( WP_CONTENT_DIR ) . 'asenha-rollbacks-' . $this->get_storage_hash();
	}

	/**
	 * Ensure the rollback storage directory structure exists.
	 *
	 * @return true|WP_Error
	 */
	public function ensure_storage_root() {
		$root_directory = $this->get_storage_root();

		if ( ! wp_mkdir_p( $root_directory ) ) {
			return new WP_Error(
				'asenha_rollback_storage_root_failed',
				__( 'The rollback storage directory could not be created.', 'admin-site-enhancements' )
			);
		}

		foreach ( array( 'plugin', 'theme' ) as $asset_type ) {
			$asset_directory = trailingslashit( $root_directory ) . $asset_type;

			if ( ! wp_mkdir_p( $asset_directory ) ) {
				return new WP_Error(
					'asenha_rollback_storage_directory_failed',
					__( 'One of the rollback storage directories could not be created.', 'admin-site-enhancements' )
				);
			}
		}

		return true;
	}

	/**
	 * Get the storage slug for a plugin asset.
	 *
	 * @param string $plugin_file Plugin file.
	 * @param array  $plugin_data Plugin data.
	 * @return string
	 */
	public function get_plugin_package_slug( $plugin_file, $plugin_data = array() ) {
		$plugin_directory = dirname( $plugin_file );

		if ( '.' !== $plugin_directory && '' !== $plugin_directory ) {
			return sanitize_title( $plugin_directory );
		}

		if ( ! empty( $plugin_data['TextDomain'] ) ) {
			return sanitize_title( $plugin_data['TextDomain'] );
		}

		return sanitize_title( basename( $plugin_file, '.php' ) );
	}

	/**
	 * Get a relative archive path.
	 *
	 * @param string $asset_type   Asset type.
	 * @param string $package_slug Package slug.
	 * @param string $filename     Archive filename.
	 * @return string
	 */
	public function get_archive_relative_path( $asset_type, $package_slug, $filename ) {
		return trim( $asset_type, '/' ) . '/' . trim( sanitize_title( $package_slug ), '/' ) . '/' . ltrim( $filename, '/' );
	}

	/**
	 * Convert a relative archive path into an absolute path.
	 *
	 * @param string $relative_path Relative path.
	 * @return string
	 */
	public function get_archive_absolute_path( $relative_path ) {
		return trailingslashit( $this->get_storage_root() ) . ltrim( $relative_path, '/' );
	}

	/**
	 * Normalize a rollback note excerpt for list displays.
	 *
	 * @param string $note_html Note HTML.
	 * @return string
	 */
	public function get_note_excerpt( $note_html ) {
		$plain_text = wp_strip_all_tags( html_entity_decode( (string) $note_html, ENT_QUOTES, get_bloginfo( 'charset' ) ) );
		$plain_text = preg_replace( '/\s+/u', ' ', $plain_text );

		return trim( wp_trim_words( (string) $plain_text, 22, '...' ) );
	}

	/**
	 * Check whether the given URL points to WordPress.org.
	 *
	 * @param string $url URL to inspect.
	 * @return bool
	 */
	public function is_wordpress_org_url( $url ) {
		$url = (string) $url;

		if ( '' === $url ) {
			return false;
		}

		return (
			false !== strpos( $url, 'wordpress.org/' ) ||
			false !== strpos( $url, 'downloads.wordpress.org/' )
		);
	}

	/**
	 * Build a safe archive filename.
	 *
	 * @param string $package_slug Package slug.
	 * @param string $version      Version string.
	 * @return string
	 */
	public function build_archive_filename( $package_slug, $version ) {
		$package_slug = sanitize_title( $package_slug );
		$version      = preg_replace( '/[^A-Za-z0-9\.\-\_]/', '-', (string) $version );
		$timestamp    = gmdate( 'Ymd-His' );

		return sprintf( '%1$s-%2$s-%3$s.zip', $package_slug, $version, $timestamp );
	}

	/**
	 * Get toolbar settings used by rollback note editors.
	 *
	 * @return array
	 */
	public function get_editor_settings() {
		return array(
			'media_buttons' => false,
			'textarea_rows' => 8,
			'teeny'         => false,
			'quicktags'     => false,
			'tinymce'       => array(
				'toolbar1' => 'bold,italic,strikethrough,underline,bullist,numlist,link,unlink,undo,redo',
				'toolbar2' => '',
			),
		);
	}
}
