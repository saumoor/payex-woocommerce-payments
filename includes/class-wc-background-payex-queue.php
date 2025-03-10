<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WC_Background_Process', false ) ) {
	include_once WC_ABSPATH . '/includes/abstracts/class-wc-background-process.php';
}

/**
 * Class WC_Background_Payex_Queue
 */
class WC_Background_Payex_Queue extends WC_Background_Process {
	/**
	 * @var WC_Logger
	 */
	private $logger;

	/**
	 * Initiate new background process.
	 */
	public function __construct() {
		$this->logger = wc_get_logger();

		// Uses unique prefix per blog so each blog has separate queue.
		$this->prefix = 'wp_' . get_current_blog_id();
		$this->action = 'wc_payex_queue';

		// Dispatch queue after shutdown.
		add_action( 'shutdown', array( $this, 'dispatch_queue' ), 100 );

		parent::__construct();
	}

	/**
	 * Schedule fallback event.
	 */
	protected function schedule_event() {
		if ( ! wp_next_scheduled( $this->cron_hook_identifier ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, $this->cron_interval_identifier, $this->cron_hook_identifier );
		}
	}

	/**
	 * Get batch.
	 *
	 * @return stdClass Return the first batch from the queue.
	 */
	protected function get_batch() {
		global $wpdb;

		$table        = $wpdb->options;
		$column       = 'option_name';
		$key_column   = 'option_id';
		$value_column = 'option_value';

		if ( is_multisite() ) {
			$table        = $wpdb->sitemeta;
			$column       = 'meta_key';
			$key_column   = 'meta_id';
			$value_column = 'meta_value';
		}

		$key = $wpdb->esc_like( $this->identifier . '_batch_' ) . '%';

		$results = array();
		$data    = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$column} LIKE %s ORDER BY {$key_column} ASC", $key ) ); // @codingStandardsIgnoreLine.
		foreach ( $data as $id => $result ) {
			$task = array_filter( (array) maybe_unserialize( $result->$value_column ) );

			$batch       = new stdClass();
			$batch->key  = $result->$column;
			$batch->data = $task;

			$results[ $id ] = $batch;

			// Create Sorting Flow by Transaction Number
			$sorting_flow[ $id ] = 0;
			$webhook             = @json_decode( $task[0]['webhook_data'], true );
			if ( $webhook && isset( $webhook['transaction']['number'] ) ) {
				$sorting_flow[ $id ] = $webhook['transaction']['number'];
			}
		}

		// Sorting
		array_multisort( $sorting_flow, SORT_ASC, SORT_NUMERIC, $results );
		unset( $data, $sorting_flow );

		// Get first result
		$batch = array_shift( $results );

		return $batch;
	}

	/**
	 * Log message.
	 *
	 * @param $message
	 */
	private function log( $message ) {
		$this->logger->info( $message, array( 'source' => 'wc_payex_queue' ) );
	}

	/**
	 * Code to execute for each item in the queue.
	 *
	 * @param mixed $item Queue item to iterate over.
	 *
	 * @return bool
	 */
	protected function task( $item ) {
		$this->log( sprintf( 'Start task: %s', var_export( $item, true ) ) );

		try {
			$data = @json_decode( $item['webhook_data'], true );
			if ( ! $data ) {

				throw new Exception( 'Invalid webhook data' );
			}

			/** @var WC_Gateway_Payex_Cc $gateway */
			$gateway = px_payment_method( $item['payment_method_id'] );
			if ( ! $gateway ) {
				throw new Exception( sprintf( 'Can\'t retrieve payment gateway instance: %s', $item['payment_method_id'] ) );
			}

			if ( ! isset( $data['payment'] ) || ! isset( $data['payment']['id'] ) ) {
				throw new Exception( 'Error: Invalid payment value' );
			}

			if ( ! isset( $data['transaction'] ) || ! isset( $data['transaction']['number'] ) ) {
				throw new Exception( 'Error: Invalid transaction number' );
			}

			// Get Order by Payment Id
			$payment_id = $data['payment']['id'];
			$order_id   = px_get_post_id_by_meta( '_payex_payment_id', $payment_id );
			if ( ! $order_id ) {
				throw new Exception( sprintf( 'Error: Failed to get order Id by Payment Id %s', $payment_id ) );
			}

			// Get Order
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				throw new Exception( sprintf( 'Error: Failed to get order by Id %s', $order_id ) );
			}
		} catch ( Exception $e ) {
			$this->log( sprintf( '[ERROR]: Validation error: %s', $e->getMessage() ) );

			return false;
		}

		try {
			// Fetch transactions list
			$result       = $gateway->request( 'GET', $payment_id . '/transactions' );
			$transactions = $result['transactions']['transactionList'];
			$gateway->transactions->import_transactions( $transactions, $order_id );

			// Extract transaction from list
			$transaction_id    = $data['transaction']['number'];
			$transaction_state = $data['transaction']['state'];
			$transaction       = px_filter( $transactions, array( 'number' => $transaction_id ) );
			$this->log( sprintf( 'Transaction: %s', var_export( $transaction, true ) ) );
			if ( ! is_array( $transaction ) || count( $transaction ) === 0 ) {
				throw new Exception( sprintf( 'Failed to fetch transaction number #%s', $transaction_id ) );
			}

			// Prevent
			//wp_cache_delete( 'payex_transaction_' . $transaction_id . $transaction_state, 'site_transient' );
			//if ( get_site_transient( 'payex_transaction_' . $transaction_id . $transaction_state ) !== FALSE ) {
			//	throw new Exception( sprintf( 'WEBHOOK: Error: Transaction #%s rejected (duplicate request)', $transaction_id ) );
			//}

			//set_site_transient( 'payex_transaction_' . $transaction_id . $transaction_state, TRUE, MINUTE_IN_SECONDS * 5 );

			// Process transaction
			try {
				$gateway->process_transaction( $transaction, $order );
			} catch ( Exception $e ) {
				$this->log( sprintf( '[WARNING]: Transaction processing: %s', $e->getMessage() ) );
			}

			return false;
		} catch ( Exception $e ) {
			$this->log( sprintf( '[ERROR]: %s', $e->getMessage() ) );
		}

		return true;
	}

	/**
	 * This runs once the job has completed all items on the queue.
	 *
	 * @return void
	 */
	protected function complete() {
		parent::complete();

		$this->log( 'Completed payex queue job.' );
	}

	/**
	 * Save and run queue.
	 */
	public function dispatch_queue() {
		if ( ! empty( $this->data ) ) {
			$this->save()->dispatch();
		}
	}
}
