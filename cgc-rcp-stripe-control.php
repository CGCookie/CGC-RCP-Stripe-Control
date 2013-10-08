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


	if( isset( $_GET['message'] ) ) : ?>
	<div id="cgc_subscription_messages">
		<?php
			$type = 'success';
			switch( $_GET['message'] ) :

				case 1 :
					$message = 'Your subscription has been successfully updated.';
					break;

				case 2 :
					$message = 'Your payments have been stopped. You will not be billed again.';
					break;

				case 3 :
					$message = 'Your payments have been restarted.';
					break;

				case 4 :
					$message = 'Your stored card details have been updated.';
					break;

				case 5 :
					$message = 'The password you entered was incorrect.';
					$type    = 'error';
					break;

			endswitch;

			echo '<p class="' . $type . '">' . $message . '</p>';
		?>
	</div>
	<?php endif; ?>
	<div id="cgc_subscription_overview">
		<div id="subscription_details">
			<div class="level"><?php echo rcp_get_subscription( $user_ID ); ?></div>
			<div class="level-price">$<?php echo rcp_get_subscription_price( rcp_get_subscription_id( $user_ID ) ); ?></div>
			<div class="next-pay-date">Next payment date: <?php echo rcp_get_expiration_date( $user_ID ); ?></div>
		</div>
		<button id="edit-subscription">Edit</button>
	</div>

<?php
	if( rcp_stripe_is_customer( $user_ID ) ) : ?>
		<form id="cgc_rcp_subscription" method="post" >
			<fieldset id="subscription">
				<?php foreach( rcp_get_subscription_levels( 'active' ) as $level ) : ?>
					<?php if( $level->price == 0 ) { continue; } ?>
					<label for="subscription_<?php echo $level->id; ?>">
						<input type="radio" id="subscription_<?php echo $level->id; ?>" name="subscription_level" value="<?php echo $level->id; ?>"<?php checked( $level->id, rcp_get_subscription_id( $user_ID ) ); ?>/>
						<span class="subscription-name"><?php echo $level->name; ?></span>
						<span class="subscription-price"><?php echo rcp_currency_filter( $level->price ); ?></span>
					</label>
				<?php endforeach; ?>
				<input type="hidden" name="cus_id" value="<?php echo $stripe_id; ?>"/>
				<input type="hidden" name="update_subscription" value="1"/>
				<input id="edit-subscription" type="submit" class="update" name="submit_subscription_edit" value="Update"/>
				<?php if( ! rcp_is_recurring( $user_ID ) && get_user_meta( $user_ID, '_rcp_stripe_sub_cancelled', true ) ) : ?>
					<input id="restart-subscription" type="submit" class="cancel" name="submit_subscription_restart" value="Restart Payments"/>
				<?php else: ?>
					<input id="end-subscription" type="submit" class="cancel" name="submit_subscription_end" value="End Payments"/>
				<?php endif; ?>
				<button class="cancel" id="nevermind">Never Mind</button>

			</fieldset>
		</form>
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
						name: $('.card-name').val(),
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
			<?php $card = $stripe_customer->cards->retrieve( $stripe_customer->default_card ); ?>
			<li>Type: <strong><?php echo $card->type; ?></strong></li>
			<li>Name on the card: <strong><?php echo $card->name; ?></strong></li>
			<li>Last four digits: <strong><?php echo $card->last4; ?></strong></li>
			<li>Expiration: <strong><?php echo $card->exp_month . ' / ' . $card->exp_year; ?></strong></li>
		</ul>

		<h3>Update Your Stored Card</h3>
		<form id="rcp_stripe_card_form" class="rcp_form" action="" method="POST">
			<div class="card-errors"></div>
			<p>
		        <label>Name on the Card</label>
		        <input type="text" size="20" autocomplete="off" class="card-name" />
		    </p>
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


function cgc_rcp_process_sub_changes() {

	if( ! isset( $_POST['update_subscription'] ) )
		return;

	if( ! is_user_logged_in() )
		return;

	$user_id = get_current_user_id();
	$customer_id = rcp_get_stripe_customer_id( $user_id );

	// Ensure the posted customer ID matches the ID stored for the currently logged-in user
	if( $customer_id != $_POST['cus_id'] )
		return;

	global $rcp_options;

	// Grab the Stripe API key
	if( isset( $rcp_options['stripe_test_mode'] ) ) {
		$secret_key = trim( $rcp_options['stripe_test_secret'] );
	} else {
		$secret_key = trim( $rcp_options['stripe_live_secret'] );
	}

	if( isset( $_POST['submit_subscription_edit'] ) ) {
		$action = 'edit';
	} elseif( isset( $_POST['submit_subscription_end'] ) ) {
		$action = 'cancel';
	} elseif( isset( $_POST['submit_subscription_restart'] ) ) {
		$action = 'restart';
	}

	Stripe::setApiKey( $secret_key );

	$customer = Stripe_Customer::retrieve( $customer_id );
	$plan     = rcp_get_subscription_details( absint( $_POST['subscription_level'] ) );
	$plan_id  = strtolower( str_replace( ' ', '', $plan->name ) );

	switch( $action ) {

		// Edit a subscription
		case 'edit' :

			// TODO: support lifetime

			$customer->updateSubscription( array( 'plan' => $plan_id, 'prorate' => true ) );
			update_user_meta( $user_id, 'rcp_subscription_level', absint( $_POST['subscription_level'] ) );
			$exp = rcp_calc_member_expiration( rcp_get_subscription_details( absint( $_POST['subscription_level'] ) ) );
			update_user_meta( $user_id, 'rcp_expiration', $exp );

			wp_redirect( home_url( '/settings/?message=1#subscription' ) ); exit;

			break;


		// Cancel a subscription
		case 'cancel' :

			$customer->cancelSubscription( array( 'at_period_end' => true ) );

			// the subscription is not cancelled until period comes to an end
			update_user_meta( $user_id, '_rcp_stripe_sub_cancelled', 'yes' );
			update_user_meta( $user_id, 'rcp_recurring', 'no' );

			wp_redirect( home_url( '/settings/?message=2#subscription' ) ); exit;

			break;

		case 'restart' :

			$customer->updateSubscription( array( 'plan' => $plan_id, 'prorate' => true ) );
			delete_user_meta( $user_id, '_rcp_stripe_sub_cancelled' );
			update_user_meta( $user_id, 'rcp_recurring', 'yes' );

			wp_redirect( home_url( '/settings/?message=3#subscription' ) ); exit;

			break;
	}


}
add_action( 'init', 'cgc_rcp_process_sub_changes' );