<?php
/*
 * Plugin Name: CGC RCP Stripe Control
 * Description: Complete control of RCP subscriptions via Stripe for users
 * Version: 0.1
 * Author: Pippin Williamson
 */

/*
 * NOTES
 *
 * The update card form is processed by RCP Stripe, this simply controls the HTML.
 *
 *
 */



function cgc_rcp_sub_control_shortcode() {

	global $user_ID, $rcp_options;

	// Bail if RCP Stripe is not active
	if( ! function_exists( 'rcp_stripe_is_customer' ) )
		return;


	$stripe_id = rcp_get_stripe_customer_id( $user_ID );

	if( isset( $rcp_options['stripe_test_mode'] ) ) {
		$secret_key = trim( $rcp_options['stripe_test_secret'] );
		$publishable_key = trim( $rcp_options['stripe_test_publishable'] );
	} else {
		$secret_key = trim( $rcp_options['stripe_live_secret'] );
		$publishable_key = trim( $rcp_options['stripe_live_publishable'] );
	}

	Stripe::setApiKey( $secret_key );
	$stripe_customer = Stripe_Customer::retrieve( $stripe_id );

	if( rcp_stripe_is_customer( $user_ID ) ) : ?>
		<h3>Subscription</h3>
		<form id="cgc_rcp_subscription">
			<fieldset id="subscription">
				<label for="subscription_1">
					<input type="radio" id="subscription_1" name="subscription_level" value="1"/>
					Monthly
				</label>
				<label for="subscription_2">
					<input type="radio" id="subscription_2" name="subscription_level" value="2"/>
					Quarterly
				</label>
				<label for="subscription_3">
					<input type="radio" id="subscription_3" name="subscription_level" value="3"/>
					Yearly
				</label>
				<span id="subscription_price">$18</span>
				<input id="edit-subscription" type="submit" class="cancel" name="end_subscription" value="End Payments"/>
				<input id="edit-subscription" type="submit" class="update" name="submit_subscription_edit" value="Edit"/>
			</fieldset>
		</form>
		<h3>Stored Card Details</h3>
		<script type="text/javascript">
			var rcp_stripe_vars;

			// this identifies your website in the createToken call below
			Stripe.setPublishableKey('<?php echo $publishable_key; ?>');

			function stripeResponseHandler(status, response) {
				if (response.error) {
					// re-enable the submit button
					jQuery('#rcp_update_card').attr("disabled", false);

					// show the errors on the form
					jQuery(".card-errors").html(response.error.message);
					jQuery('#rcp_ajax_loading').hide();

				} else {
					jQuery('#rcp_ajax_loading').hide();
					var form$ = jQuery("#rcp_stripe_card_form");
					// token contains id, last4, and card type
					var token = response['id'];
					// insert the token into the form so it gets submitted to the server
					form$.append("<input type='hidden' name='stripeToken' value='" + token + "' />");

					// and submit
					form$.get(0).submit();

				}
			}

			jQuery(document).ready(function($) {

				$("#rcp_update_card").on('click', function(event) {

					$('#rcp_ajax_loading').show();
					// createToken returns immediately - the supplied callback submits the form if there are no errors
					Stripe.createToken({
						number: $('.card-number').val(),
						cvc: $('.card-cvc').val(),
						exp_month: $('.card-expiry-month').val(),
						exp_year: $('.card-expiry-year').val()
					}, stripeResponseHandler);
					return false; // submit from callback
				});
			});

		</script>

		<h3>Your Stored Card Info</h3>

		<ul id="rcp_stripe_card_info">
			<li>Type: <strong><?php echo $stripe_customer->active_card->type; ?></strong></li>
			<li>Last four digits: <strong><?php echo $stripe_customer->active_card->last4; ?></strong></li>
			<li>Expiration: <strong><?php echo $stripe_customer->active_card->exp_month . ' / ' . $stripe_customer->active_card->exp_year; ?></strong></li>
		</ul>

		<h3>Update Your Stored Card</h3>
		<form id="rcp_stripe_card_form" class="rcp_form" action="" method="POST">
			<div class="card-errors"></div>
			<p>
		        <label>Card Number</label>
		        <input type="text" size="20" autocomplete="off" class="card-number" />
		    </p>
		    <p>
		        <label>CVC</label>
		        <input type="text" size="4" autocomplete="off" class="card-cvc" />
		    </p>
		    <p>
		    	<select class="card-expiry-month">
					<?php for( $i = 1; $i <= 12; $i++ ) : ?>
						<option value="<?php echo $i; ?>"><?php echo $i . ' - ' . rcp_get_month_name( $i ); ?></option>
					<?php endfor; ?>
				</select>
				<span> / </span>
				<select class="card-expiry-year">
					<?php
					$year = date( 'Y' );
					for( $i = $year; $i <= $year + 10; $i++ ) : ?>
						<option value="<?php echo $i; ?>"><?php echo $i; ?></option>
					<?php endfor; ?>
				</select>
		    </p>
			<p>
				<input type="hidden" name="rcp_card_action" value="update"/>
				<input type="hidden" name="rcp_customer_id" value="<?php echo $stripe_customer->id; ?>"/>
				<input type="hidden" name="rcp_card_nonce" value="<?php echo wp_create_nonce('rcp-card-nonce'); ?>"/>
				<input type="submit" id="rcp_update_card" value="Save Card Info"/>
			</p>
			<p><img src="<?php echo RCP_STRIPE_URL; ?>/images/loading.gif" style="display:none;" id="rcp_ajax_loading"/></p>
		</form>
	<?php endif; // End Stripe customer check ?>

	<h3>Payment History</h3>
	<?php echo rcp_print_user_payments( $user_ID ); ?>
	<p>Billing trouble? <a href="#">Contact support</a>.</p>
<?php
}
