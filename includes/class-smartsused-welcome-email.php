<?php
/**
 * Sends role-specific SmartSused welcome emails.
 *
 * @package SmartSused_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Send a responsive, role-specific welcome email with account setup links.
 *
 * @param int    $user_id WordPress user ID.
 * @param string $role    WordPress role slug.
 * @return bool Whether the email was sent.
 */
function smartsused_send_welcome_email( $user_id, $role ) {
	$role_content = array(
		'smartsused_owner'  => array(
			'subject'     => __( '[SmartSused] Vitajte na portáli vlastníka bytu', 'smartsused-core' ),
			'header'      => __( 'Portál vlastníka bytu', 'smartsused-core' ),
			'intro'       => __( 'Vitajte v SmartSused. Ako vlastník bytu máte na jednom mieste prehľad o dôležitých informáciách a správe svojho bývania.', 'smartsused-core' ),
			'description' => __( 'Prostredníctvom portálu môžete sledovať oznamy správcu, zadávať odpočty meradiel, spravovať svojich nájomcov a kontrolovať financie.', 'smartsused-core' ),
			'color'       => '0F172A',
		),
		'smartsused_tenant' => array(
			'subject'     => __( '[SmartSused] Vitajte v aplikácii pre rezidentov', 'smartsused-core' ),
			'header'      => __( 'Aplikácia pre rezidentov', 'smartsused-core' ),
			'intro'       => __( 'Vitajte v SmartSused. Ako nájomcovi bytu vám aplikácia uľahčí každodenné bývanie.', 'smartsused-core' ),
			'description' => __( 'Rýchlo si pozriete dôležité oznamy o odstávkach a priamo z mobilu nahlásite správcovi poruchu, napríklad nefunkčný výťah alebo osvetlenie.', 'smartsused-core' ),
			'color'       => '10B981',
		),
	);

	$user_id = absint( $user_id );

	if ( ! is_string( $role ) || $user_id < 1 || ! isset( $role_content[ $role ] ) ) {
		error_log( 'SmartSused Core: Welcome email requested with an invalid user ID or resident role.' );
		return false;
	}

	$user = get_userdata( $user_id );

	if ( ! ( $user instanceof WP_User ) || ! is_email( $user->user_email ) ) {
		error_log( 'SmartSused Core: Welcome email recipient could not be loaded or has an invalid email.' );
		return false;
	}

	$reset_key = get_password_reset_key( $user );

	if ( is_wp_error( $reset_key ) ) {
		error_log( 'SmartSused Core: Password reset link could not be created for a welcome email.' );
		return false;
	}

	$reset_url = add_query_arg(
		array(
			'action' => 'rp',
			'key'    => $reset_key,
			'login'  => $user->user_login,
		),
		network_site_url( 'wp-login.php', 'login' )
	);
	$app_url   = apply_filters( 'smartsused_welcome_app_url', 'https://smartsused.sk' );
	$app_parts = is_string( $app_url ) ? wp_parse_url( $app_url ) : false;

	if (
		! is_string( $app_url )
		|| ! wp_http_validate_url( $app_url )
		|| ! is_array( $app_parts )
		|| 'https' !== strtolower( isset( $app_parts['scheme'] ) ? $app_parts['scheme'] : '' )
	) {
		error_log( 'SmartSused Core: Welcome email application URL is invalid.' );
		return false;
	}

	$app_url   = untrailingslashit( $app_url );
	$login_url = $app_url . '/login';
	$content   = $role_content[ $role ];
	$name      = trim( (string) $user->first_name );

	if ( '' === $name ) {
		$name = trim( (string) $user->display_name );
	}

	if ( '' === $name ) {
		$name = $user->user_login;
	}

	$name        = esc_html( $name );
	$email       = esc_html( $user->user_email );
	$header      = esc_html( $content['header'] );
	$intro       = esc_html( $content['intro'] );
	$description = esc_html( $content['description'] );
	$button      = esc_html__( 'Aktivovať účet a nastaviť heslo', 'smartsused-core' );
	$setup_text  = esc_html__( 'Pred prvým prihlásením si nastavte heslo cez tento bezpečný odkaz:', 'smartsused-core' );
	$login_text  = esc_html__( 'Po nastavení hesla sa prihláste do aplikácie SmartSused.', 'smartsused-core' );
	$reset_link  = esc_url( $reset_url );
	$app_link    = esc_url( $login_url );
	$color       = '#' . $content['color'];
	$foreground  = 'smartsused_owner' === $role ? '#FFFFFF' : '#0F172A';
	$link_color  = 'smartsused_owner' === $role ? '#0F172A' : '#047857';

	$message = '<!doctype html><html lang="sk"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="x-apple-disable-message-reformatting"><title>' . $header . '</title>'
		. '<style>@media only screen and (max-width:620px){.ss-card{border-radius:0!important}.ss-pad{padding-left:20px!important;padding-right:20px!important}.ss-button{display:block!important;padding-left:12px!important;padding-right:12px!important}}</style></head>'
		. '<body style="margin:0;padding:0;background-color:#f1f5f9;font-family:Arial,Helvetica,sans-serif;color:#0f172a;">'
		. '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;background-color:#f1f5f9;"><tr><td align="center" style="padding:24px 12px;">'
		. '<table class="ss-card" role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:600px;background-color:#ffffff;border:1px solid #cbd5e1;border-radius:16px;overflow:hidden;">'
		. '<tr><td style="padding:28px 32px;background-color:' . esc_attr( $color ) . ';color:' . esc_attr( $foreground ) . ';">'
		. '<p style="margin:0 0 8px;font-size:13px;line-height:20px;font-weight:bold;letter-spacing:1px;color:' . esc_attr( $foreground ) . ';">SmartSused</p>'
		. '<h1 style="margin:0;font-size:25px;line-height:32px;font-weight:bold;color:' . esc_attr( $foreground ) . ';">' . $header . '</h1>'
		. '</td></tr><tr><td class="ss-pad" style="padding:30px 32px 20px;">'
		. '<p style="margin:0 0 16px;font-size:18px;line-height:27px;font-weight:bold;color:#0f172a;">' . sprintf( esc_html__( 'Dobrý deň, %s,', 'smartsused-core' ), $name ) . '</p>'
		. '<p style="margin:0 0 14px;font-size:16px;line-height:26px;color:#334155;">' . $intro . '</p>'
		. '<p style="margin:0 0 22px;font-size:16px;line-height:26px;color:#334155;">' . $description . '</p>'
		. '<p style="margin:0 0 6px;font-size:14px;line-height:22px;font-weight:bold;color:#0f172a;">' . esc_html__( 'Prihlasovací e-mail', 'smartsused-core' ) . '</p>'
		. '<p style="margin:0 0 24px;font-size:16px;line-height:24px;color:#334155;">' . $email . '</p>'
		. '<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:0 auto 24px;"><tr><td align="center" bgcolor="' . esc_attr( $color ) . '" style="border-radius:10px;background-color:' . esc_attr( $color ) . ';">'
		. '<a class="ss-button" href="' . $app_link . '" style="display:inline-block;padding:16px 24px;border:1px solid ' . esc_attr( $color ) . ';border-radius:10px;background-color:' . esc_attr( $color ) . ';color:' . esc_attr( $foreground ) . ';font-size:16px;line-height:22px;font-weight:bold;text-align:center;text-decoration:none;">' . $button . '</a>'
		. '</td></tr></table>'
		. '<p style="margin:0 0 8px;font-size:14px;line-height:22px;color:#334155;">' . $setup_text . '</p>'
		. '<p style="margin:0 0 22px;font-size:14px;line-height:22px;word-break:break-all;"><a href="' . $reset_link . '" style="color:' . esc_attr( $link_color ) . ';font-weight:bold;text-decoration:underline;">' . $reset_link . '</a></p>'
		. '<p style="margin:0;font-size:14px;line-height:22px;color:#475569;">' . $login_text . ' <a href="' . $app_link . '" style="color:' . esc_attr( $link_color ) . ';font-weight:bold;text-decoration:underline;">' . esc_html__( 'Otvoriť aplikáciu', 'smartsused-core' ) . '</a></p>'
		. '</td></tr><tr><td style="padding:18px 32px;background-color:#f8fafc;border-top:1px solid #e2e8f0;">'
		. '<p style="margin:0;font-size:12px;line-height:19px;color:#64748b;">' . esc_html__( 'Ak ste túto správu neočakávali, môžete ju ignorovať alebo kontaktovať správcu bytového domu.', 'smartsused-core' ) . '</p>'
		. '</td></tr></table></td></tr></table></body></html>';

	$headers = array( 'Content-Type: text/html; charset=UTF-8' );

	if ( ! wp_mail( $user->user_email, $content['subject'], $message, $headers ) ) {
		error_log( 'SmartSused Core: Role-specific welcome email could not be sent.' );
		return false;
	}

	return true;
}
