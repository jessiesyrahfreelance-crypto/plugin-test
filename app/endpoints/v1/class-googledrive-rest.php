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
	 * Credentials option name.
	 *
	 * @var string
	 */
	private $creds_option = 'wpmudev_plugin_tests_auth';

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
	 * Encryption config.
	 */
	private const ENC_METHOD   = 'aes-256-cbc';
	private const CRED_VERSION = 2;

	/**
	 * Default max upload size for this plugin (25MB).
	 */
	private const DEFAULT_MAX_UPLOAD_BYTES = 26214400; // 25 * 1024 * 1024

	/**
	 * Initialize the class.
	 */
	public function init() {
		$this->redirect_uri = home_url( '/wp-json/wpmudev/v1/drive/callback' );
		$this->setup_google_client();

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Derive a symmetric key from WP salts.
	 *
	 * @return string 32-byte binary key.
	 */
	private function get_encryption_key() {
		$material = (string) ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' )
			. ( defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '' )
			. ( defined( 'LOGGED_IN_KEY' ) ? LOGGED_IN_KEY : '' )
			. ( defined( 'NONCE_KEY' ) ? NONCE_KEY : '' );

		return hash( 'sha256', $material, true );
	}

	/**
	 * Encrypt a value.
	 *
	 * @param string $plain Plain text.
	 * @return string Base64 JSON payload or empty string on failure.
	 */
	private function encrypt_secret( $plain ) {
		$plain = (string) $plain;
		if ( '' === $plain ) {
			return '';
		}
		$ivlen  = openssl_cipher_iv_length( self::ENC_METHOD );
		$iv     = random_bytes( $ivlen );
		$cipher = openssl_encrypt( $plain, self::ENC_METHOD, $this->get_encryption_key(), OPENSSL_RAW_DATA, $iv );
		if ( false === $cipher ) {
			return '';
		}
		$payload = array(
			'v' => self::CRED_VERSION,
			'i' => base64_encode( $iv ),
			'c' => base64_encode( $cipher ),
		);

		return base64_encode( wp_json_encode( $payload ) );
	}

	/**
	 * Decrypt an encrypted value.
	 *
	 * @param string $encoded Base64 JSON payload.
	 * @return string Decrypted plain text or empty string.
	 */
	private function decrypt_secret( $encoded ) {
		$encoded = (string) $encoded;
		if ( '' === $encoded ) {
			return '';
		}
		$json = base64_decode( $encoded, true );
		if ( false === $json ) {
			return '';
		}
		$payload = json_decode( $json, true );
		if ( empty( $payload['i'] ) || empty( $payload['c'] ) ) {
			return '';
		}
		$iv     = base64_decode( $payload['i'], true );
		$cipher = base64_decode( $payload['c'], true );
		if ( false === $iv || false === $cipher ) {
			return '';
		}
		$plain = openssl_decrypt( $cipher, self::ENC_METHOD, $this->get_encryption_key(), OPENSSL_RAW_DATA, $iv );

		return ( false === $plain ) ? '' : $plain;
	}

	/**
	 * Setup Google Client.
	 */
	private function setup_google_client() {
		$auth_creds = get_option( $this->creds_option, array() );

		if ( empty( $auth_creds ) || empty( $auth_creds['client_id'] ) ) {
			return;
		}

		$client_id     = (string) $auth_creds['client_id'];
		$client_secret = '';

		// Prefer encrypted secret. Migrate plaintext if present.
		if ( ! empty( $auth_creds['client_secret_enc'] ) ) {
			$client_secret = $this->decrypt_secret( $auth_creds['client_secret_enc'] );
		} elseif ( ! empty( $auth_creds['client_secret'] ) ) {
			$client_secret = (string) $auth_creds['client_secret'];

			// Migrate to encrypted storage.
			$auth_creds['client_secret_enc'] = $this->encrypt_secret( $client_secret );
			unset( $auth_creds['client_secret'] );
			update_option( $this->creds_option, $auth_creds );
		}

		if ( '' === $client_id || '' === $client_secret ) {
			return;
		}

		$this->client = new Google_Client();
		$this->client->setClientId( $client_id );
		$this->client->setClientSecret( $client_secret );
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
				'args'                => array(
					'client_id' => array(
						'type'              => 'string',
						'required'          => true,
						'validate_callback' => function ( $param ) {
							$param = trim( (string) $param );
							// Typical Google OAuth Web client ID format: <alnum-and-dashes>.apps.googleusercontent.com
							return (bool) preg_match( '/^[0-9a-z\-]+\.apps\.googleusercontent\.com$/i', $param );
						},
						'sanitize_callback' => function ( $param ) {
							return trim( (string) $param );
						},
					),
					'client_secret' => array(
						'type'              => 'string',
						'required'          => true,
						'validate_callback' => function ( $param ) {
							$param = trim( (string) $param );
							// Keep this generic; secrets vary in format. Minimum length sanity check.
							return ( is_string( $param ) && strlen( $param ) >= 8 );
						},
						'sanitize_callback' => function ( $param ) {
							// Do NOT over-sanitize secrets; only trim to preserve content.
							return trim( (string) $param );
						},
					),
				),
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

		// List files (Files List API) with validated args.
		register_rest_route(
			'wpmudev/v1/drive',
			'/files',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_files' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'pageSize' => array(
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
						'validate_callback' => function ( $param ) {
							$param = absint( $param );
							return $param >= 1 && $param <= 100;
						},
					),
					'pageToken' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => function ( $param ) {
							return sanitize_text_field( (string) $param );
						},
					),
					'q' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => function ( $param ) {
							// Keep user-provided query text safe; Drive API will handle syntax.
							return sanitize_text_field( (string) $param );
						},
					),
					'parentId' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => function ( $param ) {
							return sanitize_text_field( (string) $param );
						},
						'validate_callback' => function ( $param ) {
							// Drive file IDs typically consist of letters, numbers, dash, underscore.
							return '' === $param || (bool) preg_match( '/^[a-zA-Z0-9\-_]+$/', (string) $param );
						},
					),
				),
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
				'args'                => array(
					'parentId' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => function ( $param ) {
							return sanitize_text_field( (string) $param );
						},
						'validate_callback' => function ( $param ) {
							return '' === $param || (bool) preg_match( '/^[a-zA-Z0-9\-_]+$/', (string) $param );
						},
					),
				),
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
	 * Save Google OAuth credentials (securely).
	 *
	 * @param WP_REST_Request $request Request instance.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_credentials( WP_REST_Request $request ) {
		// The args validators and sanitizers have already run, but re-check defensively.
		$client_id     = trim( (string) $request->get_param( 'client_id' ) );
		$client_secret = trim( (string) $request->get_param( 'client_secret' ) );

		if ( '' === $client_id || '' === $client_secret ) {
			return new WP_Error(
				'missing_params',
				__( 'Client ID and Client Secret are required.', 'wpmudev-plugin-test' ),
				array( 'status' => 400 )
			);
		}

		if ( ! preg_match( '/\.apps\.googleusercontent\.com$/i', $client_id ) ) {
			return new WP_Error(
				'invalid_client_id',
				__( 'The provided Client ID is not valid.', 'wpmudev-plugin-test' ),
				array( 'status' => 400 )
			);
		}

		$encrypted = $this->encrypt_secret( $client_secret );
		if ( '' === $encrypted ) {
			return new WP_Error(
				'encryption_failed',
				__( 'Failed to securely store the client secret.', 'wpmudev-plugin-test' ),
				array( 'status' => 500 )
			);
		}

		$payload = array(
			'version'           => self::CRED_VERSION,
			'client_id'         => $client_id,
			'client_secret_enc' => $encrypted,
		);

		// Prefer add_option with autoload=no on first save; otherwise update_option.
		if ( false === get_option( $this->creds_option, false ) ) {
			add_option( $this->creds_option, $payload, '', 'no' );
		} else {
			update_option( $this->creds_option, $payload );
		}

		// Reinitialize Google Client with new credentials
		$this->setup_google_client();

		return new WP_REST_Response(
			array(
				'success' => true,
				'code'    => 'credentials_saved',
				'message' => __( 'Credentials saved securely.', 'wpmudev-plugin-test' ),
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
	 * Files List API
	 * Fetch Google Drive files with pagination and optional parent filtering.
	 *
	 * Query parameters:
	 * - pageSize (int, 1..100)   : Number of items per page (default 20).
	 * - pageToken (string)       : Token for the next page.
	 * - q (string)               : Custom Drive query (overrides defaults if provided).
	 * - parentId (string)        : Folder ID to list its children.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_files( WP_REST_Request $request ) {
		if ( ! $this->ensure_valid_token() ) {
			return new WP_Error( 'no_access_token', __( 'Not authenticated with Google Drive', 'wpmudev-plugin-test' ), array( 'status' => 401 ) );
		}

		try {
			$page_size = (int) $request->get_param( 'pageSize' );
			if ( $page_size <= 0 ) {
				$page_size = 20;
			}
			if ( $page_size > 100 ) {
				$page_size = 100;
			}

			$page_token = sanitize_text_field( (string) $request->get_param( 'pageToken' ) );
			$user_q     = sanitize_text_field( (string) $request->get_param( 'q' ) );
			$parent_id  = sanitize_text_field( (string) $request->get_param( 'parentId' ) );

			// Build the Drive query:
			$query = '';
			if ( '' !== $user_q ) {
				$query = $user_q;
			} elseif ( '' !== $parent_id && preg_match( '/^[a-zA-Z0-9\-_]+$/', $parent_id ) ) {
				$query = sprintf( '\'%s\' in parents and trashed=false', $parent_id );
			} else {
				$query = 'trashed=false';
			}

			$options = array(
				'pageSize' => $page_size,
				'q'        => $query,
				'fields'   => 'nextPageToken, files(id,name,mimeType,size,modifiedTime,webViewLink,iconLink)',
				'orderBy'  => 'modifiedTime desc',
			);

			if ( ! empty( $page_token ) ) {
				$options['pageToken'] = $page_token;
			}

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
					'isFolder'     => 'application/vnd.google-apps.folder' === $file->getMimeType(),
				);
			}

			return new WP_REST_Response(
				array(
					'success'       => true,
					'files'         => $file_list,
					'nextPageToken' => $results->getNextPageToken(),
				),
				200
			);

		} catch ( \Google\Service\Exception $ge ) {
			$message = $ge->getMessage();
			return new WP_Error( 'google_api_error', $message, array( 'status' => 502 ) );
		} catch ( \Exception $e ) {
			return new WP_Error( 'api_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Get plugin-allowed mime types.
	 *
	 * Filter: wpmudev_drive_allowed_mime_types to customize.
	 *
	 * @return array Allowed mime types list.
	 */
	private function get_allowed_mime_types() {
		$allowed = array(
			// Images
			'image/jpeg',
			'image/png',
			'image/gif',
			'image/webp',
			'image/svg+xml',
			// Documents
			'application/pdf',
			'text/plain',
			'text/csv',
			'application/msword',
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'application/vnd.ms-excel',
			'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'application/vnd.ms-powerpoint',
			'application/vnd.openxmlformats-officedocument.presentationml.presentation',
			'application/rtf',
			'application/json',
			// Archives
			'application/zip',
			'application/x-zip-compressed',
			// Media
			'video/mp4',
			'audio/mpeg',
		);

		/**
		 * Allow customization of allowed mime types for Drive uploads.
		 *
		 * @param array $allowed
		 */
		$allowed = apply_filters( 'wpmudev_drive_allowed_mime_types', $allowed );

		// Ensure array of unique, non-empty strings.
		return array_values( array_filter( array_unique( array_map( 'strval', (array) $allowed ) ) ) );
	}

	/**
	 * Compute maximum allowed upload size in bytes.
	 *
	 * Uses the minimum of WordPress/site/server limit and plugin's default cap.
	 *
	 * @return int
	 */
	private function get_max_upload_bytes() {
		$wp_limit = (int) wp_max_upload_size(); // honors upload_max_filesize/post_max_size
		$plugin_cap = (int) apply_filters( 'wpmudev_drive_max_upload_bytes', self::DEFAULT_MAX_UPLOAD_BYTES );

		$limit = $wp_limit > 0 ? min( $wp_limit, $plugin_cap ) : $plugin_cap;

		return max( 1, (int) $limit );
	}

	/**
	 * Map PHP upload error code to human-friendly message.
	 *
	 * @param int $code
	 * @return string
	 */
	private function map_upload_error( $code ) {
		switch ( (int) $code ) {
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				return __( 'The uploaded file exceeds the maximum allowed size.', 'wpmudev-plugin-test' );
			case UPLOAD_ERR_PARTIAL:
				return __( 'The uploaded file was only partially uploaded.', 'wpmudev-plugin-test' );
			case UPLOAD_ERR_NO_FILE:
				return __( 'No file was uploaded.', 'wpmudev-plugin-test' );
			case UPLOAD_ERR_NO_TMP_DIR:
				return __( 'Missing a temporary folder on the server.', 'wpmudev-plugin-test' );
			case UPLOAD_ERR_CANT_WRITE:
				return __( 'Failed to write file to disk.', 'wpmudev-plugin-test' );
			case UPLOAD_ERR_EXTENSION:
				return __( 'A PHP extension stopped the file upload.', 'wpmudev-plugin-test' );
			default:
				return __( 'File upload error.', 'wpmudev-plugin-test' );
		}
	}

	/**
	 * Upload file to Google Drive.
	 *
	 * Accepts multipart/form-data with:
	 * - file: the uploaded file
	 * - parentId (optional): Drive folder ID to upload into
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

		// Handle PHP upload errors explicitly.
		if ( (int) $file['error'] !== UPLOAD_ERR_OK ) {
			return new WP_Error( 'upload_error', $this->map_upload_error( (int) $file['error'] ), array( 'status' => 400 ) );
		}

		$tmp_path = (string) $file['tmp_name'];
		$orig_name = (string) $file['name'];
		$size      = (int) $file['size'];
		$client_mime = (string) $file['type'];

		// Basic security checks.
		if ( empty( $tmp_path ) || ! is_uploaded_file( $tmp_path ) || ! file_exists( $tmp_path ) ) {
			return new WP_Error( 'invalid_upload', __( 'Invalid uploaded file.', 'wpmudev-plugin-test' ), array( 'status' => 400 ) );
		}

		// Validate size against limits.
		$max_bytes = $this->get_max_upload_bytes();
		if ( $size <= 0 || $size > $max_bytes ) {
			return new WP_Error(
				'file_too_large',
				sprintf(
					/* translators: %s: human readable size */
					__( 'File exceeds the maximum allowed size of %s.', 'wpmudev-plugin-test' ),
					size_format( $max_bytes )
				),
				array( 'status' => 400 )
			);
		}

		// Sanitize file name.
		$sanitized_name = sanitize_file_name( $orig_name );

		// Validate file type using WP and finfo.
		$wp_type = wp_check_filetype_and_ext( $tmp_path, $sanitized_name );
		$ext     = isset( $wp_type['ext'] ) ? (string) $wp_type['ext'] : '';
		$detected_mime = isset( $wp_type['type'] ) ? (string) $wp_type['type'] : '';

		// Use finfo as a secondary check when available.
		if ( function_exists( 'finfo_open' ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );
			if ( $finfo ) {
				$finfo_mime = finfo_file( $finfo, $tmp_path );
				if ( is_string( $finfo_mime ) && '' !== $finfo_mime ) {
					$detected_mime = $finfo_mime;
				}
				finfo_close( $finfo );
			}
		}

		// Final mime to use: prefer detected_mime, fallback to client provided.
		$final_mime = $detected_mime ?: ( $client_mime ?: 'application/octet-stream' );

		$allowed_mimes = $this->get_allowed_mime_types();

		// Disallow clearly dangerous types regardless of allowed list.
		$blocked_ext = array( 'php', 'phtml', 'phar', 'cgi', 'pl', 'exe', 'sh', 'bat', 'cmd', 'js', 'jsp', 'asp', 'aspx' );
		if ( in_array( strtolower( (string) $ext ), $blocked_ext, true ) ) {
			return new WP_Error( 'forbidden_type', __( 'This file type is not allowed.', 'wpmudev-plugin-test' ), array( 'status' => 400 ) );
		}

		// If we have an allow-list, enforce it (but allow unknown types to be filtered in).
		if ( ! empty( $allowed_mimes ) && ! in_array( $final_mime, $allowed_mimes, true ) ) {
			return new WP_Error(
				'unsupported_type',
				sprintf(
					/* translators: 1: mime type */
					__( 'Unsupported file type: %s', 'wpmudev-plugin-test' ),
					esc_html( $final_mime )
				),
				array( 'status' => 400 )
			);
		}

		// Optional: parent folder.
		$parent_id = sanitize_text_field( (string) $request->get_param( 'parentId' ) );
		if ( '' !== $parent_id && ! preg_match( '/^[a-zA-Z0-9\-_]+$/', $parent_id ) ) {
			return new WP_Error( 'invalid_parent', __( 'Invalid parent folder ID.', 'wpmudev-plugin-test' ), array( 'status' => 400 ) );
		}

		try {
			// Prepare Drive file metadata.
			$drive_file = new Google_Service_Drive_DriveFile();
			$drive_file->setName( $sanitized_name );
			if ( '' !== $parent_id ) {
				$drive_file->setParents( array( $parent_id ) );
			}

			// Read content and upload using multipart.
			$contents = file_get_contents( $tmp_path );
			if ( false === $contents ) {
				return new WP_Error( 'read_failed', __( 'Failed to read uploaded file.', 'wpmudev-plugin-test' ), array( 'status' => 500 ) );
			}

			$result = $this->drive_service->files->create(
				$drive_file,
				array(
					'data'       => $contents,
					'mimeType'   => $final_mime,
					'uploadType' => 'multipart',
					'fields'     => 'id,name,mimeType,size,webViewLink,parents',
				)
			);

			// Best-effort cleanup (PHP usually cleans tmp automatically).
			@unlink( $tmp_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			// Build response with completion status.
			$total_bytes = (int) $size;
			$uploaded_bytes = $total_bytes; // As this is a single request, it's fully uploaded on success.

			return new WP_REST_Response(
				array(
					'success'         => true,
					'message'         => __( 'File uploaded successfully.', 'wpmudev-plugin-test' ),
					'progress'        => 100,
					'bytesUploaded'   => $uploaded_bytes,
					'totalBytes'      => $total_bytes,
					'file'            => array(
						'id'          => $result->getId(),
						'name'        => $result->getName(),
						'mimeType'    => $result->getMimeType(),
						'size'        => $result->getSize(),
						'webViewLink' => $result->getWebViewLink(),
						'parents'     => method_exists( $result, 'getParents' ) ? $result->getParents() : array(),
					),
				),
				200
			);

		} catch ( \Google\Service\Exception $ge ) {
			// Try to provide clearer error context from Google API.
			$message = $ge->getMessage();
			$code    = $ge->getCode() ?: 500;

			// Best-effort cleanup.
			if ( file_exists( $tmp_path ) ) {
				@unlink( $tmp_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}

			return new WP_Error( 'google_upload_error', $message, array( 'status' => ( $code >= 400 && $code < 600 ) ? $code : 502 ) );
		} catch ( \Exception $e ) {
			// Cleanup temp file.
			if ( file_exists( $tmp_path ) ) {
				@unlink( $tmp_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}

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
				),
				200
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
				),
				200
			);

		} catch ( \Exception $e ) {
			return new WP_Error( 'create_failed', $e->getMessage(), array( 'status' => 500 ) );
		}
	}
}