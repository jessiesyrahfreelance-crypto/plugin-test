<?php
/**
 * Google Drive API endpoints using Google Client Library.
 *
 * @link          https://wpmudev.com/
 * @since         1.0.0
 *
 * @author        WPMUDEV (https://wpmudev.com)
 * @package       WPMUDEV\PluginTest
 *
 * @copyright (c) 2025, Incsub (http://incsub.com)
 */

namespace WPMUDEV\PluginTest\Endpoints\V1;

// Abort if called directly.
defined( 'WPINC' ) || die;

use WPMUDEV\PluginTest\Base;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Google_Client;
use Google_Service_Drive;
use Google_Service_Drive_DriveFile;

class Drive_API extends Base {

	/**
	 * Google Client instance.
	 *
	 * @var Google_Client
	 */
	private $client;

	/**
	 * Google Drive service.
	 *
	 * @var Google_Service_Drive
	 */
	private $drive_service;

	/**
	 * OAuth redirect URI.
	 *
	 * @var string
	 */
	private $redirect_uri;

	/**
	 * Google Drive API scopes.
	 *
	 * @var array
	 */
	private $scopes = array(
		Google_Service_Drive::DRIVE_FILE,
		Google_Service_Drive::DRIVE_READONLY,
	);

	/**
	 * Initialize the class.
	 */
	public function init() {
		$this->redirect_uri = home_url( '/wp-json/wpmudev/v1/drive/callback' );
		$this->setup_google_client();

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Setup Google Client.
	 */
	private function setup_google_client() {
		$auth_creds = get_option( 'wpmudev_plugin_tests_auth', array() );

		if ( empty( $auth_creds['client_id'] ) || empty( $auth_creds['client_secret'] ) ) {
			return;
		}

		$this->client = new Google_Client();
		$this->client->setClientId( $auth_creds['client_id'] );
		$this->client->setClientSecret( $auth_creds['client_secret'] );
		$this->client->setRedirectUri( $this->redirect_uri );
		$this->client->setScopes( $this->scopes );
		$this->client->setAccessType( 'offline' );
		$this->client->setPrompt( 'consent' );

		// Set access token if available
		$access_token = get_option( 'wpmudev_drive_access_token', '' );
		if ( ! empty( $access_token ) ) {
			$this->client->setAccessToken( $access_token );
		}

		$this->drive_service = new Google_Service_Drive( $this->client );
	}

	/**
	 * Register REST API routes.
	 */
	public function register_routes() {
		// Save credentials endpoint
		register_rest_route(
			'wpmudev/v1/drive',
			'/save-credentials',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'save_credentials' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		// Authentication endpoint: returns Google OAuth consent screen URL.
		register_rest_route(
			'wpmudev/v1/drive',
			'/auth',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'start_auth' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		// OAuth callback
		register_rest_route(
			'wpmudev/v1/drive',
			'/callback',
			array(
				'methods'  => 'GET',
				'callback' => array( $this, 'handle_callback' ),
			)
		);

		// List files
		register_rest_route(
			'wpmudev/v1/drive',
			'/files',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_files' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		// Upload file
		register_rest_route(
			'wpmudev/v1/drive',
			'/upload',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'upload_file' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		// Download file
		register_rest_route(
			'wpmudev/v1/drive',
			'/download',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'download_file' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		// Create folder
		register_rest_route(
			'wpmudev/v1/drive',
			'/create-folder',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_folder' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	/**
	 * Save Google OAuth credentials.
	 */
	public function save_credentials( WP_REST_Request $request ) {
		$client_id     = sanitize_text_field( (string) $request->get_param( 'client_id' ) );
		$client_secret = sanitize_text_field( (string) $request->get_param( 'client_secret' ) );

		if ( '' === $client_id || '' === $client_secret ) {
			return new WP_Error( 'missing_params', __( 'Client ID and Client Secret are required.', 'wpmudev-plugin-test' ), array( 'status' => 400 ) );
		}

		update_option(
			'wpmudev_plugin_tests_auth',
			array(
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
			)
		);

		// Reinitialize Google Client with new credentials
		$this->setup_google_client();

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Credentials saved.', 'wpmudev-plugin-test' ),
			),
			200
		);
	}

	/**
	 * Start Google OAuth flow: return consent screen URL with CSRF state.
	 */
	public function start_auth( WP_REST_Request $request ) {
		if ( ! $this->client ) {
			return new WP_Error( 'missing_credentials', __( 'Google OAuth credentials not configured', 'wpmudev-plugin-test' ), array( 'status' => 400 ) );
		}

		if ( ! class_exists( '\Google_Client' ) ) {
			return new WP_Error( 'missing_library', __( 'Google API PHP Client library is not available.', 'wpmudev-plugin-test' ), array( 'status' => 500 ) );
		}

		try {
			$state = wp_generate_uuid4();
			// Store state for 10 minutes to validate on callback.
			set_transient( 'wpmudev_drive_oauth_state_' . $state, 1, MINUTE_IN_SECONDS * 10 );

			$this->client->setState( $state );

			$auth_url = $this->client->createAuthUrl();

			return new WP_REST_Response(
				array(
					'success'  => true,
					'auth_url' => $auth_url,
				),
				200
			);
		} catch ( \Exception $e ) {
			return new WP_Error( 'auth_init_failed', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Handle OAuth callback.
	 */
	public function handle_callback( WP_REST_Request $request ) {
		$code  = (string) $request->get_param( 'code' );
		$state = (string) $request->get_param( 'state' );

		$redirect_back = admin_url( 'admin.php?page=wpmudev_plugintest_drive' );

		if ( empty( $code ) ) {
			wp_safe_redirect( add_query_arg( array( 'auth' => 'failed', 'error' => rawurlencode( __( 'Authorization code not received', 'wpmudev-plugin-test' ) ) ), $redirect_back ) );
			exit;
		}

		// Validate CSRF state
		if ( empty( $state ) || 1 !== (int) get_transient( 'wpmudev_drive_oauth_state_' . $state ) ) {
			wp_safe_redirect( add_query_arg( array( 'auth' => 'failed', 'error' => rawurlencode( __( 'Invalid authorization state', 'wpmudev-plugin-test' ) ) ), $redirect_back ) );
			exit;
		}
		// Clear state after one use
		delete_transient( 'wpmudev_drive_oauth_state_' . $state );

		if ( ! $this->client ) {
			wp_safe_redirect( add_query_arg( array( 'auth' => 'failed', 'error' => rawurlencode( __( 'OAuth client is not initialized', 'wpmudev-plugin-test' ) ) ), $redirect_back ) );
			exit;
		}

		try {
			$token = $this->client->fetchAccessTokenWithAuthCode( $code );

			if ( isset( $token['error'] ) ) {
				$message = isset( $token['error_description'] ) ? $token['error_description'] : $token['error'];
				wp_safe_redirect( add_query_arg( array( 'auth' => 'failed', 'error' => rawurlencode( $message ) ), $redirect_back ) );
				exit;
			}

			// Persist access token and refresh token
			update_option( 'wpmudev_drive_access_token', $token );

			if ( ! empty( $token['refresh_token'] ) ) {
				update_option( 'wpmudev_drive_refresh_token', $token['refresh_token'] );
			} else {
				// Keep any existing refresh token if Google didn't return a new one.
				$existing_refresh = get_option( 'wpmudev_drive_refresh_token' );
				if ( $existing_refresh ) {
					$token['refresh_token'] = $existing_refresh;
					update_option( 'wpmudev_drive_access_token', $token );
				}
			}

			// Calculate expiry timestamp if possible
			$created    = isset( $token['created'] ) ? (int) $token['created'] : time();
			$expires_in = isset( $token['expires_in'] ) ? (int) $token['expires_in'] : 0;
			$expires_at = $expires_in > 0 ? $created + $expires_in : ( time() + HOUR_IN_SECONDS );

			update_option( 'wpmudev_drive_token_expires', $expires_at );

			// Redirect back to admin page with success flag
			wp_safe_redirect( add_query_arg( array( 'auth' => 'success' ), $redirect_back ) );
			exit;

		} catch ( \Exception $e ) {
			wp_safe_redirect( add_query_arg( array( 'auth' => 'failed', 'error' => rawurlencode( $e->getMessage() ) ), $redirect_back ) );
			exit;
		}
	}

	/**
	 * Ensure we have a valid access token.
	 */
	private function ensure_valid_token() {
		if ( ! $this->client ) {
			return false;
		}

		// If we stored the entire token array, set it back into the client before checking.
		$stored_token = get_option( 'wpmudev_drive_access_token', array() );
		if ( ! empty( $stored_token ) ) {
			$this->client->setAccessToken( $stored_token );
		}

		if ( $this->client->isAccessTokenExpired() ) {
			$refresh_token = get_option( 'wpmudev_drive_refresh_token' );

			if ( empty( $refresh_token ) ) {
				return false;
			}

			try {
				$new_token = $this->client->fetchAccessTokenWithRefreshToken( $refresh_token );

				if ( isset( $new_token['error'] ) ) {
					return false;
				}

				// Merge old token with new parts to preserve refresh_token when not returned.
				if ( empty( $new_token['refresh_token'] ) ) {
					$new_token['refresh_token'] = $refresh_token;
				}

				update_option( 'wpmudev_drive_access_token', $new_token );

				$created    = isset( $new_token['created'] ) ? (int) $new_token['created'] : time();
				$expires_in = isset( $new_token['expires_in'] ) ? (int) $new_token['expires_in'] : 0;
				$expires_at = $expires_in > 0 ? $created + $expires_in : ( time() + HOUR_IN_SECONDS );

				update_option( 'wpmudev_drive_token_expires', $expires_at );

				return true;
			} catch ( \Exception $e ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * List files in Google Drive.
	 */
	public function list_files() {
		if ( ! $this->ensure_valid_token() ) {
			return new WP_Error( 'no_access_token', __( 'Not authenticated with Google Drive', 'wpmudev-plugin-test' ), array( 'status' => 401 ) );
		}

		try {
			$page_size = 20; // This should be an input parameter not static value 20.
			$query     = 'trashed=false'; // This should be an input parameter not static value.

			$options = array(
				'pageSize' => $page_size,
				'q'        => $query,
				'fields'   => 'files(id,name,mimeType,size,modifiedTime,webViewLink)',
			);

			$results = $this->drive_service->files->listFiles( $options );
			$files   = $results->getFiles();

			$file_list = array();
			foreach ( $files as $file ) {
				$file_list[] = array(
					'id'           => $file->getId(),
					'name'         => $file->getName(),
					'mimeType'     => $file->getMimeType(),
					'size'         => $file->getSize(),
					'modifiedTime' => $file->getModifiedTime(),
					'webViewLink'  => $file->getWebViewLink(),
				);
			}

			return new WP_REST_Response(
				array(
					'success' => true,
					'files'   => $file_list,
				)
			);

		} catch ( \Exception $e ) {
			return new WP_Error( 'api_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Upload file to Google Drive.
	 */
	public function upload_file( WP_REST_Request $request ) {
		if ( ! $this->ensure_valid_token() ) {
			return new WP_Error( 'no_access_token', __( 'Not authenticated with Google Drive', 'wpmudev-plugin-test' ), array( 'status' => 401 ) );
		}

		$files = $request->get_file_params();

		if ( empty( $files['file'] ) ) {
			return new WP_Error( 'no_file', __( 'No file provided', 'wpmudev-plugin-test' ), array( 'status' => 400 ) );
		}

		$file = $files['file'];

		if ( $file['error'] !== UPLOAD_ERR_OK ) {
			return new WP_Error( 'upload_error', __( 'File upload error', 'wpmudev-plugin-test' ), array( 'status' => 400 ) );
		}

		try {
			// Create file metadata
			$drive_file = new Google_Service_Drive_DriveFile();
			$drive_file->setName( $file['name'] );

			// Upload file
			$result = $this->drive_service->files->create(
				$drive_file,
				array(
					'data'       => file_get_contents( $file['tmp_name'] ),
					'mimeType'   => $file['type'],
					'uploadType' => 'multipart',
					'fields'     => 'id,name,mimeType,size,webViewLink',
				)
			);

			return new WP_REST_Response(
				array(
					'success' => true,
					'file'    => array(
						'id'          => $result->getId(),
						'name'        => $result->getName(),
						'mimeType'    => $result->getMimeType(),
						'size'        => $result->getSize(),
						'webViewLink' => $result->getWebViewLink(),
					),
				)
			);

		} catch ( \Exception $e ) {
			return new WP_Error( 'upload_failed', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Download file from Google Drive.
	 */
	public function download_file( WP_REST_Request $request ) {
		if ( ! $this->ensure_valid_token() ) {
			return new WP_Error( 'no_access_token', __( 'Not authenticated with Google Drive', 'wpmudev-plugin-test' ), array( 'status' => 401 ) );
		}

		$file_id = $request->get_param( 'file_id' );

		if ( empty( $file_id ) ) {
			return new WP_Error( 'missing_file_id', __( 'File ID is required', 'wpmudev-plugin-test' ), array( 'status' => 400 ) );
		}

		try {
			// Get file metadata
			$file = $this->drive_service->files->get(
				$file_id,
				array(
					'fields' => 'id,name,mimeType,size',
				)
			);

			// Download file content
			$response = $this->drive_service->files->get(
				$file_id,
				array(
					'alt' => 'media',
				)
			);

			$content = $response->getBody()->getContents();

			// Return file content as base64 for JSON response
			return new WP_REST_Response(
				array(
					'success'  => true,
					'content'  => base64_encode( $content ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
					'filename' => $file->getName(),
					'mimeType' => $file->getMimeType(),
				)
			);

		} catch ( \Exception $e ) {
			return new WP_Error( 'download_failed', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Create folder in Google Drive.
	 */
	public function create_folder( WP_REST_Request $request ) {
		if ( ! $this->ensure_valid_token() ) {
			return new WP_Error( 'no_access_token', __( 'Not authenticated with Google Drive', 'wpmudev-plugin-test' ), array( 'status' => 401 ) );
		}

		$name = $request->get_param( 'name' );

		if ( empty( $name ) ) {
			return new WP_Error( 'missing_name', __( 'Folder name is required', 'wpmudev-plugin-test' ), array( 'status' => 400 ) );
		}

		try {
			$folder = new Google_Service_Drive_DriveFile();
			$folder->setName( sanitize_text_field( $name ) );
			$folder->setMimeType( 'application/vnd.google-apps.folder' );

			$result = $this->drive_service->files->create(
				$folder,
				array(
					'fields' => 'id,name,mimeType,webViewLink',
				)
			);

			return new WP_REST_Response(
				array(
					'success' => true,
					'folder'  => array(
						'id'          => $result->getId(),
						'name'        => $result->getName(),
						'mimeType'    => $result->getMimeType(),
						'webViewLink' => $result->getWebViewLink(),
					),
				)
			);

		} catch ( \Exception $e ) {
			return new WP_Error( 'create_failed', $e->getMessage(), array( 'status' => 500 ) );
		}
	}
}