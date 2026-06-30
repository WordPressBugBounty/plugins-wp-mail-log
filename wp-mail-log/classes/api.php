<?php
namespace WML\Classes;

defined( 'ABSPATH' ) || exit;

use WML\Classes\Settings;

use WP_REST_Controller;
use WP_REST_Server;
use WP_Query;
use WP_REST_Request;
use WP_Error;
use WP_REST_Response;

/**
 * Plugin API Endpoints
 *
 * This class is to manage plugin api endpoints
 *
 * @extends WP_REST_Controller
 *
*/
class API extends WP_REST_Controller {


	protected $namespace = 'wml/v1';
	/**
	 * The constructor of class. Automatically call when class object create
	 *
	 * @since 0.3
	 * @return void
	 * @access public
	 *
	 */
	public function __construct() {
	}

	/**
	 * This function is called on rest_api_init action in bootstrap file.
	 *
	 * This function will register the rest api endpoints.
	 *
	 * @since 0.3
	 * @return void
	 * @access public
	 *
	 */

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/wml_logs',
			// Get Log -> done
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'view_log' ],
					'permission_callback' => [ $this, 'check_permission' ],
				],
				// // Delete Log -> done
				// [
				// 	'methods'             => WP_REST_Server::DELETABLE,
				// 	'callback'            => [ $this, 'delete_log' ],
				// 	'permission_callback' => [ $this, 'check_permission' ],
				// ],
			]
		);
		register_rest_route(
			$this->namespace,
			'/wml_logs/delete',
			// Get Log -> done
			[
				// Delete Log -> done
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'delete_log' ],
					'permission_callback' => [ $this, 'check_permission' ],
				],
			]
		);
		register_rest_route(
			$this->namespace,
			'/settings',
			// Save Settings done
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'save_settings' ],
					'permission_callback' => [ $this, 'check_permission' ],
				],
			]
		);
		register_rest_route(
			$this->namespace,
			'/wml_logs/send_mail',
			// Save Settings done
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'send_email' ],
					'permission_callback' => [ $this, 'check_permission' ],
				],
			]
		);
	}

	/**
	 * This function return the JSON params to array from request.
	 *
	 * @since 0.3
	 * @param object $reuqest WP Rest Request
	 * @return array
	 * @access private
	 *
	 */

	private function get_params( $request ) {
		return $request->get_json_params();
	}
	/**
	 * This function is called on wml_log api endpoint with creatable method.
	 *
	 * This function return the result of logs based on params.
	 *
	 * @since 0.3
	 * @param \WP_REST_Request $request Full data about the request.
	 * @return \WP_REST_Response Response object log result based on params
	 * @access public
	 *
	 */
	public function view_log( WP_REST_Request $request ) {
		global $wpdb;
		$params = $this->get_params( $request );
		$table_name = $wpdb->prefix . 'wml_entries';

		$query_cols  = [ 'id', 'to_email', 'subject', 'message', 'headers', 'attachments', "DATE_FORMAT(sent_date, '%Y/%m/%d %H:%i:%S') as sent_date", 'attachments_file as files' ];
		$entry_query = 'SELECT distinct ' . implode( ',', $query_cols ) . ' FROM ' . $table_name;
		$where[]     = '1 = 1';

		if ( empty( $params['startDate'] ) ) {
			$params['startDate'] = date( 'Y-m-d H:i:s', strtotime( '-30 days' ) );
		}
		if ( empty( $params['endDate'] ) ) {
			$params['endDate'] = date( 'Y-m-d H:i:s' );
		}
		if ( $params['startDate'] !== '' && $params['startDate'] !== null ) {
			$orignalStartDateTS  = strtotime( sanitize_text_field( $params['startDate'] ) );
			$params['startDate'] = date( 'Y-m-d', $orignalStartDateTS );
		}
		if ( $params['endDate'] !== '' && $params['endDate'] !== null ) {
			$orignalEndDateTS  = strtotime( sanitize_text_field( $params['endDate'] ) );
			$params['endDate'] = date( 'Y-m-d', $orignalEndDateTS );
		}
		
		$page_size   = absint( $params['pageSize'] );
		$page_offset = absint( $params['pageIndex'] ) * $page_size;

		[ $filter_sql, $filter_values ] = $this->get_filter_params( $params );

		$entry_query = $wpdb->prepare(
			"SELECT DISTINCT id, to_email, subject, message, headers, attachments, DATE_FORMAT(sent_date, '%%Y/%%m/%%d %%H:%%i:%%S') as sent_date, attachments_file as files FROM %i WHERE 1 = 1 AND DATE_FORMAT(sent_date,GET_FORMAT(DATE,'JIS')) >= %s AND DATE_FORMAT(sent_date,GET_FORMAT(DATE,'JIS')) <= %s {$filter_sql} ORDER BY id DESC LIMIT %d OFFSET %d",
			array_merge( [ $table_name, $params['startDate'], $params['endDate'] ], $filter_values, [ $page_size, $page_offset ] )
		);
		
		
		$sql = $wpdb->get_results( $entry_query );
		
		$cols = [];

		foreach ( $wpdb->get_col( $wpdb->prepare( 'DESC %i', $table_name ), 0 ) as $column_name ) {
			$cols[] = $column_name;
		}

		$entry_count_query = $wpdb->prepare(
			"SELECT count(id) FROM %i WHERE 1 = 1 AND DATE_FORMAT(sent_date,GET_FORMAT(DATE,'JIS')) >= %s AND DATE_FORMAT(sent_date,GET_FORMAT(DATE,'JIS')) <= %s {$filter_sql}",
			array_merge( [ $table_name, $params['startDate'], $params['endDate'] ], $filter_values )
		);
		
		//$entry_count_query = 'SELECT count(id) from ' . $table_name . ' WHERE 1 = 1 ' . $this->get_filter_params($params);
		
		$entry_result = $wpdb->get_var( $entry_count_query );
		$rowcount     = $wpdb->num_rows;
		$columns      = [ 'id', 'to_email', 'subject', 'message', 'headers', 'sent_date', 'files' ];

		$upload_info = wp_upload_dir();
		foreach ( $sql as $key => $row ) {
			if($row->files !== '' && $row->files !== null){
				$files = explode(',',$row->files);
				$attachments = [];
				if($files){
					foreach ($files as $key => $value) {
						$url = $upload_info['baseurl'] . $value;
						$fileExist = file_exists( $upload_info['basedir'] . $value );
						$fileName = substr($value,strripos($value, '/') + 1, strlen($value));
						$attachments[$key] = [
							'name'=> $fileName,
							'path'=> $value,
							'exist'=> $fileExist,
						];	
					}
					$row->files = implode(' ', $files) ;
					$row->dataFile = $attachments;
				}
			}
			
			// Issue with wp forms data so commented
			// $formatedTag  = wp_kses( $row->message, $this->wml_kses_allowed_html( 'post' ) );
			// $row->message = $formatedTag;
		}

		// sanitize $sql recursively
		
		$sql = $this->sanitize_data( $sql );

		$res = [
			'columns'   => $columns,
			'data'      => $sql,
			'totalRows' => $entry_result,
			'rowCount'  => $rowcount,
		];
		return rest_ensure_response( $res );
	}

	public function get_filter_params( $params ) {
      global $wpdb;
      $clauses = [];
      $values  = [];

      $allowed_keys      = [ 'to_email', 'subject', 'message' ];
      $allowed_operators = [ 'LIKE', 'NOT LIKE', '=', '!=' ];
      $allowed_relations = [ 'AND', 'OR' ];

      $filter = $params['filter'] ?? [];
      if ( $filter ) {
          foreach ( $filter as $value ) {
              if ( empty( $value['key'] ) ) {
                  continue;
              }
              if ( ! in_array( $value['key'], $allowed_keys, true ) ) {
                  continue;
              }
              if ( ! in_array( $value['operator'], $allowed_operators, true ) ) {
                  continue;
              }

              $col      = $value['key'];
              $operator = $value['operator'];

              if ( $operator === 'LIKE' || $operator === 'NOT LIKE' ) {
                  $clauses[] = "( {$col} {$operator} %s )";
                  $values[]  = '%' . $wpdb->esc_like( $value['value'] ) . '%';
              } else {
                  $clauses[] = "( {$col} {$operator} %s )";
                  $values[]  = $value['value'];
              }
          }

          if ( $clauses ) {
              $relation = in_array( $params['filterRelation'] ?? '', $allowed_relations, true )
                  ? $params['filterRelation']
                  : 'AND';

              return [ ' AND ' . implode( " {$relation} ", $clauses ), $values ];
          }
      }

      return [ '', [] ];
  }

	/**
	 * This function is called on wml_log api endpoint with deletable method.
	 *
	 * This function return wp rest response on basis of result of delete.
	 *
	 * @since 0.6
	 * @param int $id id to get data
	 * @return $results query results
	 * @access public
	 *
	 */
	public function get_data_by_id( $id ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'wml_entries';

		$query_cols  = [ 'id', 'subject', 'message', 'headers', 'attachments', "DATE_FORMAT(sent_date, '%Y/%m/%d %H:%i:%S') as sent_date", "attachments_file as files" ];

		// write query using wpdb->prepare 
		$entry_query = $wpdb->prepare(
			"SELECT DISTINCT id, subject, message, headers, attachments, DATE_FORMAT(sent_date, '%%Y/%%m/%%d %%H:%%i:%%S') as sent_date ,attachments_file as files  FROM  %i  WHERE id=%d",
			$table_name,
			$id
		);
		// $entry_query = 'SELECT distinct ' . implode( ',', $query_cols ) . ' FROM ' . $table_name . ' WHERE id=' . $id;
		// var_dump($entry_query);
		// die('dfaf');
		$result = $wpdb->get_results( $entry_query );

		return $result[0] ?? null;
	}
	/**
	 * This function is called on wml_log api endpoint with deletable method.
	 *
	 * This function return wp rest response on basis of result of delete.
	 *
	 * @since 0.3
	 * @param \WP_REST_Request $request Full data about the request.
	 * @return \WP_REST_Response Response object on success
	 * @access public
	 *
	 */
	public function delete_log( WP_REST_Request $request ) {

		global $wpdb;
		$ids     = $this->get_params( $request );
		$message = [];

		if ( ! is_array( $ids ) || empty( $ids ) ) {
			return new WP_Error( 'invalid_ids', __( 'No IDs provided.', 'wpv-wml' ), [ 'status' => 400 ] );
		}
		$ids = array_map( 'absint', $ids );
		$ids = array_filter( $ids );
		if ( empty( $ids ) ) {
			return new WP_Error( 'invalid_ids', __( 'Invalid IDs.', 'wpv-wml' ), [ 'status' => 400 ] );
		}

		$table_name = $wpdb->prefix . 'wml_entries';
		$idsPlaceholder = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$deleteRow = $wpdb->prepare(
			"DELETE FROM %i WHERE id IN ({$idsPlaceholder})",
			array_merge( [ $table_name ], $ids )
		);

		$dl1 = $wpdb->query( $deleteRow );
		if ( $dl1 === false ) {
			$message['status']  = 'failed';
			$message['message'] = __( 'Database error', 'wpv-wml' );
		} else {
			$message['status']  = 'passed';
			$message['message'] = sprintf( _n( '%d entry deleted', '%d entries deleted', $dl1, 'wpv-wml' ), $dl1 );
		}
		return rest_ensure_response( $message );
	}

	/**
	 * This function is called on wml_log api endpoint with deletable method.
	 *
	 * This function return wp rest response on basis of result of delete.
	 *
	 * @since 0.3
	 * @param string $context
	 * @return array allowed html tags in content
	 * @access protected
	 *
	 */
	protected function wml_kses_allowed_html( $context = 'post' ) {

		$allowed_tags = wp_kses_allowed_html( $context );

		$allowed_tags['link'] = [
			'rel'   => true,
			'href'  => true,
			'type'  => true,
			'media' => true,
		];

		return $allowed_tags;
	}
	/**
	 * Check if the user has the permission to manage posts
	 * @access public
	 * @since 0.3
	 * @return bool|\WP_Error True on has permission, or WP_Error object on failure.
	 */
	public function check_permission() {
		// Restrict endpoint to only users who have the edit_posts capability.
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'rest_forbidden', esc_html__( 'OMG you can not view private data.', 'wpv-wml' ), [ 'status' => 401 ] );
		}

		// This is a black-listing approach. You could alternatively do this via white-listing, by returning false here and changing the permissions check.
		return true;
	}
	/**
	 * This function is to save settings
	 * @access public
	 * @since 0.3
	 * @param \WP_REST_Request $request Full data about the request.
	 * @return \WP_REST_Response Response object on success
	 */
	public function save_settings( WP_REST_Request $request ) {
		$params   = $this->get_params( $request );
		$callback = $params['callback'] ?? '';

		$allowed_callbacks = [ 'wml_save_config' ];
		if ( ! in_array( $callback, $allowed_callbacks, true ) ) {
			return new WP_Error( 'invalid_callback', __( 'Invalid callback.', 'wpv-wml' ), [ 'status' => 400 ] );
		}

		$settings = new Settings();
		$res      = $settings->$callback( $params );
		return rest_ensure_response( $res );
	}

	/**
	 * This function is to send email
	 * @access public
	 * @since 0.5
	 * @param \WP_REST_Request $request Full data about the request.
	 * @return \WP_REST_Response Response object on success
	 */

	public function send_email( WP_REST_Request $request ) {
		$uploadDir = trailingslashit(wp_get_upload_dir()['basedir']);
		
		$params = $request->get_body_params();
		$files =  $request->get_file_params();
		
		$id        = $params['id'];
		$id = absint($id);
		if($id === 0){
			return rest_ensure_response( 'Invalid ID' );
		}
		$mail_data = $this->get_data_by_id( $id );
		if ( empty( $mail_data ) ) {
			return new WP_Error( 'not_found', __( 'Log entry not found.', 'wpv-wml' ), [ 'status' => 404 ] );
		}
		$mail_data = (array) $mail_data;
		$type = sanitize_key( $params['type'] ?? 'resend' );
		if ( ! in_array( $type, [ 'forward', 'resend' ], true ) ) {
			return new WP_Error( 'invalid_type', __( 'Invalid type.', 'wpv-wml' ), [ 'status' => 400 ] );
		}

		$email = sanitize_email( $params['to_email'] ?? '' );
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'invalid_email', __( 'Invalid recipient email.', 'wpv-wml' ), [ 'status' => 400 ] );
		}
		$subject     = $mail_data['subject'];
		$message     = $mail_data['message'];
		$attachments = [];
		$newFiles = [];
		if(array_key_exists('includeAttachment', $params)){
			$includeAttachment = json_decode($params['includeAttachment'], true);
			foreach ($includeAttachment as $key => $value) {
				$realFilePath = realpath($uploadDir . $key);
				$realBasePath = realpath($uploadDir) . DIRECTORY_SEPARATOR;
				if ($realFilePath === false || strpos($realFilePath, $realBasePath) !== 0) {
					return rest_ensure_response( 'File not found' );
				}else{
					$attachments[] = $uploadDir . $key;
				}
			}
		}
		// Attach original files
		// Local File Inclusion in
			
		// Attach Uploaded files
		foreach ($files as $key => $value) {
			$extension = pathinfo($files[$key]['name'], PATHINFO_EXTENSION);
			$time = time();
			$targetFile = $uploadDir . $time . '.' . $extension;
			$targetUrl = $uploadDir . $time . '.' . $extension;
			$wp_check_ext = wp_check_filetype_and_ext($targetFile, $value['name']);
			if($wp_check_ext['ext'] === false){
				return rest_ensure_response( 'File type not allowed' );
			}
			move_uploaded_file($files[$key]['tmp_name'],  $targetFile);
			$attachments[] = $targetUrl;
			$newFiles[] = $targetFile;
		}

		$headers     = '';
		if ( $type === 'forward' ) {
			$headers = $mail_data['headers'];
			if ( $headers == '' ) {
				$headers = 'Content-Type: text/html';
			}
		} else {
			$orignalFiles = explode( ',', $mail_data['files'] );
			foreach ( $orignalFiles as $key => $value ) {
				$value = trim( $value );
				if ( $value ) {
					// Stored paths begin with /uploads/... — resolve from ABSPATH to avoid double basedir prefix.
					$attachments[] = $uploadDir . ltrim( preg_replace( '#^/uploads/#', '', $value ), '/' );
				}
			}
			$headers = sanitize_textarea_field( $params['headers'] ?? '' );
		}

		$response = wp_mail( $email, $subject, $message, $headers, $attachments );
		
		foreach ($newFiles as $key => $value) {
			wp_delete_file($value);
		}
		return rest_ensure_response( $response );
	}

	private function sanitize_data( $data ) {

		$sanitized_data = [];
		
		$allowed_html = wp_kses_allowed_html('post');
		$allowed_html['style'] = [];

		foreach ( $data as $key => $row ) {
			$sanitized_data[] = [
				'id' 	=> $row->id,
				'to_email' => $row->to_email,
				'subject' => sanitize_text_field( $row->subject ),
				'message' => wp_kses( $row->message, $allowed_html ),
				'headers' =>  $row->headers,
				'attachments' => $row->attachments,
				'sent_date' => 	$row->sent_date,
				'files' => $row->files,
			];
			if(property_exists($row, 'dataFile')){
				$sanitized_data[$key]['dataFile'] = $row->dataFile;
			}
		}

		return $sanitized_data;
	}
}
