<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

class WC_Payex_Transactions {
	/**
	 * The single instance of the class.
	 *
	 * @var WC_Payex_Transactions
	 */
	protected static $_instance = null;

	/**
	 * Allowed Fields
	 * @var array
	 */
	protected static $_allowed_fields = array(
		'transaction_id',
		'transaction_data',
		'order_id',
		'payeeReference',
		'id',
		'created',
		'updated',
		'type',
		'state',
		'number',
		'amount',
		'vatAmount',
		'description'
	);

	/**
	 * Main Payex_Transactions Instance.
	 *
	 * @static
	 * @return WC_Payex_Transactions
	 */
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * Construct is forbidden.
	 */
	private function __construct() {
	}

	/**
	 * Cloning is forbidden.
	 */
	private function __clone() { /* ... @return Singleton */
	}

	/**
	 * Wakeup is forbidden.
	 */
	private function __wakeup() { /* ... @return Singleton */
	}

	/**
	 * Install DB Schema
	 */
	public function install_schema() {
		global $wpdb;
		$query = "
CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}payex_transactions` (
  `transaction_id` int(11) NOT NULL AUTO_INCREMENT,
  `transaction_data` text,
  `order_id` int(11) DEFAULT NULL,
  `id` varchar(255) DEFAULT NULL,
  `created` datetime DEFAULT NULL,
  `updated` datetime DEFAULT NULL,
  `type` varchar(128) DEFAULT NULL,
  `state` varchar(128) DEFAULT NULL,
  `number` varchar(128) DEFAULT NULL,
  `amount` bigint(20) DEFAULT NULL,
  `vatAmount` bigint(20) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `payeeReference` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`transaction_id`),
  UNIQUE KEY `number` (`number`),
  KEY `id` (`id`),
  KEY `order_id` (`order_id`)
) ENGINE=INNODB DEFAULT CHARSET={$wpdb->charset};
		";
		$wpdb->query( $query );
	}

	/**
	 * Add Transaction
	 *
	 * @param $fields
	 *
	 * @return bool|int
	 */
	public function add( $fields ) {
		global $wpdb;

		$result = $wpdb->insert( $wpdb->prefix . 'payex_transactions', $fields );
		if ( $result > 0 ) {
			return $wpdb->insert_id;
		}

		return false;
	}

	/**
	 * Delete Transaction
	 *
	 * @param $transaction_id
	 *
	 * @return false|int
	 */
	public function delete( $transaction_id ) {
		global $wpdb;

		return $wpdb->delete(
			$wpdb->prefix . 'payex_transactions',
			array( 'id' => (int) $transaction_id )
		);
	}

	/**
	 * Update Transaction Data
	 *
	 * @param $transaction_id
	 * @param $fields
	 *
	 * @return false|int
	 */
	public function update( $transaction_id, $fields ) {
		global $wpdb;

		return $wpdb->update(
			$wpdb->prefix . 'payex_transactions',
			$fields,
			array(
				'transaction_id' => (int) $transaction_id
			)
		);
	}

	/**
	 * Get Transaction
	 *
	 * @param $transaction_id
	 *
	 * @return array|null|object
	 */
	public function get( $transaction_id ) {
		global $wpdb;
		$query = $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}payex_transactions WHERE transaction_id = %d;",
			$transaction_id
		);

		return $wpdb->get_row( $query, ARRAY_A );
	}

	/**
	 * Get Transaction By Field
	 *
	 * @param      $field
	 * @param      $value
	 * @param bool $single
	 *
	 * @return array|null|object
	 */
	public function get_by( $field, $value, $single = true ) {
		global $wpdb;
		if ( ! in_array( $field, self::$_allowed_fields ) ) {
			$field = 'transaction_id';
		}

		$query = $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}payex_transactions WHERE {$field} = %s;",
			$value
		);

		return $single ? $wpdb->get_row( $query, ARRAY_A ) : $wpdb->get_results( $query, ARRAY_A );
	}

	/**
	 * Get Transactions by Conditionals
	 *
	 * @param array $conditionals
	 *
	 * @return array|null|object
	 */
	public function select( array $conditionals ) {
		global $wpdb;
		$lines = array();
		foreach ( $conditionals as $key => $value ) {
			if ( ! in_array( $key, self::$_allowed_fields ) ) {
				_doing_it_wrong( __METHOD__, __( 'Cheatin&#8217; huh?', 'woocommerce' ), '1.0.0' );
				die();
			}

			if ( ! is_numeric( $value ) ) {
				$value   = esc_sql( $value );
				$lines[] = "{$key} = '{$value}'";
			} else {
				$lines[] = "{$key} = {$value}";
			}


		}

		$lines = join( ' AND ', $lines );
		$query = "SELECT * FROM {$wpdb->prefix}payex_transactions WHERE {$lines};";

		return $wpdb->get_results( $query, ARRAY_A );
	}

	/**
	 * Prepare data
	 *
	 * @param $data
	 * @param $order_id
	 *
	 * @return array
	 */
	public function prepare( $data, $order_id ) {
		$allowed = self::$_allowed_fields;
		unset( $allowed['transaction_id'], $allowed['transaction_data'], $allowed['order_id'] );
		$data = array_filter( $data, function ( $value, $key ) use ( $allowed ) {
			return in_array( $key, $allowed );
		}, ARRAY_FILTER_USE_BOTH );

		$data['transaction_data'] = json_encode( $data, true );
		$data['order_id']         = $order_id;
		$data['created']          = gmdate( 'Y-m-d H:i:s', strtotime( $data['created'] ) );
		$data['updated']          = gmdate( 'Y-m-d H:i:s', strtotime( $data['updated'] ) );

		return $data;
	}

	/**
	 * Import Transaction
	 *
	 * @param $data
	 * @param $order_id
	 *
	 * @return bool|int|mixed
	 */
	public function import( $data, $order_id ) {
		$id    = $data['id'];
		$saved = $this->get_by( 'id', $id );
		if ( ! $saved ) {
			$data = $this->prepare( $data, $order_id );

			return $this->add( $data );
		} else {
			// Data is should be updated
			$data = $this->prepare( $data, $order_id );
			$this->update( $saved['transaction_id'], $data );
		}

		return $saved['transaction_id'];
	}

	/**
	 * Bulk Import Transaction
	 *
	 * @param $transactions
	 * @param $order_id
	 *
	 * @return array
	 */
	public function import_transactions( $transactions, $order_id ) {
		$result = array();
		foreach ( $transactions as $transaction ) {
			$result[] = $this->import( $transaction, $order_id );
		}

		return $result;
	}

}
