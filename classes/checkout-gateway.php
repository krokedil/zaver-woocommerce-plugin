<?php
namespace Zaver;
use Zaver\SDK\Checkout;
use Zaver\SDK\Object\PaymentCreationRequest;
use Zaver\SDK\Object\MerchantUrls;
use WC_Payment_Gateway;
use WC_Order;
use Zaver\SDK\Object\PaymentStatusResponse;

class Checkout_Gateway extends WC_Payment_Gateway {
	private $api_instance = null;

	public function __construct() {
		$this->id = Plugin::PAYMENT_METHOD;
		$this->has_fields = false;
		$this->method_title = __('Zaver Checkout', 'zco');

		$this->init_form_fields();
		$this->init_settings();

		$this->title = $this->get_option('title');
		$this->order_button_text = apply_filters('zco_order_button_text', __('Pay with Zaver', 'zco'));

		add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
	}

	public function init_form_fields(): void {
		$this->form_fields = [
			'enabled' => [
				'type'    => 'checkbox',
				'default' => 'yes',
				'title'   => __('Enable/Disable', 'zco'),
				'label'   => __('Enable Zaver Checkout', 'zco'),
			],
			'title' => [
				'type'        => 'text',
				'desc_tip'    => true,
				'title'       => __('Title', 'zco'),
				'description' => __('This controls the title which the user sees during checkout.', 'zco'),
				'default'     => __('Zaver Checkout', 'zco'),
			],
			'description' => [
				'type'    => 'textarea',
				'default' => '',
				'title'   => __('Customer Message', 'zco'),
			],
			'test_mode' => array(
				'type'        => 'checkbox',
				'default'     => 'no',
				'desc_tip'    => true,
				'title'       => __('Test mode', 'zco'),
				'label'       => __('Enable test mode', 'zco'),
				'description' => __('If you received any test credentials from Zaver, this checkbox should be checked.', 'zco'),
			),
			'api_key' => [
				'type'        => 'text',
				'desc_tip'    => true,
				'title'       => __('API Key', 'zco'),
				'description' => __('The API key you got from Zaver.', 'zco'),
			],
			'callback_token' => [
				'type'        => 'text',
				'desc_tip'    => true,
				'title'       => __('Callback Token', 'zco'),
				'description' => __('The callback token you got from Zaver.', 'zco'),
			],
		];
	}

	public function process_payment($order_id): array {
		$order = wc_get_order($order_id);

		$merchant_urls = MerchantUrls::create()
			->setCallbackUrl($this->get_callback_url($order))
			->setSuccessUrl($this->get_return_url($order));

		$payment = PaymentCreationRequest::create()
			->setMerchantPaymentReference($order->get_order_number())
			->setAmount($order->get_total())
			->setCurrency($order->get_currency())
			->setMerchantUrls($merchant_urls)
			->setMerchantMetadata([
				'originPlatform' => 'woocommerce',
				'originWebsite' => home_url(),
				'originPage' => $order->get_created_via(),
				'customerId' => (string)$order->get_customer_id(),
				'orderId' => (string)$order->get_id(),
			])
			->setTitle($this->get_purchase_title($order))
			->setDescription($this->get_purchase_description($order));

		$response = $this->api()->createPayment($payment);
		$order->update_meta_data('_zaver_payment', [
			'id' => $response->getPaymentId(),
			'token' => $response->getToken(),
			'tokenValidUntil' => $response->getValidUntil()
		]);
		$order->save_meta_data();

		return [
			'result' => 'success',
			'redirect' => $order->get_checkout_payment_url(true)
		];
	}

	public function api(): Checkout {
		if(is_null($this->api_instance)) {
			$this->api_instance = new Checkout($this->get_option('api_key', ''), $this->get_option('test_mode') === 'yes');
		}

		return $this->api_instance;
	}

	public function receive_payment_callback(): PaymentStatusResponse {
		return $this->api()->receiveCallback($this->get_option('callback_token'));
	}

	private function get_purchase_title(WC_Order $order): string {
		$items = $order->get_items();

		// If there's only one order item, return it as title
		if(count($items) === 1) {
			return reset($items)->get_name();
		}

		// If there's multiple order items, return a generic title
		return sprintf(__('Order %s', 'zco'), $order->get_order_number());
	}

	private function get_purchase_description(WC_Order $order): string {
		/** @var \WC_Order_Item_Product[] */
		$items = $order->get_items();
		$lines = [];

		foreach($items as $item) {
			$lines[] = sprintf('%d x %s', $item->get_quantity(), $item->get_product()->get_sku());
		}

		return implode("\n", $lines);
	}

	private function get_callback_url(WC_Order $order): string {
		return add_query_arg([
			'wc-api' => 'zaver_payment_callback',
			'key' => $order->get_order_key()
		], home_url());
	}
}