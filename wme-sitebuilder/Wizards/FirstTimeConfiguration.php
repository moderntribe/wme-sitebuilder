<?php

namespace Tribe\WME\Sitebuilder\Wizards;

use ActionScheduler_QueueRunner;
use Exception;
use Spatie\Async\Pool;
use Throwable;
use Tribe\WME\Sitebuilder\Concerns\StoresData;

class FirstTimeConfiguration extends Wizard {

	use StoresData;

	const FIELD_INDUSTRY  = 'industry';
	const FIELD_LOGO      = 'logo';
	const FIELD_PASSWORD  = 'password';
	const FIELD_SITENAME  = 'siteName';
	const FIELD_TAGLINE   = 'tagLine';
	const FIELD_USERNAME  = 'username';
	const DATA_STORE_NAME = '_sitebuilder_ftc';
	const IMPORT_HOOK     = 'wme_import_industry_image';
	const RUNNER_HOOK     = 'wme_create_as_queue_runner';

	/**
	 * @var string
	 */
	protected $admin_page_slug = 'sitebuilder';

	/**
	 * @var string
	 */
	protected $wizard_slug = 'ftc';

	/**
	 * @var string
	 */
	protected $ajax_action = 'sitebuilder-wizard-ftc';

	/**
	 * @var array
	 */
	protected $fields = [];

	/**
	 * @var array
	 *
	 * 'sample-industry' => [ 'collection ID' ]
	 *
	 * @todo populate with map of industry values to collection IDs
	 */
	protected $industries_to_collection_ids = [
		'massage-therapy' => [ '12345' ],
	];

	/**
	 * @var array
	 */
	public $errors = [];

	/**
	 * Construct.
	 */
	public function __construct() {
		parent::__construct();

		$this->add_ajax_action( 'wizard_started', [ $this, 'telemetryWizardStarted' ] );
		$this->add_ajax_action( 'validateUsername', [ $this, 'validateUsername' ] );
		$this->add_ajax_action( 'import_industry_images', [ $this, 'startIndustryImagesImport' ] );
	}

	/**
	 * Register hooks.
	 */
	public function register_hooks() {
		parent::register_hooks();

		add_action( 'current_screen', [ $this, 'autoLaunch' ] );
		add_action( 'wp_ajax_' . self::RUNNER_HOOK, [ $this, 'actionCreateActionSchedulerQueueRunner' ] );
		add_action( 'wp_ajax_nopriv_' . self::RUNNER_HOOK, [ $this, 'actionCreateActionSchedulerQueueRunner' ] );
		add_action( self::IMPORT_HOOK, [ $this, 'actionImportIndustryImageAsync' ], 10, 3 );
		add_action( 'kadence-starter-templates/after_all_import_execution', [ $this, 'restoreLogoAfterKadenceImport' ] );
	}

	/**
	 * Telemetry: wizard started.
	 */
	public function telemetryWizardStarted() {
		do_action( 'wme_event_wizard_started', 'ftc' );

		return wp_send_json_success();
	}

	/**
	 * Get properties.
	 *
	 * @return array
	 */
	public function props() {
		return [
			'username'    => $this->getUsername(),
			'completed'   => $this->isComplete(),
			'autoLaunch'  => false,
			'canBeClosed' => $this->isComplete(),
			'adminUrl'    => admin_url(),
			'site'        => [
				'siteName' => $this->getSitename(),
				'tagline'  => $this->getTagline(),
				'logo'     => [
					'id'  => $this->getLogoId(),
					'url' => $this->getLogoUrl(),
				],
			],
		];
	}

	/**
	 * AJAX sub-action to validate the provided username.
	 */
	public function validateUsername() {
		$username = sanitize_text_field( $_POST[ self::FIELD_USERNAME ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$errors   = [];

		if ( ! $this->isUsernameValid( $username ) ) {
			$errors[] = __( 'Username not valid.', 'wme-sitebuilder' );
		}

		if ( ! empty( $errors ) ) {
			wp_send_json_error( $errors, 400 );
		}

		wp_send_json_success();
	}

	/**
	 * Finish the wizard.
	 */
	public function finish() {
		$fields = [
			self::FIELD_LOGO,
			self::FIELD_SITENAME,
			self::FIELD_TAGLINE,
		];

		foreach ( $fields as $field ) {
			$method_name = sprintf( 'set%s', ucfirst( $field ) );

			if ( ! method_exists( $this, $method_name ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
				trigger_error( sprintf( 'Method <code>%s</code> to save <code>%s</code> field is not defined.', esc_html( $method_name ), esc_html( $field ) ), E_USER_WARNING );

				continue;
			}

			$callable = [ $this, $method_name ];

			if ( ! is_callable( $callable ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
				trigger_error( sprintf( 'Method <code>%s</code> to save <code>%s</code> field is defined but not callable.', esc_html( $method_name ), esc_html( $field ) ), E_USER_WARNING );

				continue;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( ! array_key_exists( $field, $_POST ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
				trigger_error( sprintf( 'Field <code>%s</code> is absent in $_POST global.', esc_html( $field ) ), E_USER_WARNING );

				continue;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			call_user_func( $callable, $_POST[ $field ] );
		}

		$this->getData()->set( 'complete', true )->save();

		do_action( 'wme_event_wizard_completed', 'ftc' );

		$this->setUserCredentials();

		wp_send_json_success();
	}

	/**
	 * Check if Wizard has been completed.
	 *
	 * @return bool
	 */
	public function isComplete() {
		return (bool) $this->getData()->get( 'complete', false );
	}

	/**
	 * Auto launch the wizard.
	 *
	 * @action current_screen
	 */
	public function autoLaunch() {
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return;
		}

		if ( ! is_admin() || $this->isComplete() ) {
			return;
		}

		// Allow implementers to implicitely autolaunch the First Time Configuration Wizard.
		if ( ! apply_filters( 'wme_sitebuilder_autolaunch_wizard', false ) ) {
			return;
		}

		$screen = get_current_screen();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $screen ) && sprintf( 'toplevel_page_%s', $this->admin_page_slug ) === $screen->id ) {
			return;
		}

		$redirect_url  = add_query_arg( 'page', $this->admin_page_slug, admin_url( 'admin.php' ) );
		$redirect_url .= sprintf( '#/wizard/%s', $this->wizard_slug );

		wp_safe_redirect( $redirect_url );

		exit;
	}

	/**
	 * Returns the current users user_login.
	 *
	 * @return string
	 */
	public function getUsername() {
		$user = wp_get_current_user();

		if ( empty( $user->user_login ) ) {
			return '';
		}

		return $user->user_login;
	}

	/**
	 * Returns the logo id if a logo exists, or 0.
	 *
	 * @return int
	 */
	public function getLogoId() {
		if ( empty( $this->fields['logo_id'] ) ) {
			$this->fields['logo_id'] = absint( get_option( 'site_logo', 0 ) );
		}

		return $this->fields['logo_id'];
	}

	/**
	 * Returns the logo url if a logo exists, an empty string otherwise.
	 *
	 * @return string
	 */
	public function getLogoUrl() {
		if ( empty( $this->getLogoId() ) ) {
			return '';
		}

		$url = wp_get_attachment_image_url( $this->getLogoId(), 'full' );

		if ( empty( $url ) ) {
			return '';
		}

		return $url;
	}

	/**
	 * Returns the logo id if a logo exists, or 0.
	 *
	 * @return int
	 */
	public function getLogo() {
		if ( empty( $this->fields[ self::FIELD_LOGO ] ) ) {
			$this->fields[ self::FIELD_LOGO ] = get_option( 'site_logo', 0 );
		}

		return (int) $this->fields[ self::FIELD_LOGO ];
	}

	/**
	 * Set logo ID.
	 *
	 * @param int $logo
	 */
	public function setLogo( $logo ) {
		if ( empty( $logo ) ) {
			update_option( 'site_logo', null );
			$this->getData()->set( 'logo', null );
			return;
		}

		$logo = absint( filter_var( $logo, FILTER_SANITIZE_NUMBER_INT ) );

		if ( empty( $logo ) ) {
			$this->errors[] = [ self::FIELD_LOGO => __( 'Invalid Logo', 'wme-sitebuilder' ) ];
			return;
		}

		if ( $logo === $this->getLogo() ) {
			return;
		}

		$this->getData()->set( 'logo', $logo );

		if ( update_option( 'site_logo', $logo ) ) {
			return;
		}

		$this->errors[] = [ self::FIELD_LOGO => __( 'Unable to save the Logo', 'wme-sitebuilder' ) ];
	}

	/**
	 * Get the current site name.
	 *
	 * @return string
	 */
	public function getSitename() {
		if ( empty( $this->fields[ self::FIELD_SITENAME ] ) ) {
			$this->fields[ self::FIELD_SITENAME ] = get_bloginfo( 'name' );
		}

		return $this->fields[ self::FIELD_SITENAME ];
	}

	/**
	 * Set the site name.
	 *
	 * @param string $sitename
	 */
	public function setSitename( $sitename ) {
		$sitename = filter_var( $sitename, FILTER_SANITIZE_FULL_SPECIAL_CHARS );

		if ( $sitename === $this->getSitename() ) {
			return;
		}

		if ( ! update_option( 'blogname', $sitename ) ) {
			$this->errors[] = [ self::FIELD_SITENAME => __( 'Invalid Sitename', 'wme-sitebuilder' ) ];
		}
	}

	/**
	 * Get the current site tagline.
	 *
	 * @return string
	 */
	public function getTagline() {
		if ( empty( $this->fields[ self::FIELD_TAGLINE ] ) ) {
			$this->fields[ self::FIELD_TAGLINE ] = get_bloginfo( 'description' );
		}

		return $this->fields[ self::FIELD_TAGLINE ];
	}

	/**
	 * Set the site description (tagLine).
	 *
	 * @param string $tagline
	 */
	public function setTagline( $tagline ) {
		$tagline = filter_var( $tagline, FILTER_SANITIZE_FULL_SPECIAL_CHARS );

		if ( $tagline === $this->getTagline() ) {
			return;
		}

		if ( ! update_option( 'blogdescription', $tagline ) ) {
			$this->errors[] = [ self::FIELD_TAGLINE => __( 'Invalid Tagline', 'wme-sitebuilder' ) ];
		}
	}

	/**
	 * Set user's credentials.
	 */
	protected function setUserCredentials() {
		$user = wp_get_current_user();

		$updated_password = false;
		$updated_username = false;

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST[ self::FIELD_PASSWORD ] ) ) {
			$password = sanitize_text_field( $_POST[ self::FIELD_PASSWORD ] );
			wp_set_password( $password, $user->ID );
			$updated_password = true;
		}

		if ( isset( $_POST[ self::FIELD_USERNAME ] ) ) {
			$username = sanitize_text_field( $_POST[ self::FIELD_USERNAME ] );

			if ( $user->user_login !== $username && $this->isUsernameValid( $username ) ) {
				global $wpdb;

				$updated_username = $wpdb->update(
					$wpdb->users,
					[ 'user_login' => $username ],
					[ 'ID' => $user->ID ]
				);
			}
		}
		// phpcs:enable

		if ( ! $updated_password && ! $updated_username ) {
			return;
		}

		do_action( 'wme_sitebuilder_user_password_updated', $user->ID );

		clean_user_cache( $user->ID );

		$user = wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID );

		do_action( 'wp_login', $user->user_login, $user );
	}

	/**
	 * Validates the username entered.
	 *
	 * @param string $username The username to validate.
	 *
	 * @return bool True if the username is valid, false otherwise.
	 */
	protected function isUsernameValid( $username ) {
		if ( ! validate_username( $username ) ) {
			return false;
		}

		$illegal_usernames = (array) apply_filters('illegal_user_logins', [
			'adm',
			'admin',
			'admin1',
			'hostname',
			'manager',
			'qwerty',
			'root',
			'support',
			'sysadmin',
			'test',
			'user',
			'webmaster',
		]);

		$illegal_usernames = array_map( 'strtolower', $illegal_usernames );

		if ( in_array( mb_strtolower( $username ), $illegal_usernames, true ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Restore set logo after Kadence template import.
	 *
	 * @param string[] $selected_import_files
	 */
	public function restoreLogoAfterKadenceImport( $selected_import_files ) {
		$site_logo = get_option( 'site_logo', null );
		$ftc_logo  = $this->getData()->get( 'logo' );

		if ( $site_logo === $ftc_logo ) {
			return;
		}

		update_option( 'site_logo', $ftc_logo );
	}

	/**
	 * Import industry images from industry selected.
	 */
	public function startIndustryImagesImport() {
		$industry = sanitize_text_field( $_POST[ self::FIELD_INDUSTRY ] );

		if ( empty( $industry ) ) {
			wp_send_json_error( __( 'Industry not valid.', 'wme-sitebuilder' ), 400 );
		}

		$collection_id = $this->getCollectionIdForIndustry( $industry );

		if ( empty( $collection_id ) ) {
			wp_send_json_error( __( 'Industry not valid.', 'wme-sitebuilder' ), 400 );
		}

		$response = $this->getIndustryCollectionImages( $collection_id );
		$images   = $response->images;

		if ( empty( $images ) ) {
			wp_send_json_error( __( 'No images for industry.', 'wme-sitebuilder' ), 400 );
		}

		$actions = [];

		foreach ( $images as $image ) {
			$actions[] = as_enqueue_async_action( self::IMPORT_HOOK, [ $image ] );
		}

		$actions = array_filter( $actions );

		if ( empty( $actions ) ) {
			wp_send_json_error( __( 'Unable to enqueue tasks to import images.', 'wme-sitebuilder' ), 400 );
		}

		// Spawn Action Scheduler queue runner immediately.
		wp_remote_post( admin_url( 'admin-ajax.php' ), [
			'method'      => 'POST',
			'timeout'     => 45,
			'redirection' => 5,
			'httpversion' => '1.0',
			'blocking'    => false,
			'headers'     => [],
			'cookies'     => [],
			'body'        => [
				'action' => self::RUNNER_HOOK,
				'nonce'  => wp_create_nonce( self::RUNNER_HOOK ),
			],
		] );


		wp_send_json_success();
	}

	/**
	 * Get collection ID for provided industry.
	 *
	 * @param string $industry
	 *
	 * @return string
	 */
	protected function getCollectionIdForIndustry( $industry ) {
		if ( ! array_key_exists( $industry, $this->industries_to_collection_ids ) ) {
			return '';
		}

		$collection_ids = $this->industries_to_collection_ids[ $industry ];
		$key = 0;

		if ( 1 < count( $collection_ids ) ) {
			$key = array_rand( $collection_ids );
		}

		return $collection_ids[ $key ];
	}

	/**
	 * Request industry collection images.
	 *
	 * @param string $collection_id
	 *
	 * @return stdClass
	 *
	 * @todo remove hard-coded response
	 */
	protected function getIndustryCollectionImages( $collection_id ) {
		$registered_sizes = wp_get_registered_image_subsizes();
		$request_sizes    = [];

		foreach ( $registered_sizes as $name => $size ) {
			$size['name']    = $name;
			$request_sizes[] = (object) $size;
		}

		$request_payload = [
			'collection_id' => $collection_id,
			'sizes'         => $request_sizes,
		];

// 		$request_url = '';
// 		$response    = wp_remote_get( $request_url, [
// 			'body' => $request_payload,
// 		] );
//
// 		if ( is_wp_error( $response ) ) {
// 			return (object) [];
// 		}
//
// 		return wp_remote_retrieve_body( $response );

		return (object) [
			'collection_id' => $collection_id,
			'images'        => [
				(object) [
					'alt'              => 'Image alt tag value',
					'photographer'     => 'Photographer',
					'photographer_url' => 'https://photographer.com',
					'avg_color'        => '#336699',
					'sizes'            => [
						(object) [
							'name' => 'thumbnail',
							'src'  => 'https://sitebuilder.local/wp-content/uploads/2022/10/bright-rain-150x150.png',
						],
						(object) [
							'name' => 'medium',
							'src'  => 'https://sitebuilder.local/wp-content/uploads/2022/10/bright-rain-300x169.png',
						],
						(object) [
							'name' => 'medium_large',
							'src'  => 'https://sitebuilder.local/wp-content/uploads/2022/10/bright-rain-768x432.png',
						],
						(object) [
							'name' => 'large',
							'src'  => 'https://sitebuilder.local/wp-content/uploads/2022/10/bright-rain-1024x576.png',
						],
						(object) [
							'name' => '1536x1536',
							'src'  => 'https://sitebuilder.local/wp-content/uploads/2022/10/bright-rain-1536x864.png',
						],
						(object) [
							'name' => '2048x2048',
							'src'  => 'https://sitebuilder.local/wp-content/uploads/2022/10/bright-rain-2048x1152.png',
						],
					],
				],
				( object ) [
					'alt' => '7Vf1z78McBY',
					'photographer' => 'Photographer Ing',
					'photographer_url' => 'https://photographering.com',
					'avg_color' => '#006677',
					'sizes' => [
						( object ) [
							'name' => 'thumbnail',
							'src' => 'https://sitebuilder.local/wp-content/uploads/2022/10/ing-7Vf1z78McBY-unsplash-150x150.jpeg',
						],
						( object ) [
							'name' => 'medium',
							'src' => 'https://sitebuilder.local/wp-content/uploads/2022/10/ing-7Vf1z78McBY-unsplash-300x200.jpeg',
						],
						( object ) [
							'name' => 'medium_large',
							'src' => 'https://sitebuilder.local/wp-content/uploads/2022/10/ing-7Vf1z78McBY-unsplash-768x512.jpeg',
						],
						( object ) [
							'name' => 'large',
							'src' => 'https://sitebuilder.local/wp-content/uploads/2022/10/ing-7Vf1z78McBY-unsplash-1024x683.jpeg',
						],
						( object ) [
							'name' => '1536x1536',
							'src' => 'https://sitebuilder.local/wp-content/uploads/2022/10/ing-7Vf1z78McBY-unsplash-1536x1024.jpeg',
						],
						( object ) [
							'name' => '2048x2048',
							'src' => 'https://sitebuilder.local/wp-content/uploads/2022/10/ing-7Vf1z78McBY-unsplash-2048x1366.jpeg',
						],
					],
				],
			],
		];
	}

	/**
	 * Async action: wme_import_industry_image.
	 *
	 * @param array $image
	 * @param int $attempt
	 * @param int $max_attempts
	 */
	public function actionImportIndustryImageAsync( $image, $attempt = 1, $max_attempts = 3 ) {
		try {
			$this->importSingleIndustryImage( $image );
		} catch ( \Exception $e ) {
			if ( $attempt === $max_attempts ) {
				// Re-throw exception to Action Scheduler for logging.
				throw new Exception( $e->getMessage(), $e->getCode(), $e );
			}

			// Enqueue another attempt.
			as_enqueue_async_action( self::IMPORT_HOOK, [ $image, ++$attempt, $max_attempts ] );
		}
	}

	/**
	 * Import industry image within AS action.
	 *
	 * @param array $image
	 *
	 * @throws Exception
	 *
	 * @todo Justin: I couldn't get the Pool to work when testing; please review and rewrite.
	 */
	protected function importSingleIndustryImage( $image ) {
		global $wp_filesystem;

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . '/wp-admin/includes/file.php';
		}

		WP_Filesystem();

		$metadata_sizes = [];
		$upload_dir     = wp_upload_dir();

		if ( empty( $image ) || ! is_array( $image ) ) {
			throw new Exception( 'No array with image data provided' );
		}

		$image = wp_parse_args( $image, [
			'alt' => '',
		] );

		$largest = [];
		// $pool    = Pool::create();

		foreach ( $image['sizes'] as $size ) {
// 			$pool->add( function () use ( $wp_filesystem, $size, $metadata_sizes, $upload_dir, $largest ) {
// 				$image_request = wp_remote_get( $size['src'] );
//
// 				if ( is_wp_error( $image_request ) ) ) {
// 					throw new Exception( $image_request->get_error_message() );
// 				}
//
// 				$contents = wp_remote_retrieve_body( $image_request );
//
// 				if ( empty( wp_remote_retrieve_body( $image_request ) ) {
// 					throw new Exception( sprintf( 'Unable to read image contents: %s', $size['src'] ) );
// 				}
//
// 				$contents = wp_remote_retrieve_body( $image_request );
// 				$filename = wp_basename( $size['src'] );
// 				$filepath = sprintf( '%s/%s', $upload_dir['path'], $filename );
// 				$written  = $wp_filesystem->put_contents( $filepath, $contents );
//
// 				if ( empty( $written ) ) {
// 					throw new Exception( 'Unable to write image.' );
// 				}
//
// 				if ( '2048x2048' === $size['name'] ) {error_log( '2048x2048' );
// 					$largest = [
// 						'filepath' => $filepath,
// 						'filename' => $filename,
// 					];
// 				}
//
// 				$file_sizes = getimagesize( $filepath );
//
// 				$metadata_sizes[ $size['name'] ] = [
// 					'file'      => $filename,
// 					'width'     => $file_sizes[0],
// 					'height'    => $file_sizes[1],
// 					'mime-type' => $file_sizes['mime'],
// 					'filesize'  => wp_filesize( $filepath ),
// 				];
//
// 			// Catch Pool error and try normally.
// 			} )->catch( function ( Throwable $e ) use ( $wp_filesystem, $size, $metadata_sizes, $upload_dir, $largest ) {
				$image_request = wp_remote_get( $size['src'] );

				if ( is_wp_error( $image_request ) ) {
					throw new Exception( $image_request->get_error_message() );
				}

				$contents = wp_remote_retrieve_body( $image_request );

				if ( empty( $contents ) ) {
					throw new Exception( sprintf( 'Unable to read image contents: %s', $size['src'] ) );
				}

				$filename = wp_basename( $size['src'] );
				$filepath = sprintf( '%s/%s', $upload_dir['path'], $filename );
				$written  = $wp_filesystem->put_contents( $filepath, $contents );

				if ( empty( $written ) ) {
					throw new Exception( 'Unable to write image.' );
				}

				if ( '2048x2048' === $size['name'] ) {error_log( '2048x2048' );
					$largest = [
						'filepath' => $filepath,
						'filename' => $filename,
					];
				}

				$file_sizes = getimagesize( $filepath );

				$metadata_sizes[ $size['name'] ] = [
					'file'      => $filename,
					'width'     => $file_sizes[0],
					'height'    => $file_sizes[1],
					'mime-type' => $file_sizes['mime'],
					'filesize'  => wp_filesize( $filepath ),
				];
			// } );
		}

		// $pool->wait();

		$type    = wp_check_filetype_and_ext( $largest['filepath'], $largest['filename'] );
		$title   = preg_replace( '/\.[^.]+$/', '', $largest['filename'] );
		$content = sprintf( 'Photo by %s', esc_html( $image['photographer'] ) );

		if ( ! empty( $image['photographer_url'] ) ) {
			$content .= sprintf( ' - %s', esc_url( $image['photographer_url'] ) );
		}

		$attachment = [
			'post_mime_type' => $type['type'],
			'guid'           => sprintf( '%s/%s', $upload_dir['url'], $largest['filename'] ),
			'post_title'     => $title,
			'post_content'   => $content,
		];

		$attachment_id = wp_insert_attachment( $attachment, $largest['filepath'] );

		if ( is_wp_error( $attachment_id ) ) {
			throw new Exception( $attachment_id->get_error_message() );
		}

		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		// Don't generate sub-sizes.
		add_filter( 'intermediate_image_sizes_advanced', [ $this, 'return_empty_array' ], 1000 );

		$metadata          = wp_generate_attachment_metadata( $attachment_id, $largest['filepath'] );
		$metadata['sizes'] = $metadata_sizes;

		remove_filter( 'intermediate_image_sizes_advanced', [ $this, 'return_empty_array' ], 1000 );

		wp_update_attachment_metadata( $attachment_id, $metadata );

		if ( empty( $image['alt'] ) ) {
			return;
		}

		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $image['alt'] );
	}

	/**
	 * Used to prevent sub-size generation.
	 *
	 * Preferable to WP's __return_empty_array because it's unique.
	 *
	 * @return array
	 */
	public function return_empty_array() {
		return [];
	}

	/**
	 * Action: wme_create_as_queue_runner.
	 *
	 * Spawn an Action Scheduler queue runner.
	 */
	public function actionCreateActionSchedulerQueueRunner() {
		if ( isset( $_POST['nonce'] ) && wp_verify_nonce( $_POST['nonce'], self::RUNNER_HOOK ) ) {
			ActionScheduler_QueueRunner::instance()->run();
		}

		wp_die();
	}
}
