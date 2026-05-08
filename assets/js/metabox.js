/**
 * @var zaverMetaboxParams
 */
jQuery(function ($) {
	const zaverMetabox = {
		init: function () {
			$(document).on("click", ".zaver-toggle-order-management", this.toggleOrderManagement);
		},

		toggleOrderManagement: async function (e) {
			e.preventDefault();
			const $this = $(this);
			const $metabox = $("#" + zaverMetaboxParams.metaboxId);
			const enabled = $this.attr("data-zaver-order-management") === "yes" ? "no" : "yes";

			// Block the metabox to prevent changes during the request.
			$metabox.block({
				message: null,
				overlayCSS: {
					background: "#fff",
					opacity: 0.6,
				},
			});

			const result = await zaverMetabox.ajaxSetOrderManagement(enabled);
			if (result && result.success) {
				zaverMetabox.toggleButton($this, enabled);
			} else {
				alert("Failed to toggle order management. Please try again.");
			}

			// Reload to ensure the UI fully reflects the new state.
			location.reload();
		},

		toggleButton: function ($button, enabled) {
			$button
				.attr("data-zaver-order-management", enabled)
				.toggleClass("woocommerce-input-toggle--enabled")
				.toggleClass("woocommerce-input-toggle--disabled");
		},

		ajaxSetOrderManagement: async function (enabled) {
			const orderId = zaverMetaboxParams.orderId;
			const { url, action, nonce } = zaverMetaboxParams.ajax.setOrderManagement;

			return $.ajax({
				type: "POST",
				url: url,
				data: {
					nonce: nonce,
					action: action,
					order_id: orderId,
					enabled: enabled,
				},
			});
		},
	};

	zaverMetabox.init();
});
