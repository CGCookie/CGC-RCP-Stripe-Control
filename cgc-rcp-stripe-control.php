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

function cgc_rcp_remove_actions() {
	remove_action('rcp_before_registration_submit_field', 'rcp_stripe_form_fields');
}
add_action( 'init', 'cgc_rcp_remove_actions' );


function cgc_rcp_process_free_signup() {

	$user_email = sanitize_text_field( $_POST['rcp_user_email'] );
	$user_login = sanitize_text_field( $_POST['rcp_user_login'] );
	$user_pass  = sanitize_text_field( $_POST['rcp_user_pass'] );
	$user_pass2 = sanitize_text_field( $_POST['rcp_user_pass_confirm'] );

	if( ! empty( $_POST['cookie_jar'] ) ) {
		rcp_errors()->add( 'bot', __( 'Nice try Mr. Robot', 'rcp' ), 'free_register' );
	}

	if( username_exists( $user_login ) ) {
		// Username already registered
		rcp_errors()->add( 'username_unavailable', __( 'Username already taken', 'rcp' ), 'free_register' );
	}
	if( ! validate_username( $user_login ) ) {
		// invalid username
		rcp_errors()->add( 'username_invalid', __( 'Invalid username', 'rcp' ), 'free_register' );
	}
	if( empty( $user_login ) ) {
		// empty username
		rcp_errors()->add( 'username_empty', __( 'Please enter a username', 'rcp' ), 'free_register' );
	}
	if( ! is_email( $user_email ) ) {
		//invalid email
		rcp_errors()->add( 'email_invalid', __( 'Invalid email', 'rcp' ), 'free_register' );
	}
	if( email_exists( $user_email ) ) {
		//Email address already registered
		rcp_errors()->add( 'email_used', __( 'Email already registered', 'rcp' ), 'free_register' );
	}
	if( empty( $user_pass ) ) {
		// passwords is empty
		rcp_errors()->add( 'password_empty', __( 'Please enter a password', 'rcp' ), 'free_register' );
	}
	if( empty( $user_pass2 ) ) {
		// passwords is empty
		rcp_errors()->add( 'password_confirm_empty', __( 'Please confirm your password', 'rcp' ), 'free_register' );
	}

	if( $user_pass2 !== $user_pass ) {
		// passwords is empty
		rcp_errors()->add( 'password_mismatch', __( 'Your passwords do not match', 'rcp' ), 'free_register' );
	}

	// retrieve all error messages, if any
	$errors = rcp_errors()->get_error_messages();

	// only create the user if there are no errors
	if( empty( $errors ) ) {
		$user_id = wp_insert_user( array(
				'user_login'		=> $user_login,
				'user_pass'	 		=> $user_pass,
				'user_email'		=> $user_email,
				'user_registered'	=> date( 'Y-m-d H:i:s' ),
				'role'				=> 'subscriber'
			)
		);

		$subscription_key = rcp_generate_subscription_key();
		update_user_meta( $user_id, 'rcp_subscription_key', $subscription_key );
		update_user_meta( $user_id, 'rcp_subscription_level', 1 );
		rcp_set_status( $user_id, 'free' );

		$creds = array();
		$creds['user_login'] = $user_login;
		$creds['user_password'] = $user_pass;
		$creds['remember'] = false;
		$user = wp_signon( $creds, false );
		die('1');
	} else {
		echo rcp_show_error_messages( 'free_register' ); exit;
	}
	die('-1');
}
add_action( 'wp_ajax_nopriv_rcp_register_free', 'cgc_rcp_process_free_signup' );

function cgc_rcp_force_auto_renew( $data ) {
	$data['auto_renew'] = 1;
	return $data;
}
add_filter( 'rcp_subscription_data', 'cgc_rcp_force_auto_renew' );

function cgc_rcp_filter_username_length( $user ) {
	if( strlen( $user['login'] ) < 4 && ! empty( $user['need_new'] ) ) {
		rcp_errors()->add( 'short_username', 'Username is too short. It must be at least 4 characters.', 'register' );
	}

	return $user;
}
add_filter( 'rcp_user_registration_data', 'cgc_rcp_filter_username_length' );


function cgc_rcp_sub_control_shortcode() {

	global $user_ID, $rcp_options;

	// Bail if RCP Stripe is not active
	if( ! function_exists( 'rcp_stripe_is_customer' ) )
		return;


	$current_level = rcp_get_subscription_details( rcp_get_subscription_id( $user_ID ) );
	$levels = rcp_get_subscription_levels( 'active' );


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

				case 6 :
					$message = 'Grimlims got in the way and something went wrong. Please try again.';
					$type    = 'error';
					break;

			endswitch;

			echo '<p class="' . $type . '">' . $message . '</p>';
		?>
	</div>
	<?php endif; ?>

	<?php if( ! rcp_stripe_is_customer( $user_ID ) ) : // For PayPal users ?>
		<div id="cgc_subscription_overview">
			<?php if( $current_level->name == 'Lifetime' ) : ?>
				<?php echo cgc_rcp_lifetime_message(); ?>
			<?php else : ?>
				<ul id="subscription_details">
					<li class="level">
						<span>My Subscription: </span>
						<span class="level-name"><?php echo $current_level->name; ?></span>
					</li>
					<li class="level-price">
						<span>Amount: </span>
						<span class="amount">
							<span>$<?php echo rcp_get_subscription_price( $current_level->id ); ?> </span>
							<span>for <?php echo $current_level->duration . ' ' . rcp_filter_duration_unit( $current_level->duration_unit, $current_level->duration ); ?></span>
						</span>
					</li>
					<li class="next-pay-date">
						<span>Next payment date: </span>
						<span class="payment-date"><?php echo rcp_get_expiration_date( $user_ID ); ?></span>
					</li>
				</ul>
				<p>If you wish to cancel your PayPal subscription, click the link below, log into your PayPal account, navigate to Profile > My Money > My Preapproved Payments and locate the subscription for CG Cookie.</p>
				<p class="paypal-cancel">
					<a href="https://www.paypal.com/cgi-bin/customerprofileweb?cmd=_manage-paylist" target="_blank">Cancel Subscription</a>
				</p>
			<?php endif; ?>
		</div>
	<?php elseif( rcp_stripe_is_customer( $user_ID ) ) : ?>

		<?php
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
		?>

		<script type="text/javascript">
			var rcp_stripe_vars, cgc_scripts;

			// this identifies your website in the createToken call below
			Stripe.setPublishableKey('<?php echo $publishable_key; ?>');

			function stripeResponseHandler(status, response) {
				if (response.error) {
					// re-enable the submit button
					jQuery('#rcp_update_card').attr("disabled", false);

					// show the errors on the form
					jQuery(".card-errors").addClass('error').html(response.error.message);
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

				var current_sub_id = $('#current_sub_id').val();

				// Toggle subscription edit
				$('.toggle-subscription-edit').click(function(e) {
					e.preventDefault();
					$('#sub-edit-submit,#cancel-edit-subscription,#edit-subscription').toggle();
					$('#cgc_subscription_overview .level-name, #subscription_options_menu').toggle();
				});

				$('#cancel-edit-subscription').click(function(e) {
					e.preventDefault();
					var price = $('#subscription_price_' + current_sub_id).val();
					var exp   = $('#subscription_expiration_' + current_sub_id).val();
					$('#cgc_subscription_overview .level-amount').text( price );
					$('#cgc_subscription_overview .payment-date').text( exp );
					$('#cgc_subscription_overview li').removeClass('modified');
					$('#sub-update-message').hide();
					$('#edit-subscription').show();
				})

				// Update price and payment due date when changing subscriptions
				$('#subscription_options_menu input').click(function() {
					var sub_id  = $(this).val();
					var price   = $('#subscription_price_' + sub_id).val();
					var exp     = $('#subscription_expiration_' + sub_id).val();
					var currrent_exp  = $('#subscription_expiration_' + current_sub_id).val();
					var current_level = $('#current_sub_name').val();
					var new_level     = $('#subscription_options_menu input:checked').val();

					if( new_level == 'Lifetime' ) {

						var message = 'Your subscription will be changed from ' + current_level + ' to ' + new_level + '. You will be billed $630 immediately but will never be billed again. Note, your current subscription will be cancelled and you will receive an email alerting you of the cancellation, then you will receive a second email alerting you of the Lifetime activation. Click Update below to confirm the change to your subscription.';

					} else if ( new_level == 'Cancel Subscription' ) {

						var message = 'Your subscription payments will be cancelled immediately but you will retain access to all Citizen content until the end of term, ' + currrent_exp;

					} else {
						var message = 'Your subscription will be changed from ' + current_level + ' to ' + new_level + '. You will now be billed ' + price + '. Click Update below to confirm the change to your subscription.';
					}

					if( new_level == 'Cancel Subscription' || new_level == 'Lifetime' ) {
						$('#cgc_subscription_overview .level-price, #cgc_subscription_overview .next-pay-date').hide();
					} else {

						$('#cgc_subscription_overview .level-amount').text( price ).addClass('modified');
						$('#cgc_subscription_overview .payment-date').text( exp ).addClass('modified');
						$('#cgc_subscription_overview .level-amount, #cgc_subscription_overview .next-pay-date').show().addClass('modified');
					}
					if ( new_level != current_level){
						$('#submit-wrap').show();
						$('#sub-update-message').text( message ).slideDown();
					}
					if ( new_level == current_level ) {
						$('#sub-update-message').hide();
						$('#submit-wrap').hide();
						$('#cgc_subscription_overview li').removeClass('modified');
					}

					$('#subscription_options_menu').hide();
					$('#cgc_subscription_overview .level-name').text( new_level );

				});

				$('#sub-edit-submit').click(function(e) {
					e.preventDefault();
					$("#sub-edit-confirm-modal").reveal("open");
				});

				// Subscription edit submission
				$('#cgc_rcp_subscription').submit(function(e) {
					e.preventDefault();
					var form$ = $(this);
					var data = {
						action: 'validate_subscription_password',
						pass: $('#pass').val()
					};
					$.post(cgc_scripts.ajaxurl, data, function(response) {
						console.log( response );
						if( response == 'valid' ) {
							form$.get(0).submit();
						} else {
							$('#pass').append( '<div class="error">The password you entered is incorrect.</div>');
						}
					});
				});

				// Change active card form
				$("#rcp_update_card").on('click', function(event) {

					$('#rcp_ajax_loading').show();
					// createToken returns immediately - the supplied callback submits the form if there are no errors
					Stripe.createToken({
						name: $('.card-name').val(),
						number: $('.card-number').val(),
						cvc: $('.card-cvc').val(),
						exp_month: $('.card-expiry-month').val(),
						exp_year: $('.card-expiry-year').val(),
						address_zip: $('.card-zip').val()
					}, stripeResponseHandler);
					return false; // submit from callback
				});
			});

		</script>

		<?php if( $current_level->name == 'Lifetime' ) : ?>
			<?php echo cgc_rcp_lifetime_message(); ?>
		<?php else : ?>
			<form id="cgc_rcp_subscription" method="post">
				<div id="cgc_subscription_overview">
					<table id="cgc_current_subscription">
						<thead>
							<tr>
								<th>Current Subscription</th>
								<th>Amount</th>
								<th colspan="2">
									<?php if( rcp_is_recurring( $user_ID ) ) : ?>
									<span>Next Payment:</span>
									<?php else : ?>
									<span>Expiration:</span>
									<?php endif; ?>
								</th>
							</tr>
						</thead>
						<tbody>
							<tr>
								<td>
									<span class="level-name level-value"><?php echo rcp_get_subscription( $user_ID ); ?></span>
									<div id="subscription_options_menu" style="display:none;">
										<span class="sub_level_current">
											<span class="sub_level_current_name"><?php echo $current_level->name; ?></span>
											<span class="sub_level_current_label">Current Plan</span>
										</span>
										<?php foreach( $levels as $level ) : ?>
											<?php if( $level->price == 0 || $level->id == $current_level->id ) { continue; } ?>
											<input type="radio" name="subscription_level" id="sub_level_<?php echo $level->id; ?>" value="<?php echo $level->id; ?>"/>
											<label for="sub_level_<?php echo $level->id; ?>">
												<?php echo $level->name; ?>
												<span class="sub_level_description"><?php echo $level->description; ?></span>
												<span class="sub_level_description">$<?php echo $level->price; ?>/<?php echo $level->duration_unit; ?></span>
											</label>
										<?php endforeach; ?>
										<?php if( rcp_is_recurring( $user_ID ) ) : ?>
											<input type="radio" id="sub_level_cancel" name="subscription_level" value="x">
											<label for="sub_level_cancel">Cancel Citizen Subscription</label>
										<?php endif; ?>
									</div>
								</td>
								<td>
									<span class="level-amount level-value">$<?php echo $current_level->price; ?> for <?php echo $current_level->duration . ' ' . rcp_filter_duration_unit( $current_level->duration_unit, $current_level->duration ); ?></span>
								</td>
								<td>
									<span class="payment-date level-value"><?php echo rcp_get_expiration_date( $user_ID ); ?></span>
								</td>
								<td>
									<button id="edit-subscription" class="toggle-subscription-edit">
										<i class="icon-pencil"></i> Edit
									</button>
								</td>
							</tr>
						</tbody>
					</table>

					<div id="sub-update-message" class="info_message" style="display:none;">
						<!--filled via jQuery-->
					</div>
					<div id="submit-wrap">

						<button id="sub-edit-submit" style="display:none">Confirm</button>
						<button id="cancel-edit-subscription" class="toggle-subscription-edit" style="display:none">Nevermind</button>

						<div id="sub-edit-confirm-modal" class="reveal-modal">
							<h3>Confirm Subscription Change</h3>
							<p>Please enter your password to confirm that you wish to change your subscription.</p>
							<label for="pass">Password:</label>
							<input type="password" id="pass" name="pass" value=""/>
							<input type="hidden" name="cus_id" value="<?php echo $stripe_id; ?>"/>
							<input type="hidden" id="current_sub_id" name="current_sub_id" value="<?php echo rcp_get_subscription_id( $user_ID ); ?>"/>
							<input type="hidden" id="current_sub_name" name="current_sub_name" value="<?php echo rcp_get_subscription( $user_ID ); ?>"/>
							<input type="hidden" name="update_subscription" value="1"/>
							<input id="edit-subscription" type="submit" class="update" name="submit_subscription_edit" value="Update"/>
							<a href="#" class="close-reveal-modal close"><i class="icon-remove"></i></a>
						</div>

						<?php // Now output hidden values ?>
						<?php foreach( $levels as $level ) : ?>
							<?php if( $level->price == 0 ) { continue; } ?>
							<input type="hidden" id="subscription_price_<?php echo $level->id; ?>" value="$<?php echo rcp_get_subscription_price( $level->id ); ?> for <?php echo $level->duration . ' ' . rcp_filter_duration_unit( $level->duration_unit, $level->duration ); ?>"/>
							<input type="hidden" id="subscription_expiration_<?php echo $level->id; ?>" value="<?php echo date( 'F j, Y', strtotime( rcp_calc_member_expiration( $level ) ) ); ?>"/>
						<?php endforeach; ?>

					</div>

				</div>

			</form>

			<div class="stored-card-info">
				<h4 class="setting-title">Your Stored Card Info</h4>
					<a href="#" class="update-toggle"><i class="icon-pencil"></i> Update Card Information</a>
					<a href="#" class="update-toggle nevermind"><i class="icon-remove"></i> Nevermind</a>

				<ul id="rcp_stripe_card_info">
					<?php $card = $stripe_customer->cards->retrieve( $stripe_customer->default_card ); ?>
					<li>Type: <span><?php echo $card->type; ?></span></li>
					<li>Name on the card: <span><?php echo $card->name; ?></span></li>
					<li>Last four digits: <span><?php echo $card->last4; ?></span></li>
					<li>Expiration: <span><?php echo $card->exp_month . ' / ' . $card->exp_year; ?></span></li>
				</ul>
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
				        <label>Zip / Postal Code</label>
				        <input type="text" size="4" autocomplete="off" class="card-zip" />
				    </p>
					<p>
						<input type="hidden" name="rcp_card_action" value="update"/>
						<input type="hidden" name="rcp_customer_id" value="<?php echo $stripe_customer->id; ?>"/>
						<input type="hidden" name="rcp_card_nonce" value="<?php echo wp_create_nonce('rcp-card-nonce'); ?>"/>
						<input type="submit" id="rcp_update_card" value="Save Card Info"/>
					</p>
					<p><img src="<?php echo RCP_STRIPE_URL; ?>/images/loading.gif" style="display:none;" id="rcp_ajax_loading"/></p>
				</form>
			</div>
		<?php endif; // End lifetime check ?>
	<?php endif; // End Stripe customer check ?>

	<?php if( class_exists( 'RCP_Payments' ) ) : ?>
		<h3>Payment History</h3>
		<?php
		$payments_db = new RCP_Payments;
		$payments = $payments_db->get_payments( array( 'user_id' => $user_ID ) );
		if( $payments ) :
			foreach( $payments as $payment ) :
				echo '<div class="member_payment">';
					echo '<span class="payment-date">' . date( 'F j, Y', strtotime( $payment->date ) ) . '</span>';
					echo '<span class="payment-sep">&nbsp;&ndash;&nbsp;</span>';
					echo '<span class="payment-duration">' . $payment->subscription . '</span>';
					echo '<span class="payment-sep">&nbsp;&ndash;&nbsp;</span>';
					echo '<span class="payment-amount">$' . number_format( $payment->amount, 2 ) . '</span>';
				echo '</div>';
			endforeach;
		endif;

		?>
	<?php endif; ?>
	<p>Billing trouble? <a href="#" onClick="script: Zenbox.show(); return false;">Contact support</a>.</p>
<?php
}

function cgc_rcp_lifetime_message() {
?>
	<div id="lifetime_member">
		<p>You are a lifetime member, congratulations on never needing to make a payment again!</p>
	</div>
<?php
}


function cgc_rcp_process_sub_changes() {

	if( ! isset( $_POST['update_subscription'] ) )
		return;

	if( ! is_user_logged_in() )
		return;

	$user_id = get_current_user_id();
	$user    = get_userdata( $user_id );
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

	$action = isset( $_POST['subscription_level'] ) ? $_POST['subscription_level'] : false;

	if( $action == 'x' ) {
		$action = 'cancel';
	} elseif( $action == 10 ) {
		$action = 'upgrade_to_lifetime';
	} elseif( $action && ( ! rcp_is_recurring( $user_id ) || ! rcp_is_active( $user_id ) ) ) {
		$action = 'restart';
	} elseif( $action ) {
		$action = 'edit';
	}

	Stripe::setApiKey( $secret_key );

	$customer = Stripe_Customer::retrieve( $customer_id );
	$plan     = rcp_get_subscription_details( absint( $_POST['subscription_level'] ) );
	$plan_id  = strtolower( str_replace( ' ', '', $plan->name ) );

	if( ! wp_check_password( $_POST['pass'], $user->user_pass, $user->ID ) ) {
		wp_redirect( home_url( '/settings/?message=5#subscription' ) ); exit;
	}

	if( ! $action ) {
		wp_redirect( home_url( '/settings/?message=6#subscription' ) ); exit;
	}

	switch( $action ) {

		// Edit a subscription
		case 'edit' :

			$customer->updateSubscription( array( 'plan' => $plan_id, 'prorate' => true ) );
			update_user_meta( $user_id, 'rcp_subscription_level', absint( $_POST['subscription_level'] ) );
			$exp = rcp_calc_member_expiration( rcp_get_subscription_details( absint( $_POST['subscription_level'] ) ) );
			update_user_meta( $user_id, 'rcp_expiration', $exp );
			rcp_set_status( $user_id, 'active' );

			do_action( 'cgc_rcp_subscription_changed', $user_id, $plan_id );

			wp_redirect( home_url( '/settings/?message=1#subscription' ) ); exit;

			break;

		case 'upgrade_to_lifetime' :

			$customer->cancelSubscription( array( 'at_period_end' => false ) );

			Stripe_InvoiceItem::create( array(
					'customer'    => $customer->id,
					'amount'      => 630 * 100,
					'currency'    => 'usd',
					'description' => 'CG Cookie Lifetime Citizen Upgrade'
				)
			);

			// Create the invoice containing taxes / discounts / fees
			$invoice = Stripe_Invoice::create( array(
				'customer' => $customer->id, // the customer to apply the fee to
			) );
			$invoice->pay();

			// the subscription is not cancelled until period comes to an end
			update_user_meta( $user_id, '_rcp_stripe_sub_cancelled', 'yes' );
			delete_user_meta( $user_id, 'rcp_recurring' );
			update_user_meta( $user_id, 'rcp_subscription_level', absint( $_POST['subscription_level'] ) );
			update_user_meta( $user_id, 'rcp_expiration', 'none' );
			rcp_set_status( $user_id, 'active' );

			do_action( 'cgc_rcp_subscription_upgrade_to_lifetime', $user_id );

			wp_redirect( home_url( '/settings/?message=1#subscription' ) ); exit;

			break;


		// Cancel a subscription
		case 'cancel' :

			$customer->cancelSubscription( array( 'at_period_end' => true ) );

			// the subscription is not cancelled until period comes to an end
			update_user_meta( $user_id, '_rcp_stripe_sub_cancelled', 'yes' );
			delete_user_meta( $user_id, 'rcp_recurring' );

			do_action( 'cgc_rcp_subscription_cancelled', $user_id );

			wp_redirect( home_url( '/settings/?message=2#subscription' ) ); exit;

			break;

		case 'restart' :

			$customer->updateSubscription( array( 'plan' => $plan_id, 'prorate' => true ) );
			delete_user_meta( $user_id, '_rcp_stripe_sub_cancelled' );
			update_user_meta( $user_id, 'rcp_recurring', 'yes' );
			update_user_meta( $user_id, 'rcp_subscription_level', absint( $_POST['subscription_level'] ) );
			$exp = rcp_calc_member_expiration( rcp_get_subscription_details( absint( $_POST['subscription_level'] ) ) );
			update_user_meta( $user_id, 'rcp_expiration', $exp );
			rcp_set_status( $user_id, 'active' );

			do_action( 'cgc_rcp_subscription_restarted', $user_id, $plan_id );

			wp_redirect( home_url( '/settings/?message=3#subscription' ) ); exit;

			break;
	}


}
add_action( 'init', 'cgc_rcp_process_sub_changes' );

function cgc_rcp_check_password() {
	if( ! isset( $_POST['pass'] ) )
		die( '-1' );

	$user = get_userdata( get_current_user_id() );

	if( ! $user )
		die( '-1' );

	if( wp_check_password( $_POST['pass'], $user->user_pass, $user->ID ) ) {
		echo 'valid'; exit;
	}

	die( '0' );
}
add_action( 'wp_ajax_validate_subscription_password', 'cgc_rcp_check_password' );


function cgc_rcp_member_row_class( $member ) {
	if( ! function_exists( 'rcp_stripe_is_customer' ) )
		return;

	if( rcp_stripe_is_customer( $member->ID ) ) {
		echo 'stripe';
	} else {
		echo 'paypal';
	}
}
add_action( 'rcp_member_row_class', 'cgc_rcp_member_row_class' );

function cgc_rcp_member_merchant_css() {
	if( isset( $_GET['page'] ) && 'rcp-members' == $_GET['page'] ) {
		echo '<style>.merchant-legend { display: inline-block; height: 10px; width: 10px; }.rcp_row.paypal td, .merchant-legend.paypal { background: #c3cbe8; }.rcp_row.stripe td, .merchant-legend.stripe { background: #e8d5c3; }</style>';
	}
}
add_action( 'admin_head', 'cgc_rcp_member_merchant_css' );

function cgc_rcp_member_merchat_legend() {
	echo '<span class="merchant-legend stripe"></span>Stripe&nbsp;';
	echo '<span class="merchant-legend paypal"></span>PayPal';
}
add_action( 'rcp_members_above_table', 'cgc_rcp_member_merchat_legend' );