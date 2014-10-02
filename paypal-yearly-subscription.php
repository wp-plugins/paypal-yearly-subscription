<?php
/**
 * Plugin Name: PayPal Yearly Subscription
 * Plugin URI: 
 * Description: A very basic plugin to add yearly subscription to a wordpress website
 * Version: 0.1
 * Author: Atilla Ordog
 * Author URI: 
 * License: GPL
 * Text Domain: paypal-yearly-subscription
 */
 
class PayPalYearlySubscription
{
	/**
	 * @var array Plugin settings
	 */
	private $_settings;
	
	/**
	 * Static property to hold our singleton instance
	 * @var wpPayPalFramework
	 */
	static $instance = false;
	
	/**
	 * @var string Name used for options
	 */
	private $_optionsName = 'paypal-yearly-subscription';
	
	/**
	 * @var string Name used for options
	 */
	private $_optionsGroup = 'paypal-yearly-subscription-options';
	
	/**
	 * @var array Endpoints for sandbox and live
	 */
	private $_endpoint = array(
		'sandbox'	=> 'https://api-3t.sandbox.paypal.com/nvp',
		'live'		=> 'https://api-3t.paypal.com/nvp'
	);

	/**
	 * @var array URLs for sandbox and live
	 */
	private $_url = array(
		'sandbox'	=> 'https://www.sandbox.paypal.com/webscr?cmd=_express-checkout&token=',
		'live'		=> 'https://www.paypal.com/webscr?cmd=_express-checkout&token='
	);
	
	private $_currencies = array();
	
	/** 
	 * @var array Current logged in user data
	 */
	private $_user_data = null;
	
	/**
	 * @var array Holds the available user roles
	 */
	private $_current_roles = array();
	
	/**
	 * @var array The default data for the subscription
	 */
	private $_user_subscription_data = array(
		'id' => null,
		'user_id' => 0,
		'from_date' => '',
		'to_date' => '',
		'fee' => 0
	);
	
	/**
	 * @var string Name of the table we store the subscriptions in
	 */
	private $_subscription_table = 'yearly_subscriptions';
	
	/**
	 * This is our constructor, which is private to force the use of
	 * getInstance() to make this a Singleton
	 *
	 * @return PayPalYearlySubscription
	 */
	private function __construct()
	{	
		if (session_id() == "") 
			session_start();
		
		global $wpdb;
		
		$this->_subscription_table = $wpdb->prefix . $this->_subscription_table;
		$this->_db_install();
		
		$this->_getSettings();

		$wp_roles = new WP_Roles();
		$this->_current_roles = $wp_roles->roles;
		
		$this->_currencies = array(
			'AUD'	=> __( 'Australian Dollar', 'paypal-yearly-subscription' ),
			'CAD'	=> __( 'Canadian Dollar', 'paypal-yearly-subscription' ),
			'CZK'	=> __( 'Czech Koruna', 'paypal-yearly-subscription' ),
			'DKK'	=> __( 'Danish Krone', 'paypal-yearly-subscription' ),
			'EUR'	=> __( 'Euro', 'paypal-yearly-subscription' ),
			'HKD'	=> __( 'Hong Kong Dollar', 'paypal-yearly-subscription' ),
			'HUF'	=> __( 'Hungarian Forint', 'paypal-yearly-subscription' ),
			'ILS'	=> __( 'Israeli New Sheqel', 'paypal-yearly-subscription' ),
			'JPY'	=> __( 'Japanese Yen', 'paypal-yearly-subscription' ),
			'MXN'	=> __( 'Mexican Peso', 'paypal-yearly-subscription' ),
			'NOK'	=> __( 'Norwegian Krone', 'paypal-yearly-subscription' ),
			'NZD'	=> __( 'New Zealand Dollar', 'paypal-yearly-subscription' ),
			'PLN'	=> __( 'Polish Zloty', 'paypal-yearly-subscription' ),
			'GBP'	=> __( 'Pound Sterling', 'paypal-yearly-subscription' ),
			'SGD'	=> __( 'Singapore Dollar', 'paypal-yearly-subscription' ),
			'SEK'	=> __( 'Swedish Krona', 'paypal-yearly-subscription' ),
			'CHF'	=> __( 'Swiss Franc', 'paypal-yearly-subscription' ),
			'USD'	=> __( 'U.S. Dollar', 'paypal-yearly-subscription' )
		);
		
		add_action( 'admin_init', array($this,'registerOptions') );
		add_action( 'admin_menu', array($this,'adminMenu') );
		add_filter( 'init', array( $this, 'init_locale' ) );
		
		if ( !shortcode_exists('paypal-yearly-subscription-button') )
		{
			add_shortcode('paypal-yearly-subscription-button', array($this, 'subscription_button'));
		}
		
		if ( !shortcode_exists('paypal-yearly-subscription-return') )
		{
			add_shortcode('paypal-yearly-subscription-return', array($this, 'subscription_return'));
		}
		
		if ( !shortcode_exists('paypal-yearly-subscription-check-subscription') )
		{
			add_shortcode('paypal-yearly-subscription-check-subscription', array($this, 'subscription_check'));
		}
	}
	
	/**
	 * Install the table into DB using dbDelta
	 */
	private function _db_install()
	{
		global $wpdb;

		/*
		 * We'll set the default character set and collation for this table.
		 * If we don't do this, some characters could end up being converted 
		 * to just ?'s when saved in our table.
		 */
		$charset_collate = '';

		if ( ! empty( $wpdb->charset ) ) {
		  $charset_collate = "DEFAULT CHARACTER SET {$wpdb->charset}";
		}

		if ( ! empty( $wpdb->collate ) ) {
		  $charset_collate .= " COLLATE {$wpdb->collate}";
		}

		$sql = 'CREATE TABLE '.$this->_subscription_table.' (
				id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id INT(10) UNSIGNED NULL DEFAULT NULL,
				from_date DATETIME NULL DEFAULT NULL,
				to_date DATETIME NULL DEFAULT NULL,
				fee FLOAT UNSIGNED NULL DEFAULT NULL,
				PRIMARY KEY  (id)
			) '.$charset_collate;

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql );
	}
	
	public function init_locale() {
		load_plugin_textdomain( 'paypal-framework', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}
	
	public static function getInstance() {
		if ( !self::$instance )
			self::$instance = new self;
		return self::$instance;
	}
	
	private function _getSettings() {
		if (empty($this->_settings))
			$this->_settings = get_option( $this->_optionsName );
		if ( !is_array( $this->_settings ) )
			$this->_settings = array();

		$defaults = array(
			'sandbox' => 'sandbox',
			'username-sandbox'	=> '',
			'password-sandbox'	=> '',
			'signature-sandbox'	=> '',
			'username-live'		=> '',
			'password-live'		=> '',
			'signature-live'	=> '',
			'version' => '58.0',
			'currency' => 'USD',
			'yearly_fee' => '1',
			'user_role' => '',
			'pay_button_text' => 'Pay',
			'return_url' => '',
			'cancel_url' => ''
		);
		$this->_settings = wp_parse_args( $this->_settings, $defaults );
	}
	
	public function registerOptions() {
		register_setting( $this->_optionsGroup, $this->_optionsName );
	}
	
	public function adminMenu() {
		$page = add_options_page( __( 'PayPal Yearly Subscription Settings', 'paypal-yearly-subscription' ), __( 'PayPal Yearly Subs.', 'paypal-yearly-subscription' ), 'manage_options', 'PayPalYearlySubscription', array( $this, 'options' ) );
		add_action( 'admin_print_styles-' . $page, array( $this, 'admin_css' ) );
	}

	public function admin_css() {
		wp_enqueue_style( 'paypal-yearly-subscription', plugin_dir_url( __FILE__ ) . 'paypal-yearly-subscription.css', array(), '0.0.1' );
	}
	
	public function subscription_button($atts)
	{
		// before everything else, handle the post when coming from the button
		if ( $_POST )
		{
			$_SESSION["Payment_Amount"] = $this->_settings['yearly_fee'];
			
			$resArray = $this->CallShortcutExpressCheckout(
				$this->_settings['yearly_fee'], 
				$this->_settings['currency'], 
				'Sale', 
				get_site_url().$this->_settings['return_url'], 
				get_site_url().$this->_settings['cancel_url']
			);
			
			$ack = strtoupper($resArray["ACK"]);
			if($ack=="SUCCESS" || $ack=="SUCCESSWITHWARNING")
			{
				
				$this->RedirectToPayPal ( $resArray["TOKEN"] );
				exit;
			} 
			else  
			{
				//Display a user friendly Error on the page using any of the following error information returned by PayPal
				$ErrorCode = urldecode($resArray["L_ERRORCODE0"]);
				$ErrorShortMsg = urldecode($resArray["L_SHORTMESSAGE0"]);
				$ErrorLongMsg = urldecode($resArray["L_LONGMESSAGE0"]);
				$ErrorSeverityCode = urldecode($resArray["L_SEVERITYCODE0"]);
				
				$error =  "SetExpressCheckout API call failed. ";
				$error .= "<br>Detailed Error Message: " . $ErrorLongMsg;
				$error .= "<br>Short Error Message: " . $ErrorShortMsg;
				$error .= "<br>Error Code: " . $ErrorCode;
				$error .= "<br>Error Severity Code: " . $ErrorSeverityCode;
				
				return $error;
			}
		}
		
		global $current_user;
		$user_subscription = $this->get_subscription($current_user->ID);
		
		// Check if current user has been already subscribed or can subscribe at all
		$can_subscribe = true;
		$no_form_message = '';
		if ( !in_array($this->_settings['user_role'], $current_user->roles) )
		{
			$no_form_message = __('You do not have the role to subscribe.', 'paypal-yearly-subscription');
			$can_subscribe = false;
		}
		
		if ( $can_subscribe && $user_subscription['id'] != null && strtotime($user_subscription['to_date']) > time() )
		{
			$no_form_message = __('You already have a valid subscription.', 'paypal-yearly-subscription');
			$can_subscribe = false;
		}
		
		if ( $can_subscribe )
		{
			$form = '<form name="confirmation" id="confirmation" method="post" action="">
				<input type="submit" name="submit" value="'.$this->_settings['pay_button_text'].'">
				</form>';
		}
		else
		{
			$form = $no_form_message;
		}
			
		return $form;
	}
	
	/**
	 * The shortcode function handling the return of PayPal
	 */
	public function subscription_return($atts)
	{
		$finalPaymentAmount =  $_SESSION["Payment_Amount"];
	
		/*
		'------------------------------------
		' Calls the DoExpressCheckoutPayment API call
		'
		' The ConfirmPayment function is defined in the file PayPalFunctions.jsp,
		' that is included at the top of this file.
		'-------------------------------------------------
		*/

		$resArray = $this->ConfirmPayment ( $finalPaymentAmount );
		$ack = strtoupper($resArray["ACK"]);
		if( $ack == "SUCCESS" || $ack == "SUCCESSWITHWARNING" )
		{
			global $current_user, $wpdb;
			
			$data = $this->_user_subscription_data;
			$data['user_id'] = $current_user->ID;
			$data['from_date'] = date('Y-m-d');
			$data['to_date'] = date('Y-m-d', strtotime('+1 year'));
			$data['fee'] = $this->_settings['yearly_fee'];
			
			$wpdb->insert($this->_subscription_table, $data);
			
			echo __("Thank you for your payment.", 'paypal-yearly-subscription');
		}
		else  
		{
			//Display a user friendly Error on the page using any of the following error information returned by PayPal
			$ErrorCode = urldecode($resArray["L_ERRORCODE0"]);
			$ErrorShortMsg = urldecode($resArray["L_SHORTMESSAGE0"]);
			$ErrorLongMsg = urldecode($resArray["L_LONGMESSAGE0"]);
			$ErrorSeverityCode = urldecode($resArray["L_SEVERITYCODE0"]);
			
			$error = "GetExpressCheckoutDetails API call failed. ";
			$error .= "<br>Detailed Error Message: " . $ErrorLongMsg;
			$error .= "<br>Short Error Message: " . $ErrorShortMsg;
			$error .= "<br>Error Code: " . $ErrorCode;
			$error .= "<br>Error Severity Code: " . $ErrorSeverityCode;
			
			return $error;
		}
	}
	
	/**
	 * Shortcode function that checks if a subscription is valid
	 */
	public function subscription_check($attrs, $content = '')
	{
		global $current_user;
		$user_subscription = $this->get_subscription($current_user->ID);
		
		if ( ($user_subscription['id'] != null && strtotime($user_subscription['to_date']) > time()) || in_array('administrator', $current_user->roles) )
		{
			return $content;
		}
		else
		{
			return __('You have to be subscribed to view this content.', 'paypal-yearly-subscription');
		}
	}
	
	/**
	 * Get the subscription of a user
	 */
	public function get_subscription($user_id = 0)
	{
		global $wpdb;
		
		$row = array();
		
		try
		{
			$row = $wpdb->get_row('SELECT * FROM '.$this->_subscription_table.' WHERE user_id = '.(int)$user_id.' ORDER BY to_date DESC LIMIT 1', ARRAY_A);
			
			if ( $row == null )
			{
				$row = array();
			}
		}
		catch(Exception $e){}
		
		$data = $this->_user_subscription_data;
		foreach ( $data as $key => $value )
		{
			if ( array_key_exists($key, $row) )
			{
				$data[$key] = $row[$key];
			}
		}
		
		return $data;
	}
	
	/**
	 * This is used to display the options page for this plugin
	 */
	public function options() {
?>
		<div class="wrap">
			<h2><?php _e( 'PayPal Yearly Subscription Options', 'paypal-yearly-subscription' ); ?></h2>
			<form action="options.php" method="post" id="wp_paypal_framework">
				<?php settings_fields( $this->_optionsGroup ); ?>
				<table class="form-table">
					<tr valign="top">
						<th scope="row">
							<label for="<?php echo $this->_optionsName; ?>_username-live">
								<?php _e( 'PayPal Live API Username:', 'paypal-framework' ); ?>
							</label>
						</th>
						<td>
							<input type="text" name="<?php echo $this->_optionsName; ?>[username-live]" value="<?php echo esc_attr( $this->_settings['username-live'] ); ?>" id="<?php echo $this->_optionsName; ?>_username-live" class="regular-text code" />
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">
							<label for="<?php echo $this->_optionsName; ?>_password-live">
								<?php _e('PayPal Live API Password:', 'paypal-framework') ?>
							</label>
						</th>
						<td>
							<input type="text" name="<?php echo $this->_optionsName; ?>[password-live]" value="<?php echo esc_attr( $this->_settings['password-live'] ); ?>" id="<?php echo $this->_optionsName; ?>_password-live" class="regular-text code" />
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">
							<label for="<?php echo $this->_optionsName; ?>_signature-live">
								<?php _e( 'PayPal Live API Signature:', 'paypal-framework' ) ?>
							</label>
						</th>
						<td>
							<input type="text" name="<?php echo $this->_optionsName; ?>[signature-live]" value="<?php echo esc_attr($this->_settings['signature-live']); ?>" id="<?php echo $this->_optionsName; ?>_signature-live" class="regular-text code" />
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">
							<label for="<?php echo $this->_optionsName; ?>_username-sandbox">
								<?php _e('PayPal Sandbox API Username:', 'paypal-framework') ?>
							</label>
						</th>
						<td>
							<input type="text" name="<?php echo $this->_optionsName; ?>[username-sandbox]" value="<?php echo esc_attr($this->_settings['username-sandbox']); ?>" id="<?php echo $this->_optionsName; ?>_username-sandbox" class="regular-text code" />
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">
							<label for="<?php echo $this->_optionsName; ?>_password-sandbox">
								<?php _e('PayPal Sandbox API Password:', 'paypal-framework') ?>
							</label>
						</th>
						<td>
							<input type="text" name="<?php echo $this->_optionsName; ?>[password-sandbox]" value="<?php echo esc_attr($this->_settings['password-sandbox']); ?>" id="<?php echo $this->_optionsName; ?>_password-sandbox" class="regular-text code" />
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">
							<label for="<?php echo $this->_optionsName; ?>_signature-sandbox">
								<?php _e('PayPal Sandbox API Signature:', 'paypal-framework') ?>
							</label>
						</th>
						<td>
							<input type="text" name="<?php echo $this->_optionsName; ?>[signature-sandbox]" value="<?php echo esc_attr($this->_settings['signature-sandbox']); ?>" id="<?php echo $this->_optionsName; ?>_signature-sandbox" class="regular-text code" />
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">
							<?php _e('PayPal Sandbox or Live:', 'paypal-yearly-subscription') ?>
						</th>
						<td>
							<input type="radio" name="<?php echo $this->_optionsName; ?>[sandbox]" value="live" id="<?php echo $this->_optionsName; ?>_sandbox-live"<?php checked('live', $this->_settings['sandbox']); ?> />
							<label for="<?php echo $this->_optionsName; ?>_sandbox-live"><?php _e('Live', 'paypal-yearly-subscription'); ?></label><br />
							<input type="radio" name="<?php echo $this->_optionsName; ?>[sandbox]" value="sandbox" id="<?php echo $this->_optionsName; ?>_sandbox-sandbox"<?php checked('sandbox', $this->_settings['sandbox']); ?> />
							<label for="<?php echo $this->_optionsName; ?>_sandbox-sandbox"><?php _e('Use Sandbox (for testing only)', 'paypal-yearly-subscription'); ?></label><br />
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">
							<label for="<?php echo $this->_optionsName; ?>_currency">
								<?php _e('Default Currency:', 'paypal-yearly-subscription') ?>
							</label>
						</th>
						<td>
							<select id="<?php echo $this->_optionsName; ?>_currency" class="postform" name="<?php echo $this->_optionsName; ?>[currency]">
								<option value=''><?php _e( 'Please Choose Default Currency', 'paypal-yearly-subscription' ); ?></option>
								<?php foreach ( $this->_currencies as $code => $currency ) { ?>
								<option value='<?php echo esc_attr($code); ?>'<?php selected($code, $this->_settings['currency']); ?>><?php echo esc_html( $currency ); ?></option>
								<?php } ?>
							</select>
							<small>
								<?php _e( "This is just the default currency for if one isn't specified.", 'paypal-yearly-subscription' ); ?>
							</small>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">
							<label for="<?php echo $this->_optionsName; ?>_version">
								<?php _e('PayPal API version:', 'paypal-yearly-subscription') ?>
							</label>
						</th>
						<td>
							<input type="text" name="<?php echo $this->_optionsName; ?>[version]" value="<?php echo esc_attr($this->_settings['version']); ?>" id="<?php echo $this->_optionsName; ?>_version" class="small-text" />
							<small>
								<?php echo sprintf( __( "This is the default version to use if one isn't specified.  It is usually safe to set this to the <a href='%s'>most recent version</a>.", 'paypal-yearly-subscription' ), 'http://developer.paypal-portal.com/pdn/board/message?board.id=nvp&thread.id=4475' ); ?>
							</small>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">
							<label for="<?php echo $this->_optionsName; ?>_yearly_fee">
								<?php _e('Yearly Fee:', 'paypal-yearly-subscription') ?>
							</label>
						</th>
						<td>
							<input type="text" name="<?php echo $this->_optionsName; ?>[yearly_fee]" value="<?php echo esc_attr($this->_settings['yearly_fee']); ?>" id="<?php echo $this->_optionsName; ?>_yearly_fee" class="regular-text" />
							<small>
								<?php _e( 'This is the amount users have to pay yearly.', 'paypal-yearly-subscription' ); ?>
							</small>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">
							<label for="<?php echo $this->_optionsName; ?>_currency">
								<?php _e('Select User Role:', 'paypal-yearly-subscription') ?>
							</label>
						</th>
						<td>
							<select id="<?php echo $this->_optionsName; ?>_user_role" class="postform" name="<?php echo $this->_optionsName; ?>[user_role]">
								<option value=''><?php _e( 'Please Choose User Role', 'paypal-yearly-subscription' ); ?></option>
								<?php foreach ( $this->_current_roles as $code => $role ) { ?>
								<option value='<?php echo esc_attr($code); ?>'<?php selected($code, $this->_settings['user_role']); ?>><?php echo esc_html( $role['name'] ); ?></option>
								<?php } ?>
							</select>
							<small>
								<?php _e( "This is the role that has to pay yearly.", 'paypal-yearly-subscription' ); ?>
							</small>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">
							<label for="<?php echo $this->_optionsName; ?>_pay_button_text">
								<?php _e('Pay Button Text:', 'paypal-yearly-subscription') ?>
							</label>
						</th>
						<td>
							<input type="text" name="<?php echo $this->_optionsName; ?>[pay_button_text]" value="<?php echo esc_attr($this->_settings['pay_button_text']); ?>" id="<?php echo $this->_optionsName; ?>_pay_button_text" class="regular-text" />
							<small>
								<?php _e( 'The text of the pay button.', 'paypal-yearly-subscription' ); ?>
							</small>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">
							<label for="<?php echo $this->_optionsName; ?>_return_url">
								<?php _e('Return Url:', 'paypal-yearly-subscription') ?>
							</label>
						</th>
						<td>
							<input type="text" name="<?php echo $this->_optionsName; ?>[return_url]" value="<?php echo esc_attr($this->_settings['return_url']); ?>" id="<?php echo $this->_optionsName; ?>_return_url" class="regular-text" />
							<small>
								<?php _e( 'Relative url where paypal gets back on success.', 'paypal-yearly-subscription' ); ?>
							</small>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">
							<label for="<?php echo $this->_optionsName; ?>_cancel_url">
								<?php _e('Cancel Url:', 'paypal-yearly-subscription') ?>
							</label>
						</th>
						<td>
							<input type="text" name="<?php echo $this->_optionsName; ?>[cancel_url]" value="<?php echo esc_attr($this->_settings['cancel_url']); ?>" id="<?php echo $this->_optionsName; ?>_cancel_url" class="regular-text" />
							<small>
								<?php _e( 'Relative url where paypal gets back if canceled.', 'paypal-yearly-subscription' ); ?>
							</small>
						</td>
					</tr>
				</table>
				<p class="submit">
					<input type="submit" name="Submit" value="<?php _e('Update Options &raquo;', 'paypal-yearly-subscription'); ?>" />
				</p>
			</form>
			
			<div class="extra-info">
				<p>Shortcodes to use in pages:</p>
				<p>
					<pre>[paypal-yearly-subscription-button]</pre> - The button that takes the user to the PayPal payment page 
				</p>
				<p>
					<pre>[paypal-yearly-subscription-return]</pre> - The code that handles the data coming from PayPal after return
				</p>
				<p>
					<pre>[paypal-yearly-subscription-check-subscription] content [/paypal-yearly-subscription-check-subscription]</pre> - Enclose the content you want to restrict from the user role
				</p>
			</div>
		</div>
<?php
	}
	
	/*   
	'-------------------------------------------------------------------------------------------------------------------------------------------
	' Purpose: 	Prepares the parameters for the SetExpressCheckout API Call.
	' Inputs:  
	'		paymentAmount:  	Total value of the shopping cart
	'		currencyCodeType: 	Currency code value the PayPal API
	'		paymentType: 		paymentType has to be one of the following values: Sale or Order or Authorization
	'		returnURL:			the page where buyers return to after they are done with the payment review on PayPal
	'		cancelURL:			the page where buyers return to when they cancel the payment review on PayPal
	'--------------------------------------------------------------------------------------------------------------------------------------------	
	*/
	public function CallShortcutExpressCheckout( $paymentAmount, $currencyCodeType, $paymentType, $returnURL, $cancelURL) 
	{
		//------------------------------------------------------------------------------------------------------------------------------------
		// Construct the parameter string that describes the SetExpressCheckout API call in the shortcut implementation
		
		$nvpstr="&PAYMENTREQUEST_0_AMT=". $paymentAmount;
		$nvpstr = $nvpstr . "&PAYMENTREQUEST_0_DESC=".urlencode('Yearly Subscription').' - '.$this->_settings['yearly_fee'].' '.$this->_settings['currency'];
		$nvpstr = $nvpstr . "&PAYMENTREQUEST_0_PAYMENTACTION=" . $paymentType;
		$nvpstr = $nvpstr . "&RETURNURL=" . urlencode($returnURL);
		$nvpstr = $nvpstr . "&CANCELURL=" . urlencode($cancelURL);
		$nvpstr = $nvpstr . "&PAYMENTREQUEST_0_CURRENCYCODE=" . $currencyCodeType;
		
		$_SESSION["currencyCodeType"] = $currencyCodeType;	  
		$_SESSION["PaymentType"] = $paymentType;

		//'--------------------------------------------------------------------------------------------------------------- 
		//' Make the API call to PayPal
		//' If the API call succeded, then redirect the buyer to PayPal to begin to authorize payment.  
		//' If an error occured, show the resulting errors
		//'---------------------------------------------------------------------------------------------------------------
	    $resArray = $this->hash_call("SetExpressCheckout", $nvpstr);
		$ack = strtoupper($resArray["ACK"]);
		if($ack=="SUCCESS" || $ack=="SUCCESSWITHWARNING")
		{
			$token = urldecode($resArray["TOKEN"]);
			$_SESSION['TOKEN']=$token;
		}
		   
	    return $resArray;
	}

	/*   
	'-------------------------------------------------------------------------------------------------------------------------------------------
	' Purpose: 	Prepares the parameters for the SetExpressCheckout API Call.
	' Inputs:  
	'		paymentAmount:  	Total value of the shopping cart
	'		currencyCodeType: 	Currency code value the PayPal API
	'		paymentType: 		paymentType has to be one of the following values: Sale or Order or Authorization
	'		returnURL:			the page where buyers return to after they are done with the payment review on PayPal
	'		cancelURL:			the page where buyers return to when they cancel the payment review on PayPal
	'		shipToName:		the Ship to name entered on the merchant's site
	'		shipToStreet:		the Ship to Street entered on the merchant's site
	'		shipToCity:			the Ship to City entered on the merchant's site
	'		shipToState:		the Ship to State entered on the merchant's site
	'		shipToCountryCode:	the Code for Ship to Country entered on the merchant's site
	'		shipToZip:			the Ship to ZipCode entered on the merchant's site
	'		shipToStreet2:		the Ship to Street2 entered on the merchant's site
	'		phoneNum:			the phoneNum  entered on the merchant's site
	'--------------------------------------------------------------------------------------------------------------------------------------------	
	*/
	public function CallMarkExpressCheckout( $paymentAmount, $currencyCodeType, $paymentType, $returnURL, 
									  $cancelURL, $shipToName, $shipToStreet, $shipToCity, $shipToState,
									  $shipToCountryCode, $shipToZip, $shipToStreet2, $phoneNum
									) 
	{
		//------------------------------------------------------------------------------------------------------------------------------------
		// Construct the parameter string that describes the SetExpressCheckout API call in the shortcut implementation
		
		$nvpstr="&PAYMENTREQUEST_0_AMT=". $paymentAmount;
		$nvpstr = $nvpstr . "&PAYMENTREQUEST_0_PAYMENTACTION=" . $paymentType;
		$nvpstr = $nvpstr . "&RETURNURL=" . $returnURL;
		$nvpstr = $nvpstr . "&CANCELURL=" . $cancelURL;
		$nvpstr = $nvpstr . "&PAYMENTREQUEST_0_CURRENCYCODE=" . $currencyCodeType;
		$nvpstr = $nvpstr . "&ADDROVERRIDE=1";
		$nvpstr = $nvpstr . "&PAYMENTREQUEST_0_SHIPTONAME=" . $shipToName;
		$nvpstr = $nvpstr . "&PAYMENTREQUEST_0_SHIPTOSTREET=" . $shipToStreet;
		$nvpstr = $nvpstr . "&PAYMENTREQUEST_0_SHIPTOSTREET2=" . $shipToStreet2;
		$nvpstr = $nvpstr . "&PAYMENTREQUEST_0_SHIPTOCITY=" . $shipToCity;
		$nvpstr = $nvpstr . "&PAYMENTREQUEST_0_SHIPTOSTATE=" . $shipToState;
		$nvpstr = $nvpstr . "&PAYMENTREQUEST_0_SHIPTOCOUNTRYCODE=" . $shipToCountryCode;
		$nvpstr = $nvpstr . "&PAYMENTREQUEST_0_SHIPTOZIP=" . $shipToZip;
		$nvpstr = $nvpstr . "&PAYMENTREQUEST_0_SHIPTOPHONENUM=" . $phoneNum;
		
		$_SESSION["currencyCodeType"] = $currencyCodeType;	  
		$_SESSION["PaymentType"] = $paymentType;

		//'--------------------------------------------------------------------------------------------------------------- 
		//' Make the API call to PayPal
		//' If the API call succeded, then redirect the buyer to PayPal to begin to authorize payment.  
		//' If an error occured, show the resulting errors
		//'---------------------------------------------------------------------------------------------------------------
	    $resArray = $this->hash_call("SetExpressCheckout", $nvpstr);
		$ack = strtoupper($resArray["ACK"]);
		if($ack=="SUCCESS" || $ack=="SUCCESSWITHWARNING")
		{
			$token = urldecode($resArray["TOKEN"]);
			$_SESSION['TOKEN']=$token;
		}
		   
	    return $resArray;
	}
	
	/*
	'-------------------------------------------------------------------------------------------
	' Purpose: 	Prepares the parameters for the GetExpressCheckoutDetails API Call.
	'
	' Inputs:  
	'		None
	' Returns: 
	'		The NVP Collection object of the GetExpressCheckoutDetails Call Response.
	'-------------------------------------------------------------------------------------------
	*/
	public function GetShippingDetails( $token )
	{
		//'--------------------------------------------------------------
		//' At this point, the buyer has completed authorizing the payment
		//' at PayPal.  The function will call PayPal to obtain the details
		//' of the authorization, incuding any shipping information of the
		//' buyer.  Remember, the authorization is not a completed transaction
		//' at this state - the buyer still needs an additional step to finalize
		//' the transaction
		//'--------------------------------------------------------------
	   
	    //'---------------------------------------------------------------------------
		//' Build a second API request to PayPal, using the token as the
		//'  ID to get the details on the payment authorization
		//'---------------------------------------------------------------------------
	    $nvpstr="&TOKEN=" . $token;

		//'---------------------------------------------------------------------------
		//' Make the API call and store the results in an array.  
		//'	If the call was a success, show the authorization details, and provide
		//' 	an action to complete the payment.  
		//'	If failed, show the error
		//'---------------------------------------------------------------------------
	    $resArray = $this->hash_call("GetExpressCheckoutDetails",$nvpstr);
	    $ack = strtoupper($resArray["ACK"]);
		if($ack == "SUCCESS" || $ack=="SUCCESSWITHWARNING")
		{	
			$_SESSION['payer_id'] =	$resArray['PAYERID'];
		} 
		return $resArray;
	}
	
	/*
	'-------------------------------------------------------------------------------------------------------------------------------------------
	' Purpose: 	Prepares the parameters for the GetExpressCheckoutDetails API Call.
	'
	' Inputs:  
	'		sBNCode:	The BN code used by PayPal to track the transactions from a given shopping cart.
	' Returns: 
	'		The NVP Collection object of the GetExpressCheckoutDetails Call Response.
	'--------------------------------------------------------------------------------------------------------------------------------------------	
	*/
	public function ConfirmPayment( $FinalPaymentAmt )
	{
		/* Gather the information to make the final call to
		   finalize the PayPal payment.  The variable nvpstr
		   holds the name value pairs
		   */
		

		//Format the other parameters that were stored in the session from the previous calls	
		$token 				= urlencode($_SESSION['TOKEN']);
		$paymentType 		= urlencode($_SESSION['PaymentType']);
		$currencyCodeType 	= urlencode($_SESSION['currencyCodeType']);
		$payerID 			= urlencode($_REQUEST['PayerID']);

		$serverName 		= urlencode($_SERVER['SERVER_NAME']);

		$nvpstr  = '&TOKEN=' . $token . '&PAYERID=' . $payerID . '&PAYMENTREQUEST_0_PAYMENTACTION=' . $paymentType . '&PAYMENTREQUEST_0_AMT=' . $FinalPaymentAmt;
		$nvpstr .= '&PAYMENTREQUEST_0_CURRENCYCODE=' . $currencyCodeType . '&IPADDRESS=' . $serverName; 

		 /* Make the call to PayPal to finalize payment
		    If an error occured, show the resulting errors
		    */
		$resArray = $this->hash_call("DoExpressCheckoutPayment",$nvpstr);

		/* Display the API response back to the browser.
		   If the response from PayPal was a success, display the response parameters'
		   If the response was an error, display the errors received using APIError.php.
		   */
		$ack = strtoupper($resArray["ACK"]);

		return $resArray;
	}
	
	/*
	'-------------------------------------------------------------------------------------------------------------------------------------------
	' Purpose: 	This function makes a DoDirectPayment API call
	'
	' Inputs:  
	'		paymentType:		paymentType has to be one of the following values: Sale or Order or Authorization
	'		paymentAmount:  	total value of the shopping cart
	'		currencyCode:	 	currency code value the PayPal API
	'		firstName:			first name as it appears on credit card
	'		lastName:			last name as it appears on credit card
	'		street:				buyer's street address line as it appears on credit card
	'		city:				buyer's city
	'		state:				buyer's state
	'		countryCode:		buyer's country code
	'		zip:				buyer's zip
	'		creditCardType:		buyer's credit card type (i.e. Visa, MasterCard ... )
	'		creditCardNumber:	buyers credit card number without any spaces, dashes or any other characters
	'		expDate:			credit card expiration date
	'		cvv2:				Card Verification Value 
	'		
	'-------------------------------------------------------------------------------------------
	'		
	' Returns: 
	'		The NVP Collection object of the DoDirectPayment Call Response.
	'--------------------------------------------------------------------------------------------------------------------------------------------	
	*/


	public function DirectPayment( $paymentType, $paymentAmount, $creditCardType, $creditCardNumber,
							$expDate, $cvv2, $firstName, $lastName, $street, $city, $state, $zip, 
							$countryCode, $currencyCode )
	{
		//Construct the parameter string that describes DoDirectPayment
		$nvpstr = "&AMT=" . $paymentAmount;
		$nvpstr = $nvpstr . "&CURRENCYCODE=" . $currencyCode;
		$nvpstr = $nvpstr . "&PAYMENTACTION=" . $paymentType;
		$nvpstr = $nvpstr . "&CREDITCARDTYPE=" . $creditCardType;
		$nvpstr = $nvpstr . "&ACCT=" . $creditCardNumber;
		$nvpstr = $nvpstr . "&EXPDATE=" . $expDate;
		$nvpstr = $nvpstr . "&CVV2=" . $cvv2;
		$nvpstr = $nvpstr . "&FIRSTNAME=" . $firstName;
		$nvpstr = $nvpstr . "&LASTNAME=" . $lastName;
		$nvpstr = $nvpstr . "&STREET=" . $street;
		$nvpstr = $nvpstr . "&CITY=" . $city;
		$nvpstr = $nvpstr . "&STATE=" . $state;
		$nvpstr = $nvpstr . "&COUNTRYCODE=" . $countryCode;
		$nvpstr = $nvpstr . "&IPADDRESS=" . $_SERVER['REMOTE_ADDR'];

		$resArray = $this->hash_call("DoDirectPayment", $nvpstr);

		return $resArray;
	}


	/**
	  '-------------------------------------------------------------------------------------------------------------------------------------------
	  * hash_call: Function to perform the API call to PayPal using API signature
	  * @methodName is name of API  method.
	  * @nvpStr is nvp string.
	  * returns an associtive array containing the response from the server.
	  '-------------------------------------------------------------------------------------------------------------------------------------------
	*/
	public function hash_call($methodName,$nvpStr)
	{
		//declaring of global variables
		$API_Endpoint = $this->_endpoint[ $this->_settings['sandbox'] ]; 
		$version = $this->_settings['version']; 
		$API_UserName = $this->_settings['username-'.$this->_settings['sandbox']]; 
		$API_Password = $this->_settings['password-'.$this->_settings['sandbox']];  
		$API_Signature = $this->_settings['signature-'.$this->_settings['sandbox']];
		
		$USE_PROXY = false; 
		$PROXY_HOST = '127.0.0.1'; 
		$PROXY_PORT = '808';
		
		global $gv_ApiErrorURL;
		$sBNCode = "PP-ECWizard";

		//setting the curl parameters.
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL,$API_Endpoint);
		curl_setopt($ch, CURLOPT_VERBOSE, 1);

		//turning off the server and peer verification(TrustManager Concept).
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);

		curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
		curl_setopt($ch, CURLOPT_POST, 1);
		
	    //if USE_PROXY constant set to TRUE in Constants.php, then only proxy will be enabled.
	   //Set proxy name to PROXY_HOST and port number to PROXY_PORT in constants.php 
		if($USE_PROXY)
			curl_setopt ($ch, CURLOPT_PROXY, $PROXY_HOST. ":" . $PROXY_PORT); 

		//NVPRequest for submitting to server
		$nvpreq="METHOD=" . urlencode($methodName) . "&VERSION=" . urlencode($version) . "&PWD=" . urlencode($API_Password) . "&USER=" . urlencode($API_UserName) . "&SIGNATURE=" . urlencode($API_Signature) . $nvpStr . "&BUTTONSOURCE=" . urlencode($sBNCode);

		//setting the nvpreq as POST FIELD to curl
		curl_setopt($ch, CURLOPT_POSTFIELDS, $nvpreq);

		//getting response from server
		$response = curl_exec($ch);

		//convrting NVPResponse to an Associative Array
		$nvpResArray = $this->deformatNVP($response);
		$nvpReqArray = $this->deformatNVP($nvpreq);
		$_SESSION['nvpReqArray']=$nvpReqArray;

		if (curl_errno($ch)) 
		{
			// moving to display page to display curl errors
			  $_SESSION['curl_error_no']=curl_errno($ch) ;
			  $_SESSION['curl_error_msg']=curl_error($ch);

			  //Execute the Error handling module to display errors. 
		} 
		else 
		{
			 //closing the curl
		  	curl_close($ch);
		}

		return $nvpResArray;
	}

	/*'----------------------------------------------------------------------------------
	 Purpose: Redirects to PayPal.com site.
	 Inputs:  NVP string.
	 Returns: 
	----------------------------------------------------------------------------------
	*/
	public function RedirectToPayPal ( $token )
	{
		// Redirect to paypal.com here
		$payPalURL = $this->_url[ $this->_settings['sandbox'] ] . $token;
		//header("Location: ".$payPalURL);
		
		echo '
			<script>
				window.location = "'.$payPalURL.'";
			</script>
		';
	}

	
	/*'----------------------------------------------------------------------------------
	 * This function will take NVPString and convert it to an Associative Array and it will decode the response.
	  * It is usefull to search for a particular key and displaying arrays.
	  * @nvpstr is NVPString.
	  * @nvpArray is Associative Array.
	   ----------------------------------------------------------------------------------
	  */
	public function deformatNVP($nvpstr)
	{
		$intial=0;
	 	$nvpArray = array();

		while(strlen($nvpstr))
		{
			//postion of Key
			$keypos= strpos($nvpstr,'=');
			//position of value
			$valuepos = strpos($nvpstr,'&') ? strpos($nvpstr,'&'): strlen($nvpstr);

			/*getting the Key and Value values and storing in a Associative Array*/
			$keyval=substr($nvpstr,$intial,$keypos);
			$valval=substr($nvpstr,$keypos+1,$valuepos-$keypos-1);
			//decoding the respose
			$nvpArray[urldecode($keyval)] =urldecode( $valval);
			$nvpstr=substr($nvpstr,$valuepos+1,strlen($nvpstr));
	     }
		return $nvpArray;
	}
}

// Instantiate our class
$PayPalYearlySubscription = PayPalYearlySubscription::getInstance();