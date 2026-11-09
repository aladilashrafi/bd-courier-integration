<?php
/**
 * TEMPLATE - REST webhook receiver for Pathao, Carrybee, Steadfast and RedX (WordPress).
 *
 * Route: POST /wp-json/<NAMESPACE>/v1/webhook/{pathao|carrybee|steadfast|redx}
 *
 * Flow: verify (raw body) -> handshake? -> store raw event (unique dedupe key) -> 2xx fast ->
 *       async worker maps status -> do_action( 'bdc_shipment_status', ... ) for your store logic.
 *
 * Secrets come from $secrets (constants / encrypted options). Never hard-code them or ship them in JS.
 * See references/webhooks.md and references/status-map.md for the reasoning behind every rule here.
 */

namespace BDCourier;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Status_Mapper {
	// Keep in sync with scripts/status_map.py. [canonical, flags]; 'keep' = don't change state, 'ignore' = not a shipment state.
	private const MAP = array(
		'pathao'    => array(
			'order.created' => array( 'created', array() ), 'order.updated' => array( 'keep', array() ),
			'order.pickup-requested' => array( 'pickup_requested', array() ), 'order.assigned-for-pickup' => array( 'pickup_requested', array() ),
			'order.picked' => array( 'picked_up', array() ), 'order.pickup-failed' => array( 'pickup_failed', array() ), 'order.pickup-cancelled' => array( 'pickup_failed', array() ),
			'order.at-the-sorting-hub' => array( 'in_transit', array() ), 'order.in-transit' => array( 'in_transit', array() ), 'order.received-at-last-mile-hub' => array( 'in_transit', array() ),
			'order.assigned-for-delivery' => array( 'out_for_delivery', array() ), 'order.on-hold' => array( 'on_hold', array() ), 'order.delivery-failed' => array( 'delivery_failed', array() ),
			'order.delivered' => array( 'delivered', array() ), 'order.partial-delivery' => array( 'partial_delivered', array() ), 'order.returned' => array( 'returned', array() ),
			'order.paid' => array( 'settled', array() ), 'order.paid-return' => array( 'returned', array( 'cod_collected_on_return' ) ), 'order.exchanged' => array( 'exchange', array() ),
		),
		'carrybee'  => array(
			'order.created' => array( 'created', array() ), 'order.create-failed' => array( 'ignore', array( 'booking_failed' ) ), 'order.updated' => array( 'keep', array() ),
			'order.pickup-requested' => array( 'pickup_requested', array() ), 'order.assigned-for-pickup' => array( 'pickup_requested', array() ),
			'order.picked' => array( 'picked_up', array() ), 'order.pickup-failed' => array( 'pickup_failed', array() ), 'order.pickup-cancelled' => array( 'pickup_failed', array() ),
			'order.at-the-sorting-hub' => array( 'in_transit', array() ), 'order.on-the-way-to-central-warehouse' => array( 'in_transit', array() ), 'order.at-central-warehouse' => array( 'in_transit', array() ),
			'order.in-transit' => array( 'in_transit', array() ), 'order.received-at-last-mile-hub' => array( 'in_transit', array() ),
			'order.assigned-for-delivery' => array( 'out_for_delivery', array() ), 'order.delivery-on-hold' => array( 'on_hold', array() ), 'order.delivery-failed' => array( 'delivery_failed', array() ),
			'order.delivered' => array( 'delivered', array() ), 'order.partial-delivery' => array( 'partial_delivered', array() ),
			'order.returned-at-sorting' => array( 'returning', array() ), 'order.returned-in-transit' => array( 'returning', array() ),
			'order.returned' => array( 'returned', array() ), 'order.returned-to-merchant' => array( 'returned', array() ), 'order.paid-return' => array( 'returned', array( 'cod_collected_on_return' ) ),
			'order.exchange' => array( 'exchange', array() ), 'order.paid' => array( 'settled', array() ),
		),
		'steadfast' => array(
			'in_review' => array( 'created', array() ), 'pending' => array( 'created', array() ), 'hold' => array( 'on_hold', array() ),
			'delivered_approval_pending' => array( 'keep', array( 'awaiting_confirmation' ) ), 'partial_delivered_approval_pending' => array( 'keep', array( 'awaiting_confirmation' ) ),
			'cancelled_approval_pending' => array( 'keep', array( 'awaiting_confirmation' ) ), 'unknown_approval_pending' => array( 'keep', array( 'awaiting_confirmation' ) ),
			'delivered' => array( 'delivered', array() ), 'partial_delivered' => array( 'partial_delivered', array() ), 'cancelled' => array( 'cancelled', array() ),
			'exceptional' => array( 'exception', array() ), 'unknown' => array( 'unknown', array() ),
			'partial_delivered_return_proccessing' => array( 'returning', array( 'partial' ) ), 'partial_delivered_return_rider_assigned' => array( 'returning', array( 'partial' ) ),
			'partial_delivered_return_received' => array( 'returned', array( 'partial' ) ), 'cancelled_return_proccessing' => array( 'returning', array() ),
			'cancelled_return_rider_assigned' => array( 'returning', array() ), 'cancelled_return_received' => array( 'returned', array() ),
		),
		'redx'      => array(
			'ready-for-delivery' => array( 'picked_up', array() ), 'delivery-in-progress' => array( 'out_for_delivery', array() ), 'delivered' => array( 'delivered', array() ),
			'agent-hold' => array( 'on_hold', array() ), 'agent-returning' => array( 'returning', array() ), 'returned' => array( 'returned', array() ),
			'agent-area-change' => array( 'in_transit', array( 'area_changed' ) ), 'pickup-pending' => array( 'created', array() ),
		),
	);

	private const RANK = array(
		'created' => 0, 'pickup_requested' => 1, 'pickup_failed' => 1, 'picked_up' => 2, 'in_transit' => 3,
		'out_for_delivery' => 4, 'on_hold' => 4, 'delivery_failed' => 4, 'returning' => 8,
		'delivered' => 9, 'partial_delivered' => 9, 'returned' => 10, 'cancelled' => 10, 'exception' => 10, 'exchange' => 10,
	);

	/** @return array{0:string,1:array} */
	public static function normalize( string $courier, string $raw ): array {
		$raw = strtolower( trim( $raw ) );
		return self::MAP[ $courier ][ $raw ] ?? array( 'unknown', array( 'unmapped' ) );
	}

	/** No-regression rule: webhooks arrive out of order and get retried. */
	public static function should_apply( ?string $current, string $new ): bool {
		if ( in_array( $new, array( 'keep', 'ignore', 'settled', 'unknown' ), true ) ) {
			return false;
		}
		if ( ! $current || 'unknown' === $current ) {
			return true;
		}
		$terminal = array( 'delivered', 'partial_delivered', 'returned', 'cancelled', 'exception' );
		if ( in_array( $new, array( 'returning', 'returned', 'cancelled', 'exception', 'delivered', 'partial_delivered' ), true ) ) {
			if ( in_array( $current, $terminal, true ) && $new !== $current ) {
				return in_array( $current, array( 'delivered', 'partial_delivered' ), true ) && in_array( $new, array( 'returning', 'returned' ), true );
			}
			return true;
		}
		if ( in_array( $current, $terminal, true ) ) {
			return false;
		}
		$loop = array( 'on_hold', 'delivery_failed', 'out_for_delivery' );
		if ( in_array( $new, $loop, true ) && in_array( $current, array_merge( $loop, array( 'in_transit' ) ), true ) ) {
			return true;
		}
		return ( self::RANK[ $new ] ?? -1 ) >= ( self::RANK[ $current ] ?? -1 );
	}
}

final class Webhook_Controller {

	const TABLE_SUFFIX = 'bdc_courier_events';

	/** @var array e.g. ['pathao' => ['secret' => '', 'integration_secret' => ''], 'carrybee' => ['secret' => ''], 'steadfast' => ['token' => ''], 'redx' => ['token' => '']] */
	private $secrets;
	/** @var string REST namespace, e.g. 'myplugin/v1' */
	private $ns;

	public function __construct( string $namespace, array $secrets ) {
		$this->ns      = $namespace;
		$this->secrets = $secrets;
	}

	public function register(): void {
		add_action( 'rest_api_init', function () {
			register_rest_route( $this->ns, '/webhook/(?P<courier>pathao|carrybee|steadfast|redx)', array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true', // Auth happens inside: signatures need the raw body.
			) );
		} );
		add_action( 'bdc_process_courier_event', array( $this, 'process' ), 10, 1 );
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = $wpdb->prefix . self::TABLE_SUFFIX;
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			courier VARCHAR(20) NOT NULL,
			dedupe_key VARCHAR(190) NOT NULL,
			consignment_id VARCHAR(64) NOT NULL DEFAULT '',
			reference VARCHAR(100) NOT NULL DEFAULT '',
			event VARCHAR(80) NOT NULL DEFAULT '',
			payload LONGTEXT NOT NULL,
			received_at DATETIME NOT NULL,
			processed_at DATETIME NULL,
			error TEXT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY dedupe (courier, dedupe_key),
			KEY consignment (courier, consignment_id)
		) {$charset};" );
	}

	/* ------------------------------------------------------------------------------------ auth */

	private static function eq( string $a, string $b ): bool {
		return '' !== $b && hash_equals( $b, $a ); // empty configured secret never authenticates
	}

	private function authenticate( string $courier, \WP_REST_Request $req, string $raw ): bool {
		$s = $this->secrets[ $courier ] ?? array();
		switch ( $courier ) {
			case 'pathao': // shared secret, not an HMAC
				return self::eq( (string) $req->get_header( 'x-pathao-signature' ), (string) ( $s['secret'] ?? '' ) );
			case 'carrybee': // shared secret
				return self::eq( (string) $req->get_header( 'x-cb-webhook-integration-header' ), (string) ( $s['secret'] ?? '' ) );
			case 'steadfast': // HMAC-SHA256 over the RAW body, keyed by the auth token
				$token = (string) ( $s['token'] ?? '' );
				return '' !== $token && self::eq( strtolower( (string) $req->get_header( 'x-signature' ) ), hash_hmac( 'sha256', $raw, $token ) );
			case 'redx': // token in the callback URL query string (no signature exists)
				$q = $req->get_query_params();
				return self::eq( (string) ( $q['token'] ?? '' ), (string) ( $s['token'] ?? '' ) );
		}
		return false;
	}

	/* -------------------------------------------------------------------------------- handling */

	public function handle( \WP_REST_Request $req ) {
		$courier = (string) $req['courier'];
		$raw     = $req->get_body(); // raw bytes; verify BEFORE decoding.
		$data    = json_decode( $raw, true );

		if ( ! $this->authenticate( $courier, $req, $raw ) ) {
			return new \WP_REST_Response( array( 'error' => 'unauthorized' ), 401 );
		}
		if ( ! is_array( $data ) ) {
			return $this->ack( $courier, 202 ); // authenticated but not JSON (e.g. a panel "TEST" ping): accept, don't make them retry
		}

		// Carrybee handshake: 202 + echo the secret header.
		if ( 'carrybee' === $courier && 'webhook.integration' === ( $data['event'] ?? '' ) ) {
			return $this->ack( $courier, 202 );
		}

		$ev = $this->extract( $courier, $data );
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_SUFFIX;
		$wpdb->suppress_errors( true );
		$ok = $wpdb->insert( $table, array(
			'courier'        => $courier,
			'dedupe_key'     => $ev['dedupe_key'],
			'consignment_id' => $ev['consignment_id'],
			'reference'      => $ev['reference'],
			'event'          => $ev['event'],
			'payload'        => $raw,
			'received_at'    => gmdate( 'Y-m-d H:i:s' ),
		) );
		$wpdb->suppress_errors( false );

		if ( $ok ) { // false => duplicate (unique key) => already handled; still answer 2xx.
			$id = (int) $wpdb->insert_id;
			if ( function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action( 'bdc_process_courier_event', array( $id ), 'bdc' );
			} else {
				wp_schedule_single_event( time() + 5, 'bdc_process_courier_event', array( $id ) );
			}
		}
		return $this->ack( $courier, 'steadfast' === $courier ? 200 : 202 );
	}

	private function ack( string $courier, int $status ): \WP_REST_Response {
		$body = 'steadfast' === $courier ? array( 'status' => 'success' ) : array( 'ok' => true );
		$res  = new \WP_REST_Response( $body, $status );
		if ( 'pathao' === $courier && ! empty( $this->secrets['pathao']['integration_secret'] ) ) {
			$res->header( 'X-Pathao-Merchant-Webhook-Integration-Secret', $this->secrets['pathao']['integration_secret'] );
		}
		if ( 'carrybee' === $courier && ! empty( $this->secrets['carrybee']['secret'] ) ) {
			$res->header( 'X-CB-Webhook-Integration-Header', $this->secrets['carrybee']['secret'] );
		}
		return $res;
	}

	/** Courier-specific field names -> one shape. */
	private function extract( string $courier, array $d ): array {
		switch ( $courier ) {
			case 'pathao':
				$cid = (string) ( $d['consignment_id'] ?? '' );
				$ref = (string) ( $d['merchant_order_id'] ?? '' );
				$evt = (string) ( $d['event'] ?? '' );
				$ts  = (string) ( $d['timestamp'] ?? $d['updated_at'] ?? '' );
				break;
			case 'carrybee':
				$cid = (string) ( $d['consignment_id'] ?? '' );
				$ref = (string) ( $d['merchant_order_id'] ?? '' );
				$evt = (string) ( $d['event'] ?? '' );
				$ts  = (string) ( $d['timestamptz'] ?? '' );
				break;
			case 'steadfast':
				$cid = (string) ( $d['consignment_id'] ?? '' );
				$ref = (string) ( $d['invoice'] ?? '' );
				$evt = (string) ( $d['notification_type'] ?? '' ) . ':' . (string) ( $d['status'] ?? '' );
				$ts  = (string) ( $d['updated_at'] ?? '' );
				break;
			default: // redx
				$cid = (string) ( $d['tracking_number'] ?? '' );
				$ref = (string) ( $d['invoice_number'] ?? '' );
				$evt = (string) ( $d['status'] ?? '' );
				$ts  = (string) ( $d['timestamp'] ?? '' );
		}
		return array(
			'consignment_id' => substr( $cid, 0, 64 ),
			'reference'      => substr( $ref, 0, 100 ),
			'event'          => substr( $evt, 0, 80 ),
			// Steadfast also sends an Idempotency-Key header; this key is deterministic for all couriers.
			'dedupe_key'     => substr( hash( 'sha256', $cid . '|' . $ref . '|' . $evt . '|' . $ts ), 0, 64 ),
		);
	}

	/* ----------------------------------------------------------------------------- async worker */

	public function process( int $event_id ): void {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_SUFFIX;
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $event_id ), ARRAY_A );
		if ( ! $row || $row['processed_at'] ) {
			return;
		}
		$p   = json_decode( $row['payload'], true ) ?: array();
		$raw = 'steadfast' === $row['courier'] ? (string) ( $p['status'] ?? '' ) : $row['event'];
		list( $canonical, $flags ) = Status_Mapper::normalize( $row['courier'], $raw );

		$error = null;
		if ( 'unknown' === $canonical ) {
			$error = 'unmapped status/event: ' . $raw; // keep the raw payload, alert an admin, never throw
		}
		/**
		 * Your store logic hooks here (update shipment row, WC order status/note, COD reconciliation, emails).
		 * Apply Status_Mapper::should_apply( $current_state, $canonical ) against the shipment's stored state first.
		 *
		 * @param string $courier   pathao|carrybee|steadfast|redx
		 * @param string $consignment_id
		 * @param string $canonical canonical state, or 'keep' | 'ignore' | 'settled' | 'unknown'
		 * @param array  $flags     e.g. awaiting_confirmation, area_changed, partial
		 * @param array  $payload   decoded raw payload (collected_amount, invoice_id, reason ... where present)
		 * @param string $reference your order reference (merchant_order_id / invoice)
		 */
		do_action( 'bdc_shipment_status', $row['courier'], $row['consignment_id'], $canonical, $flags, $p, $row['reference'] );

		$wpdb->update( $table, array( 'processed_at' => gmdate( 'Y-m-d H:i:s' ), 'error' => $error ), array( 'id' => $event_id ) );
	}
}
