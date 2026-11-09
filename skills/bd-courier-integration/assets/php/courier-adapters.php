<?php
/**
 * TEMPLATE - Bangladeshi courier adapters for WordPress/WooCommerce.
 *
 * Adapt, don't paste blindly: rename the namespace/prefixes, wire $cfg from your settings
 * (secrets from wp-config constants or encrypted options - never hard-coded), and run orders
 * through the rules in scripts/validate_order.py first (this file re-checks only the basics).
 *
 * Normalized order input (all adapters):
 *   name, phone, address, cod_amount, weight_grams, quantity, reference, note, description,
 *   alt_phone, declared_value, store_id, city_id, zone_id, area_id, area_name
 *
 * Normalized result (all adapters): ok, consignment_id, tracking_code, fee, status_raw, http, error, raw
 *
 * Retry policy: only Steadfast booking may be retried blindly (unique invoice). For Pathao, Carrybee and
 * RedX an ambiguous failure (http == 0, i.e. timeout) must be resolved by checking the courier or asking
 * staff - a retry can double-book. This file never retries a booking POST on its own.
 */

namespace BDCourier;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Courier_Adapter {
	public function slug(): string;
	/** Book a shipment from a normalized order array. */
	public function create_shipment( array $order ): array;
	/** Current raw status for a consignment/tracking id. */
	public function get_status( string $id ): array;
	/** Cancel / pull back. Adapters that have no documented cancel return ok=false with an explanation. */
	public function cancel( string $id, string $reason = '' ): array;
}

final class Phone {
	/** Normalize +8801XXXXXXXXX / 8801XXXXXXXXX / 1XXXXXXXXX to 01XXXXXXXXX; null if not a valid mobile. */
	public static function normalize( $raw ): ?string {
		$d = preg_replace( '/\D+/', '', (string) $raw );
		if ( 0 === strpos( $d, '880' ) ) {
			$d = substr( $d, 3 );
		}
		if ( 10 === strlen( $d ) && '1' === $d[0] ) {
			$d = '0' . $d;
		}
		return preg_match( '/^01[3-9]\d{8}$/', $d ) ? $d : null;
	}
}

abstract class Base_Adapter implements Courier_Adapter {

	/** @var array Adapter config: sandbox(bool) plus the courier's credentials. */
	protected $cfg;

	public function __construct( array $cfg ) {
		$this->cfg = $cfg;
	}

	protected function sandbox(): bool {
		return ! empty( $this->cfg['sandbox'] );
	}

	/**
	 * Raw HTTP call. Returns ['code' => int, 'json' => ?array, 'error' => ?string].
	 * code 0 means no HTTP response at all (timeout/DNS/TLS) - an ambiguous outcome for POSTs.
	 */
	protected function http( string $method, string $url, array $headers = array(), $body = null, int $timeout = 20 ): array {
		$headers += array( 'Content-Type' => 'application/json', 'Accept' => 'application/json' );
		$args     = array(
			'method'    => $method,
			'timeout'   => $timeout,
			'headers'   => $headers,
			'sslverify' => true,
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$res = wp_remote_request( $url, $args );
		if ( is_wp_error( $res ) ) {
			return array( 'code' => 0, 'json' => null, 'error' => $res->get_error_message() );
		}
		$json = json_decode( wp_remote_retrieve_body( $res ), true );
		return array(
			'code'  => (int) wp_remote_retrieve_response_code( $res ),
			'json'  => is_array( $json ) ? $json : null,
			'error' => null,
		);
	}

	protected function result( bool $ok, array $extra = array() ): array {
		return $extra + array(
			'ok'             => $ok,
			'consignment_id' => null,
			'tracking_code'  => null,
			'fee'            => null,
			'status_raw'     => null,
			'http'           => 0,
			'error'          => null,
			'raw'            => null,
		);
	}

	/** Pull a human-readable error out of the courier's response shapes. */
	protected function error_from( array $r ): string {
		if ( $r['error'] ) {
			return 'Network error: ' . $r['error'];
		}
		$j = $r['json'];
		if ( is_array( $j ) ) {
			foreach ( array( 'message', 'error' ) as $k ) {
				if ( isset( $j[ $k ] ) && is_string( $j[ $k ] ) && '' !== $j[ $k ] ) {
					$msg = $j[ $k ];
					break;
				}
			}
			$detail = $j['errors'] ?? $j['causes'] ?? null;
			if ( $detail ) {
				$msg = ( $msg ?? 'Rejected' ) . ' ' . wp_json_encode( $detail );
			}
			if ( isset( $msg ) ) {
				return $msg;
			}
		}
		return 'HTTP ' . $r['code'];
	}

	protected function log( string $message, array $context = array() ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			foreach ( array( 'client_secret', 'secret', 'password', 'token', 'authorization', 'api-key', 'secret-key' ) as $k ) {
				unset( $context[ $k ] );
			}
			wc_get_logger()->info( $message . ' ' . wp_json_encode( $context ), array( 'source' => 'bdc-' . $this->slug() ) );
		}
	}
}

/* ------------------------------------------------------------------------------------------- Pathao */

final class Pathao_Adapter extends Base_Adapter {
	// cfg: sandbox, client_id, client_secret, username, password, store_id (default), token_option (optional)

	public function slug(): string {
		return 'pathao';
	}

	private function base(): string {
		return $this->sandbox() ? 'https://courier-api-sandbox.pathao.com' : 'https://api-hermes.pathao.com';
	}

	private function opt(): string {
		return $this->cfg['token_option'] ?? 'bdc_pathao_token';
	}

	private function save_token( array $json ): ?string {
		if ( empty( $json['access_token'] ) ) {
			return null;
		}
		update_option(
			$this->opt(),
			array(
				'access'     => $json['access_token'],
				'refresh'    => $json['refresh_token'] ?? '',
				'expires_at' => time() + (int) ( $json['expires_in'] ?? 432000 ) - 3600,
			),
			false
		);
		return $json['access_token'];
	}

	private function token( bool $force = false ): ?string {
		$t = get_option( $this->opt() );
		if ( ! $force && is_array( $t ) && ! empty( $t['access'] ) && $t['expires_at'] > time() + 300 ) {
			return $t['access'];
		}
		// Short lock so concurrent requests don't each refresh and burn each other's refresh token.
		if ( get_transient( 'bdc_pathao_lock' ) ) {
			sleep( 1 );
			$t = get_option( $this->opt() );
			if ( is_array( $t ) && ! empty( $t['access'] ) && $t['expires_at'] > time() + 60 ) {
				return $t['access'];
			}
		}
		set_transient( 'bdc_pathao_lock', 1, 30 );
		try {
			$url = $this->base() . '/aladdin/api/v1/issue-token';
			if ( is_array( $t ) && ! empty( $t['refresh'] ) ) {
				$r = $this->http( 'POST', $url, array(), array(
					'client_id'     => $this->cfg['client_id'],
					'client_secret' => $this->cfg['client_secret'],
					'grant_type'    => 'refresh_token',
					'refresh_token' => $t['refresh'],
				) );
				if ( 200 === $r['code'] && $r['json'] && $this->save_token( $r['json'] ) ) {
					return $r['json']['access_token'];
				}
			}
			$r = $this->http( 'POST', $url, array(), array(
				'client_id'     => $this->cfg['client_id'],
				'client_secret' => $this->cfg['client_secret'],
				'grant_type'    => 'password',
				'username'      => $this->cfg['username'],
				'password'      => $this->cfg['password'],
			) );
			return ( 200 === $r['code'] && $r['json'] ) ? $this->save_token( $r['json'] ) : null;
		} finally {
			delete_transient( 'bdc_pathao_lock' );
		}
	}

	/** Authenticated call with one refresh-and-retry on 401 (safe: a 401 means nothing was processed). */
	private function call( string $method, string $path, $body = null ): array {
		for ( $i = 0; $i < 2; $i++ ) {
			$tok = $this->token( 1 === $i );
			if ( ! $tok ) {
				return array( 'code' => 0, 'json' => null, 'error' => 'Pathao authentication failed' );
			}
			$r = $this->http( $method, $this->base() . $path, array( 'Authorization' => 'Bearer ' . $tok ), $body );
			if ( 401 !== $r['code'] ) {
				return $r;
			}
		}
		return $r;
	}

	public function create_shipment( array $o ): array {
		$phone = Phone::normalize( $o['phone'] ?? '' );
		if ( ! $phone ) {
			return $this->result( false, array( 'error' => 'Invalid recipient phone' ) );
		}
		$kg = max( 0.5, ( (int) ( $o['weight_grams'] ?? 500 ) ) / 1000 );
		if ( $kg > 10 ) {
			return $this->result( false, array( 'error' => 'Pathao max parcel weight is 10 kg' ) );
		}
		$payload = array(
			'store_id'          => (int) ( $o['store_id'] ?? $this->cfg['store_id'] ),
			'recipient_name'    => (string) $o['name'],
			'recipient_phone'   => $phone,
			'recipient_address' => (string) $o['address'],
			'delivery_type'     => 48,
			'item_type'         => 2,
			'item_quantity'     => max( 1, (int) ( $o['quantity'] ?? 1 ) ),
			'item_weight'       => round( $kg, 3 ),
			'amount_to_collect' => (int) round( (float) ( $o['cod_amount'] ?? 0 ) ),
		);
		if ( ! empty( $o['reference'] ) ) {
			$payload['merchant_order_id'] = (string) $o['reference'];
		}
		if ( ! empty( $o['note'] ) ) {
			$payload['special_instruction'] = (string) $o['note'];
		}
		if ( ! empty( $o['description'] ) ) {
			$payload['item_description'] = (string) $o['description'];
		}
		// Optional ids: include only when known - never send null.
		foreach ( array( 'city_id' => 'recipient_city', 'zone_id' => 'recipient_zone', 'area_id' => 'recipient_area' ) as $src => $dst ) {
			if ( ! empty( $o[ $src ] ) ) {
				$payload[ $dst ] = (int) $o[ $src ];
			}
		}
		$r = $this->call( 'POST', '/aladdin/api/v1/orders', $payload );
		$d = $r['json']['data'] ?? array();
		if ( 200 === $r['code'] && ! empty( $d['consignment_id'] ) ) {
			return $this->result( true, array(
				'consignment_id' => $d['consignment_id'],
				'fee'            => isset( $d['delivery_fee'] ) ? (float) $d['delivery_fee'] : null,
				'status_raw'     => $d['order_status'] ?? 'Pending',
				'http'           => $r['code'],
				'raw'            => $r['json'],
			) );
		}
		return $this->result( false, array( 'http' => $r['code'], 'error' => $this->error_from( $r ), 'raw' => $r['json'] ) );
	}

	public function get_status( string $id ): array {
		$r = $this->call( 'GET', '/aladdin/api/v1/orders/' . rawurlencode( $id ) . '/info' );
		$d = $r['json']['data'] ?? array();
		return 200 === $r['code'] && $d
			? $this->result( true, array( 'consignment_id' => $id, 'status_raw' => $d['order_status_slug'] ?? $d['order_status'] ?? null, 'http' => 200, 'raw' => $r['json'] ) )
			: $this->result( false, array( 'http' => $r['code'], 'error' => $this->error_from( $r ) ) );
	}

	public function cancel( string $id, string $reason = '' ): array {
		return $this->result( false, array( 'error' => 'Pathao docs list no cancel endpoint - cancel from the Pathao merchant panel or ask Pathao support.' ) );
	}
}

/* ----------------------------------------------------------------------------------------- Carrybee */

final class Carrybee_Adapter extends Base_Adapter {
	// cfg: sandbox, client_id, client_secret, client_context, store_id (default)

	public function slug(): string {
		return 'carrybee';
	}

	private function call( string $method, string $path, $body = null ): array {
		$base = $this->sandbox() ? 'https://sandbox.carrybee.com' : 'https://developers.carrybee.com';
		return $this->http( $method, $base . $path, array(
			'Client-ID'      => $this->cfg['client_id'],
			'Client-Secret'  => $this->cfg['client_secret'],
			'Client-Context' => $this->cfg['client_context'],
		), $body );
	}

	public function create_shipment( array $o ): array {
		$phone = Phone::normalize( $o['phone'] ?? '' );
		if ( ! $phone ) {
			return $this->result( false, array( 'error' => 'Invalid recipient phone' ) );
		}
		$grams = (int) ( $o['weight_grams'] ?? 500 );
		$cod   = (int) round( (float) ( $o['cod_amount'] ?? 0 ) );
		if ( $grams < 1 || $grams > 25000 ) {
			return $this->result( false, array( 'error' => 'Carrybee weight must be 1-25000 g' ) );
		}
		if ( $cod > 100000 ) {
			return $this->result( false, array( 'error' => 'Carrybee COD limit is 100000 BDT' ) );
		}
		$payload = array(
			'store_id'           => (string) ( $o['store_id'] ?? $this->cfg['store_id'] ),
			'delivery_type'      => 1,
			'product_type'       => 1,
			'recipient_name'     => (string) $o['name'],
			'recipient_phone'    => $phone,
			'recipient_address'  => (string) $o['address'],
			'item_weight'        => $grams,
			'item_quantity'      => max( 1, (int) ( $o['quantity'] ?? 1 ) ),
			'collectable_amount' => $cod,
		);
		if ( ! empty( $o['reference'] ) ) {
			$payload['merchant_order_id'] = substr( (string) $o['reference'], 0, 49 );
		}
		if ( ! empty( $o['note'] ) ) {
			$payload['special_instruction'] = substr( (string) $o['note'], 0, 254 );
		}
		if ( ! empty( $o['description'] ) ) {
			$payload['product_description'] = substr( (string) $o['description'], 0, 254 );
		}
		foreach ( array( 'city_id', 'zone_id', 'area_id' ) as $k ) {
			if ( ! empty( $o[ $k ] ) ) {
				$payload[ $k ] = (int) $o[ $k ];
			}
		}
		// Secondary phone: the docs spell the create field 'recipient_secendary_phone' (typo). Verify in sandbox before adding it.
		$r  = $this->call( 'POST', '/api/v2/orders', $payload );
		$od = $r['json']['data']['order'] ?? array();
		if ( 201 === $r['code'] && ! empty( $od['consignment_id'] ) ) {
			return $this->result( true, array(
				'consignment_id' => $od['consignment_id'],
				'fee'            => isset( $od['delivery_fee'] ) ? (float) $od['delivery_fee'] : null,
				'http'           => 201,
				'raw'            => $r['json'],
			) );
		}
		return $this->result( false, array( 'http' => $r['code'], 'error' => $this->error_from( $r ), 'raw' => $r['json'] ) );
	}

	/** $id may be the consignment id or your merchant order id (the API accepts both). */
	public function get_status( string $id ): array {
		$r = $this->call( 'GET', '/api/v2/orders/' . rawurlencode( $id ) . '/details' );
		$d = $r['json']['data'] ?? array();
		return 200 === $r['code'] && $d
			? $this->result( true, array( 'consignment_id' => $d['consignment_id'] ?? $id, 'status_raw' => $d['transfer_status'] ?? null, 'http' => 200, 'raw' => $r['json'] ) )
			: $this->result( false, array( 'http' => $r['code'], 'error' => $this->error_from( $r ) ) );
	}

	public function cancel( string $id, string $reason = '' ): array {
		$r = $this->call( 'POST', '/api/v2/orders/' . rawurlencode( $id ) . '/cancel', array( 'cancellation_reason' => substr( $reason ?: 'Cancelled by merchant', 0, 199 ) ) );
		// 202 = accepted, not completed: confirm via details/webhook.
		return 202 === $r['code']
			? $this->result( true, array( 'consignment_id' => $id, 'http' => 202 ) )
			: $this->result( false, array( 'http' => $r['code'], 'error' => $this->error_from( $r ) ) );
	}
}

/* ---------------------------------------------------------------------------------------- Steadfast */

final class Steadfast_Adapter extends Base_Adapter {
	// cfg: api_key, secret_key   (no sandbox exists - warn the admin that bookings are real)

	public function slug(): string {
		return 'steadfast';
	}

	private function call( string $method, string $path, $body = null ): array {
		return $this->http( $method, 'https://portal.packzy.com/api/v1' . $path, array(
			'Api-Key'    => $this->cfg['api_key'],
			'Secret-Key' => $this->cfg['secret_key'],
		), $body );
	}

	private static function clean( string $s, int $limit ): string {
		return substr( str_replace( array( '{', '}', ';', '<', '>', '$' ), ' ', $s ), 0, $limit );
	}

	public function create_shipment( array $o ): array {
		$phone   = Phone::normalize( $o['phone'] ?? '' );
		$invoice = (string) ( $o['reference'] ?? '' );
		if ( ! $phone ) {
			return $this->result( false, array( 'error' => 'Invalid recipient phone' ) );
		}
		if ( ! preg_match( '/^[A-Za-z0-9_-]{1,100}$/', $invoice ) ) {
			return $this->result( false, array( 'error' => 'Invoice must be 1-100 chars of letters, digits, - or _' ) );
		}
		$payload = array(
			'invoice'           => $invoice,
			'recipient_name'    => self::clean( (string) $o['name'], 100 ),
			'recipient_phone'   => $phone,
			'recipient_address' => self::clean( (string) $o['address'], 490 ),
			'cod_amount'        => (int) round( (float) ( $o['cod_amount'] ?? 0 ) ),
		);
		if ( ! empty( $o['note'] ) ) {
			$payload['note'] = self::clean( (string) $o['note'], 480 );
		}
		if ( ! empty( $o['alt_phone'] ) && Phone::normalize( $o['alt_phone'] ) ) {
			$payload['alternative_phone'] = Phone::normalize( $o['alt_phone'] );
		}
		if ( ! empty( $o['description'] ) ) {
			$payload['item_description'] = substr( (string) $o['description'], 0, 255 );
		}
		if ( ! empty( $o['quantity'] ) ) {
			$payload['total_lot'] = (int) $o['quantity'];
		}
		$r = $this->call( 'POST', '/create_order', $payload );
		$c = $r['json']['consignment'] ?? array();
		// Read the body: a 200 can still carry status 400 + errors.
		if ( 200 === $r['code'] && 200 === (int) ( $r['json']['status'] ?? 0 ) && ! empty( $c['consignment_id'] ) ) {
			return $this->result( true, array(
				'consignment_id' => (string) $c['consignment_id'],
				'tracking_code'  => $c['tracking_code'] ?? null,
				'status_raw'     => $c['status'] ?? 'in_review',
				'http'           => 200,
				'raw'            => $r['json'],
			) );
		}
		return $this->result( false, array( 'http' => $r['code'], 'error' => $this->error_from( $r ), 'raw' => $r['json'] ) );
	}

	public function get_status( string $id ): array {
		$r = $this->call( 'GET', '/status_with_return_status_by_cid/' . rawurlencode( $id ) );
		return 200 === $r['code'] && isset( $r['json']['delivery_status'] )
			? $this->result( true, array( 'consignment_id' => $id, 'status_raw' => $r['json']['delivery_status'], 'http' => 200, 'raw' => $r['json'] ) )
			: $this->result( false, array( 'http' => $r['code'], 'error' => $this->error_from( $r ) ) );
	}

	/** Steadfast has no cancel endpoint: a return request pulls an undelivered parcel back. */
	public function cancel( string $id, string $reason = '' ): array {
		$r = $this->call( 'POST', '/create_return_request', array( 'consignment_id' => (int) $id, 'reason' => substr( $reason, 0, 500 ) ) );
		return 201 === $r['code']
			? $this->result( true, array( 'consignment_id' => $id, 'http' => 201, 'raw' => $r['json'] ) )
			: $this->result( false, array( 'http' => $r['code'], 'error' => $this->error_from( $r ) ) ); // 422: already delivered/returning/open request
	}

	/** Cheap credential probe: 401 means wrong keys. Do not call in a loop (10 failures => 60 min lockout). */
	public function check_credentials(): bool {
		return 200 === $this->call( 'GET', '/get_balance' )['code'];
	}
}

/* --------------------------------------------------------------------------------------------- RedX */

final class RedX_Adapter extends Base_Adapter {
	// cfg: sandbox, token (JWT), store_id (pickup_store_id, optional)

	public function slug(): string {
		return 'redx';
	}

	private function call( string $method, string $path, $body = null ): array {
		$base = $this->sandbox() ? 'https://sandbox.redx.com.bd/v1.0.0-beta' : 'https://openapi.redx.com.bd/v1.0.0-beta';
		return $this->http( $method, $base . $path, array( 'API-ACCESS-TOKEN' => 'Bearer ' . $this->cfg['token'] ), $body );
	}

	public function create_shipment( array $o ): array {
		$phone = Phone::normalize( $o['phone'] ?? '' );
		if ( ! $phone ) {
			return $this->result( false, array( 'error' => 'Invalid customer phone' ) );
		}
		if ( empty( $o['area_id'] ) || empty( $o['area_name'] ) ) {
			return $this->result( false, array( 'error' => 'RedX needs delivery area name + id (GET /areas)' ) );
		}
		$cod     = (int) round( (float) ( $o['cod_amount'] ?? 0 ) );
		$payload = array(
			'customer_name'          => (string) $o['name'],
			'customer_phone'         => $phone,
			'delivery_area'          => (string) $o['area_name'],
			'delivery_area_id'       => (int) $o['area_id'],
			'customer_address'       => (string) $o['address'],
			'cash_collection_amount' => $cod,
			'parcel_weight'          => (int) ( $o['weight_grams'] ?? 500 ), // grams
			'value'                  => (int) ( $o['declared_value'] ?? $cod ),
		);
		if ( ! empty( $o['reference'] ) ) {
			$payload['merchant_invoice_id'] = (string) $o['reference'];
		}
		if ( ! empty( $o['note'] ) ) {
			$payload['instruction'] = (string) $o['note'];
		}
		$store = $o['store_id'] ?? ( $this->cfg['store_id'] ?? null );
		if ( $store ) {
			$payload['pickup_store_id'] = (int) $store;
		}
		$r = $this->call( 'POST', '/parcel', $payload );
		if ( $r['code'] >= 200 && $r['code'] < 300 && ! empty( $r['json']['tracking_id'] ) ) {
			return $this->result( true, array( 'consignment_id' => $r['json']['tracking_id'], 'http' => $r['code'], 'raw' => $r['json'] ) );
		}
		return $this->result( false, array( 'http' => $r['code'], 'error' => $this->error_from( $r ), 'raw' => $r['json'] ) );
	}

	public function get_status( string $id ): array {
		$r = $this->call( 'GET', '/parcel/info/' . rawurlencode( $id ) );
		$p = $r['json']['parcel'] ?? array();
		return 200 === $r['code'] && $p
			? $this->result( true, array( 'consignment_id' => $id, 'status_raw' => $p['status'] ?? null, 'fee' => isset( $p['charge'] ) ? (float) $p['charge'] : null, 'http' => 200, 'raw' => $r['json'] ) )
			: $this->result( false, array( 'http' => $r['code'], 'error' => $this->error_from( $r ) ) );
	}

	public function cancel( string $id, string $reason = '' ): array {
		$r = $this->call( 'PATCH', '/parcels', array(
			'entity_type'    => 'parcel-tracking-id',
			'entity_id'      => $id,
			'update_details' => array( 'property_name' => 'status', 'new_value' => 'cancelled', 'reason' => $reason ?: 'Cancelled by merchant' ),
		) );
		// "Request Accepted" is a request, not a confirmation - verify through get_status()/webhook.
		return ( 200 === $r['code'] && ! empty( $r['json']['success'] ) )
			? $this->result( true, array( 'consignment_id' => $id, 'http' => 200, 'raw' => $r['json'] ) )
			: $this->result( false, array( 'http' => $r['code'], 'error' => $this->error_from( $r ) ) );
	}
}

/* ---------------------------------------------------------------------------------------- Factory */

final class Courier_Factory {
	/** @param array $cfg Settings for that courier (see each adapter's header comment). */
	public static function make( string $slug, array $cfg ): Courier_Adapter {
		switch ( $slug ) {
			case 'pathao':
				return new Pathao_Adapter( $cfg );
			case 'carrybee':
				return new Carrybee_Adapter( $cfg );
			case 'steadfast':
				return new Steadfast_Adapter( $cfg );
			case 'redx':
				return new RedX_Adapter( $cfg );
		}
		throw new \InvalidArgumentException( 'Unknown courier: ' . $slug );
	}
}
