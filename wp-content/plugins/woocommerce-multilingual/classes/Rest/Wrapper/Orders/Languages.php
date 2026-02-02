<?php

namespace WCML\Rest\Wrapper\Orders;

use WCML\Rest\Wrapper\Handler;
use WCML\Rest\Exceptions\InvalidLanguage;
use WPML\FP\Obj;

use function WCML\functions\getId;

class Languages extends Handler {

	/**
	 * @param array            $args
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return array
	 */
	public function query( $args, $request ) {
		$lang = $request->get_param( 'lang' );

		if ( ! is_null( $lang ) && 'all' !== $lang ) {
			$args['meta_query'][] = [
				'key'   => \WCML_Orders::KEY_LANGUAGE,
				'value' => strval( $lang ),
			];
		}

		return $args;
	}


	/**
	 * Appends the language and translation information to the get_product response
	 *
	 * @param \WP_REST_Response        $response
	 * @param \WP_Post|\WC_Order|mixed $object
	 * @param \WP_REST_Request         $request
	 *
	 * @return \WP_REST_Response
	 */
	public function prepare( $response, $object, $request ) {
		$language      = get_query_var( 'lang' );
		$orderLanguage = \WCML_Orders::getLanguage( $this->get_id( $object ) );

		if ( $orderLanguage !== $language ) {
			$lineItems = Obj::propOr( [], 'line_items', $response->data );
			foreach ( $lineItems as $k => $item ) {
				$translatedProductId = wpml_object_id_filter( $item['product_id'], 'product', false, $language );
				if ( $translatedProductId ) {
					$translatedProduct                                = get_post( $translatedProductId );
					$response->data['line_items'][ $k ]['product_id'] = $translatedProductId;
					if ( 'product_variation' === $translatedProduct->post_type ) {
						$postParent = get_post( $translatedProduct->post_parent );
						$postName   = $postParent->post_title;
					} else {
						$postName = $translatedProduct->post_title;
					}
					$response->data['line_items'][ $k ]['name'] = $postName;
				}
			}
		}

		return $response;
	}

	/**
	 * @param \WP_Post|\WC_Order|mixed $object
	 *
	 * @return int
	 *
	 * @throws \Exception If order has no id.
	 */
	private function get_id( $object ) {
		try {
			return getId( $object );
		} catch ( \Exception $err ) {
			throw new \Exception( 'Order has no ID set.' );
		}
	}


	/**
	 * Sets the product information according to the provided language
	 *
	 * @param object           $object
	 * @param \WP_REST_Request $request
	 * @param bool             $creating
	 *
	 * @throws InvalidLanguage If relevant language is not active.
	 */
	public function insert( $object, $request, $creating ) {
		$data = $request->get_params();
		if ( isset( $data['lang'] ) ) {

			if ( ! apply_filters( 'wpml_language_is_active', false, $data['lang'] ) ) {
				throw new InvalidLanguage( $data['lang'] );
			}

			\WCML_Orders::setLanguage( $object->get_id(), $data['lang'] );
		}
	}
}
