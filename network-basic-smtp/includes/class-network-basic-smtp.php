<?php
/**
 * Network Basic SMTP - Main Class
 *
 * @package Network_Basic_SMTP
 * @author  637 Digital Solutions
 * @license MIT
 * @link    https://637digital.com
 *
 * Copyright (c) 2026 637 Digital Solutions
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

if (!defined('ABSPATH')) {
	exit;
}

final class Network_Basic_SMTP {
	const OPTION_KEY       = 'nbsmtp_settings';
	const NONCE_SAVE       = 'nbsmtp_save_settings';
	const NONCE_TEST       = 'nbsmtp_send_test';
	const MENU_SLUG        = 'network-basic-smtp';
	const CIPHER_METHOD    = 'aes-256-cbc';
	const PASSWORD_VERSION = 'v1';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Captured mail error from wp_mail_failed during current request.
	 *
	 * @var string
	 */
	private $last_mail_error = '';

	/**
	 * Get singleton instance.
	 *
	 * @return self
	 */
	public static function get_instance() {
		if (null === self::$instance) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action('network_admin_menu', array($this, 'register_network_page'));
		add_action('network_admin_edit_nbsmtp_save', array($this, 'handle_save'));
		add_action('network_admin_edit_nbsmtp_test', array($this, 'handle_test'));

		add_action('phpmailer_init', array($this, 'configure_phpmailer'));
		add_action('wp_mail_failed', array($this, 'capture_mail_error'));
		add_action('network_admin_footer', array($this, 'render_admin_assets'));

		add_filter('wp_mail', array($this, 'append_footer_to_mail'));
	}

	/**
	 * Register the Network Admin settings page.
	 *
	 * @return void
	 */
	public function register_network_page() {
		add_menu_page(
			'Network SMTP',
			'Network SMTP',
			'manage_network_options',
			self::MENU_SLUG,
			array($this, 'render_page'),
			'dashicons-email',
			25
		);
	}

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	private function get_defaults() {
		return array(
			'host'               => '',
			'port'               => 587,
			'encryption'         => 'tls',
			'username'           => '',
			'password_encrypted' => '',
			'from_email'         => '',
			'from_name'          => '',
			'auth'               => 1,
			'footer_enabled'     => 0,
			'footer_text'        => '',
		);
	}

	/**
	 * Get stored settings merged with defaults.
	 *
	 * @return array
	 */
	private function get_settings() {
		$settings = get_site_option(self::OPTION_KEY, array());
		$settings = wp_parse_args($settings, $this->get_defaults());

		$settings['port'] = absint($settings['port']);

		if (!in_array($settings['encryption'], array('none', 'ssl', 'tls'), true)) {
			$settings['encryption'] = 'tls';
		}

		$settings['auth'] = empty($settings['auth']) ? 0 : 1;
		$settings['footer_enabled'] = empty($settings['footer_enabled']) ? 0 : 1;
		$settings['footer_text'] = is_string($settings['footer_text']) ? $settings['footer_text'] : '';

		return $settings;
	}

	/**
	 * Persist settings.
	 *
	 * @param array $settings Settings to store.
	 * @return void
	 */
	private function update_settings($settings) {
		update_site_option(self::OPTION_KEY, $settings);
	}

	/**
	 * Check whether a password is currently stored.
	 *
	 * @return bool
	 */
	private function has_stored_password() {
		$settings = $this->get_settings();
		return !empty($settings['password_encrypted']);
	}

	/**
	 * Get normalized encryption key.
	 *
	 * @return string
	 */
	private function get_encryption_key() {
		if (!defined('NBSMTP_ENCRYPTION_KEY') || !is_string(NBSMTP_ENCRYPTION_KEY) || '' === NBSMTP_ENCRYPTION_KEY) {
			return '';
		}

		return hash('sha256', NBSMTP_ENCRYPTION_KEY, true);
	}

	/**
	 * Encrypt an SMTP secret before storing it.
	 *
	 * @param string $plaintext Secret value.
	 * @return string
	 */
	private function encrypt_secret($plaintext) {
		$key = $this->get_encryption_key();

		if ('' === $key || '' === $plaintext) {
			return '';
		}

		$iv_length = openssl_cipher_iv_length(self::CIPHER_METHOD);
		if (!is_int($iv_length) || $iv_length < 1) {
			return '';
		}

		try {
			$iv = random_bytes($iv_length);
		} catch (Exception $e) {
			return '';
		}

		$ciphertext = openssl_encrypt(
			$plaintext,
			self::CIPHER_METHOD,
			$key,
			OPENSSL_RAW_DATA,
			$iv
		);

		if (false === $ciphertext) {
			return '';
		}

		$payload = array(
			'v'  => self::PASSWORD_VERSION,
			'iv' => base64_encode($iv),
			'ct' => base64_encode($ciphertext),
		);

		$json = wp_json_encode($payload);
		if (!is_string($json) || '' === $json) {
			return '';
		}

		return base64_encode($json);
	}

	/**
	 * Decrypt stored SMTP secret.
	 *
	 * @param string $encoded_payload Encrypted payload.
	 * @return string
	 */
	private function decrypt_secret($encoded_payload) {
		if (!is_string($encoded_payload) || '' === $encoded_payload) {
			return '';
		}

		$key = $this->get_encryption_key();
		if ('' === $key) {
			return '';
		}

		$json = base64_decode($encoded_payload, true);
		if (false === $json || '' === $json) {
			return '';
		}

		$payload = json_decode($json, true);
		if (!is_array($payload) || empty($payload['iv']) || empty($payload['ct'])) {
			return '';
		}

		$iv = base64_decode($payload['iv'], true);
		$ciphertext = base64_decode($payload['ct'], true);

		if (false === $iv || false === $ciphertext) {
			return '';
		}

		$plaintext = openssl_decrypt(
			$ciphertext,
			self::CIPHER_METHOD,
			$key,
			OPENSSL_RAW_DATA,
			$iv
		);

		return is_string($plaintext) ? $plaintext : '';
	}

	/**
	 * Get the decrypted SMTP password.
	 *
	 * @return string
	 */
	private function get_decrypted_password() {
		$settings = $this->get_settings();
		return $this->decrypt_secret($settings['password_encrypted']);
	}

	/**
	 * Build admin URL.
	 *
	 * @param array $args Optional query args.
	 * @return string
	 */
	private function admin_url($args = array()) {
		return add_query_arg($args, network_admin_url('admin.php?page=' . self::MENU_SLUG));
	}

	/**
	 * Redirect back to settings page with notice args.
	 *
	 * @param array $args Query arguments.
	 * @return void
	 */
	private function redirect_with_notice($args = array()) {
		wp_safe_redirect($this->admin_url($args));
		exit;
	}

	/**
	 * Whether current user can manage network SMTP settings.
	 *
	 * @return bool
	 */
	private function can_manage() {
		return current_user_can('manage_network_options');
	}

	/**
	 * Render settings page.
	 *
	 * @return void
	 */
	public function render_page() {
		if (!$this->can_manage()) {
			wp_die(esc_html__('You do not have permission to access this page.', 'network-basic-smtp'));
		}

		$settings      = $this->get_settings();
		$current_user  = wp_get_current_user();
		$updated       = isset($_GET['updated']) ? sanitize_text_field(wp_unslash($_GET['updated'])) : '';
		$tested        = isset($_GET['tested']) ? sanitize_text_field(wp_unslash($_GET['tested'])) : '';
		$error         = isset($_GET['error']) ? sanitize_text_field(wp_unslash($_GET['error'])) : '';
		$key_missing   = ('' === $this->get_encryption_key());
		$has_password  = $this->has_stored_password();

		?>
		<div class="wrap">
			<h1>Network SMTP</h1>
			<p>These settings apply across the entire multisite network.</p>

			<?php if ('1' === $updated) : ?>
				<div class="notice notice-success is-dismissible">
					<p>SMTP settings saved.</p>
				</div>
			<?php endif; ?>

			<?php if ('1' === $tested) : ?>
				<div class="notice notice-success is-dismissible">
					<p>Test email sent successfully.</p>
				</div>
			<?php endif; ?>

			<?php if ('' !== $error) : ?>
				<div class="notice notice-error">
					<p><?php echo esc_html(rawurldecode($error)); ?></p>
				</div>
			<?php endif; ?>

			<?php if ($key_missing) : ?>
				<div class="notice notice-error">
					<p>
						<strong>Encryption key missing.</strong>
						Add <code>define('NBSMTP_ENCRYPTION_KEY', 'your-long-random-secret');</code>
						to <code>wp-config.php</code> before saving a password.
					</p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url(network_admin_url('edit.php?action=nbsmtp_save')); ?>">
				<?php wp_nonce_field(self::NONCE_SAVE); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="nbsmtp_host">SMTP Host</label>
						</th>
						<td>
							<input
								name="host"
								id="nbsmtp_host"
								type="text"
								class="regular-text"
								value="<?php echo esc_attr($settings['host']); ?>"
								autocomplete="off"
							/>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="nbsmtp_port">Port</label>
						</th>
						<td>
							<input
								name="port"
								id="nbsmtp_port"
								type="number"
								min="1"
								max="65535"
								class="small-text"
								value="<?php echo esc_attr($settings['port']); ?>"
							/>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="nbsmtp_encryption">Encryption</label>
						</th>
						<td>
							<select name="encryption" id="nbsmtp_encryption">
								<option value="none" <?php selected($settings['encryption'], 'none'); ?>>None</option>
								<option value="ssl" <?php selected($settings['encryption'], 'ssl'); ?>>SSL</option>
								<option value="tls" <?php selected($settings['encryption'], 'tls'); ?>>TLS</option>
							</select>
						</td>
					</tr>

					<tr>
						<th scope="row">Authentication</th>
						<td>
							<label>
								<input
									name="auth"
									id="nbsmtp_auth"
									type="checkbox"
									value="1"
									<?php checked((int) $settings['auth'], 1); ?>
								/>
								Use SMTP authentication
							</label>
						</td>
					</tr>

					<tr id="nbsmtp_username_row">
						<th scope="row">
							<label for="nbsmtp_username">Username</label>
						</th>
						<td>
							<input
								name="username"
								id="nbsmtp_username"
								type="text"
								class="regular-text"
								value="<?php echo esc_attr($settings['username']); ?>"
								autocomplete="off"
							/>
						</td>
					</tr>

					<tr id="nbsmtp_password_row">
						<th scope="row">
							<label for="nbsmtp_password">Password</label>
						</th>
						<td>
							<input
								name="password"
								id="nbsmtp_password"
								type="password"
								class="regular-text"
								value=""
								autocomplete="new-password"
							/>
							<p class="description">
								Leave blank to keep the currently stored password.
								<?php if ($has_password) : ?>
									A password is currently stored.
								<?php else : ?>
									No password is currently stored.
								<?php endif; ?>
							</p>
							<label>
								<input type="checkbox" name="clear_password" id="nbsmtp_clear_password" value="1" />
								Clear stored password
							</label>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="nbsmtp_from_email">From Email</label>
						</th>
						<td>
							<input
								name="from_email"
								id="nbsmtp_from_email"
								type="email"
								class="regular-text"
								value="<?php echo esc_attr($settings['from_email']); ?>"
								autocomplete="off"
							/>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="nbsmtp_from_name">From Name</label>
						</th>
						<td>
							<input
								name="from_name"
								id="nbsmtp_from_name"
								type="text"
								class="regular-text"
								value="<?php echo esc_attr($settings['from_name']); ?>"
								autocomplete="off"
							/>
						</td>
					</tr>

					<tr>
						<th scope="row">Default Footer</th>
						<td>
							<label>
								<input
									name="footer_enabled"
									id="nbsmtp_footer_enabled"
									type="checkbox"
									value="1"
									<?php checked((int) $settings['footer_enabled'], 1); ?>
								/>
								Append a default footer to outgoing email
							</label>
						</td>
					</tr>

					<tr id="nbsmtp_footer_text_row">
						<th scope="row">
							<label for="nbsmtp_footer_text">Footer Text</label>
						</th>
						<td>
							<textarea
								name="footer_text"
								id="nbsmtp_footer_text"
								class="large-text"
								rows="6"
								placeholder="Example:\n--\nHope Ignites Network\nPlease do not reply to this message."
							><?php echo esc_textarea($settings['footer_text']); ?></textarea>
							<p class="description">
								Plain text footer appended to every outgoing email sent through WordPress.
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button('Save SMTP Settings'); ?>
			</form>

			<hr>

			<h2>Send Test Email</h2>
			<p>
				This sends a test message using the configured SMTP server to your current
				WordPress account email.
			</p>

			<?php if ($current_user && !empty($current_user->user_email)) : ?>
				<p>
					Test email will be sent to:
					<strong><?php echo esc_html($current_user->user_email); ?></strong>
				</p>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url(network_admin_url('edit.php?action=nbsmtp_test')); ?>">
				<?php wp_nonce_field(self::NONCE_TEST); ?>
				<?php submit_button('Send Test Email to Me', 'secondary'); ?>
			</form>

			<hr style="margin-top: 2em;">
			<p style="color: #646970; font-size: 0.9em;">
				Network Basic SMTP v<?php echo esc_html(NBSMTP_VERSION); ?> | 
				Licensed under the <a href="https://opensource.org/licenses/MIT" target="_blank" rel="noopener noreferrer">MIT License</a> | 
			<a href="https://637digital.com" target="_blank" rel="noopener noreferrer">637 Digital Solutions</a>
		</p>
	</div>
	<?php
}

/**
 * Save settings.
 *
 * @return void
 */
public function handle_save() {
		if (!$this->can_manage()) {
			wp_die(esc_html__('You do not have permission to perform this action.', 'network-basic-smtp'));
		}

		check_admin_referer(self::NONCE_SAVE);

		$existing = $this->get_settings();

		$host           = isset($_POST['host']) ? sanitize_text_field(wp_unslash($_POST['host'])) : '';
		$port           = isset($_POST['port']) ? absint(wp_unslash($_POST['port'])) : 587;
		$encryption     = isset($_POST['encryption']) ? sanitize_text_field(wp_unslash($_POST['encryption'])) : 'tls';
		$username       = isset($_POST['username']) ? sanitize_text_field(wp_unslash($_POST['username'])) : '';
		$from_email     = isset($_POST['from_email']) ? sanitize_email(wp_unslash($_POST['from_email'])) : '';
		$from_name      = isset($_POST['from_name']) ? sanitize_text_field(wp_unslash($_POST['from_name'])) : '';
		$auth           = isset($_POST['auth']) ? 1 : 0;
		$footer_enabled = isset($_POST['footer_enabled']) ? 1 : 0;
		$footer_text    = isset($_POST['footer_text']) ? sanitize_textarea_field(wp_unslash($_POST['footer_text'])) : '';

		if (!in_array($encryption, array('none', 'ssl', 'tls'), true)) {
			$encryption = 'tls';
		}

		if ($port < 1 || $port > 65535) {
			$port = 587;
		}

		if ('' !== $from_email && !is_email($from_email)) {
			$this->redirect_with_notice(array(
				'error' => rawurlencode('Please enter a valid From Email address.'),
			));
		}

		if (!$footer_enabled) {
			$footer_text = '';
		}

		$password_encrypted = $existing['password_encrypted'];
		$clear_password = !empty($_POST['clear_password']);
		$new_password_provided = isset($_POST['password']) && '' !== wp_unslash($_POST['password']);

		if (!$auth) {
			$username = '';
			$password_encrypted = '';
		} elseif ($clear_password) {
			$password_encrypted = '';
		} elseif ($new_password_provided) {
			$raw_password = wp_unslash($_POST['password']);

			if ('' === $this->get_encryption_key()) {
				$this->redirect_with_notice(array(
					'error' => rawurlencode('Cannot save password because NBSMTP_ENCRYPTION_KEY is missing from wp-config.php.'),
				));
			}

			$encrypted = $this->encrypt_secret($raw_password);

			if ('' === $encrypted) {
				$this->redirect_with_notice(array(
					'error' => rawurlencode('Failed to encrypt and store the SMTP password.'),
				));
			}

			$password_encrypted = $encrypted;
		}

		$settings = array(
			'host'               => $host,
			'port'               => $port,
			'encryption'         => $encryption,
			'username'           => $username,
			'password_encrypted' => $password_encrypted,
			'from_email'         => $from_email,
			'from_name'          => $from_name,
			'auth'               => $auth,
			'footer_enabled'     => $footer_enabled,
			'footer_text'        => $footer_text,
		);

		$this->update_settings($settings);

		$this->redirect_with_notice(array(
			'updated' => '1',
		));
	}

	/**
	 * Send a test email to the logged-in user.
	 *
	 * @return void
	 */
	public function handle_test() {
		if (!$this->can_manage()) {
			wp_die(esc_html__('You do not have permission to perform this action.', 'network-basic-smtp'));
		}

		check_admin_referer(self::NONCE_TEST);

		$user = wp_get_current_user();

		if (!$user || empty($user->user_email) || !is_email($user->user_email)) {
			$this->redirect_with_notice(array(
				'error' => rawurlencode('Unable to determine your user email address.'),
			));
		}

		$this->last_mail_error = '';

		$to = $user->user_email;
		$subject = 'Network SMTP Test Email';
		$message = sprintf(
			"Hello %s,\n\nThis is a test email sent through the configured SMTP server for your WordPress multisite network.\n\nIf you received this message, SMTP is working correctly.\n\nSent from: %s",
			$user->display_name ? $user->display_name : $user->user_login,
			network_home_url()
		);
		$headers = array('Content-Type: text/plain; charset=UTF-8');

		$sent = wp_mail($to, $subject, $message, $headers);

		if (!$sent) {
			$error_message = $this->last_mail_error ? $this->last_mail_error : 'Test email failed to send. Check SMTP settings and server configuration.';

			$this->redirect_with_notice(array(
				'error' => rawurlencode($error_message),
			));
		}

		$this->redirect_with_notice(array(
			'tested' => '1',
		));
	}

	/**
	 * Capture wp_mail failure details for display after a test send.
	 *
	 * @param WP_Error $wp_error Mail error object.
	 * @return void
	 */
	public function capture_mail_error($wp_error) {
		if (!is_wp_error($wp_error)) {
			return;
		}

		$message = $wp_error->get_error_message();

		if ('' === $message) {
			$message = 'Mail send failed for an unknown reason.';
		}

		$data = $wp_error->get_error_data();

		if (is_array($data) && !empty($data['phpmailer_exception_code'])) {
			$message .= ' (PHPMailer code: ' . absint($data['phpmailer_exception_code']) . ')';
		}

		$this->last_mail_error = $message;
	}

	/**
	 * Apply SMTP settings to PHPMailer.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer PHPMailer instance.
	 * @return void
	 */
	public function configure_phpmailer($phpmailer) {
		$settings = $this->get_settings();

		if (empty($settings['host']) || empty($settings['port'])) {
			return;
		}

		$phpmailer->isSMTP();
		$phpmailer->Host = $settings['host'];
		$phpmailer->Port = (int) $settings['port'];
		$phpmailer->SMTPAuth = !empty($settings['auth']);

		if (!empty($settings['auth'])) {
			$phpmailer->Username = $settings['username'];
			$phpmailer->Password = $this->get_decrypted_password();
		}

		if ('none' !== $settings['encryption']) {
			$phpmailer->SMTPSecure = $settings['encryption'];
		} else {
			$phpmailer->SMTPSecure = '';
		}

		if (!empty($settings['from_email']) && is_email($settings['from_email'])) {
			$from_name = !empty($settings['from_name']) ? $settings['from_name'] : get_network()->site_name;

			$phpmailer->setFrom($settings['from_email'], $from_name, false);
			$phpmailer->Sender = $settings['from_email'];
		}
	}

	/**
	 * Append configured footer to outgoing mail.
	 *
	 * @param array $args wp_mail arguments.
	 * @return array
	 */
	public function append_footer_to_mail($args) {
		$settings = $this->get_settings();

		if (empty($settings['footer_enabled']) || '' === trim($settings['footer_text'])) {
			return $args;
		}

		$message = isset($args['message']) ? (string) $args['message'] : '';
		$headers = isset($args['headers']) ? $args['headers'] : array();
		$is_html = false;

		if (is_array($headers)) {
			foreach ($headers as $header) {
				if (is_string($header) && false !== stripos($header, 'Content-Type:') && false !== stripos($header, 'text/html')) {
					$is_html = true;
					break;
				}
			}
		} elseif (is_string($headers)) {
			if (false !== stripos($headers, 'Content-Type:') && false !== stripos($headers, 'text/html')) {
				$is_html = true;
			}
		}

		$footer = trim($settings['footer_text']);

		if ('' === $footer) {
			return $args;
		}

		if ($is_html) {
			$footer_html = nl2br(esc_html($footer));
			$args['message'] = rtrim($message) . '<br><br>' . $footer_html;
		} else {
			$args['message'] = rtrim($message) . "\n\n" . $footer;
		}

		return $args;
	}

	/**
	 * Print small admin-page JS/CSS for auth and footer field toggling.
	 *
	 * @return void
	 */
	public function render_admin_assets() {
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;

		if (!$screen || 'settings_page_' . self::MENU_SLUG !== $screen->id) {
			return;
		}
		?>
		<style>
			#nbsmtp_username_row.is-disabled,
			#nbsmtp_password_row.is-disabled,
			#nbsmtp_footer_text_row.is-disabled {
				opacity: 0.55;
			}
		</style>

		<script>
			document.addEventListener('DOMContentLoaded', function () {
				const authCheckbox = document.getElementById('nbsmtp_auth');
				const usernameRow = document.getElementById('nbsmtp_username_row');
				const passwordRow = document.getElementById('nbsmtp_password_row');
				const usernameInput = document.getElementById('nbsmtp_username');
				const passwordInput = document.getElementById('nbsmtp_password');
				const clearPasswordInput = document.getElementById('nbsmtp_clear_password');

				const footerCheckbox = document.getElementById('nbsmtp_footer_enabled');
				const footerRow = document.getElementById('nbsmtp_footer_text_row');
				const footerTextarea = document.getElementById('nbsmtp_footer_text');

				function syncAuthFields() {
					if (!authCheckbox || !usernameRow || !passwordRow || !usernameInput || !passwordInput) {
						return;
					}

					const enabled = authCheckbox.checked;

					usernameInput.disabled = !enabled;
					passwordInput.disabled = !enabled;

					if (clearPasswordInput) {
						clearPasswordInput.disabled = !enabled;
						if (!enabled) {
							clearPasswordInput.checked = false;
						}
					}

					usernameRow.classList.toggle('is-disabled', !enabled);
					passwordRow.classList.toggle('is-disabled', !enabled);

					if (!enabled) {
						passwordInput.value = '';
					}
				}

				function syncFooterFields() {
					if (!footerCheckbox || !footerRow || !footerTextarea) {
						return;
					}

					const enabled = footerCheckbox.checked;
					footerTextarea.disabled = !enabled;
					footerRow.classList.toggle('is-disabled', !enabled);
				}

				if (authCheckbox) {
					authCheckbox.addEventListener('change', syncAuthFields);
				}

				if (footerCheckbox) {
					footerCheckbox.addEventListener('change', syncFooterFields);
				}

				syncAuthFields();
				syncFooterFields();
			});
		</script>
		<?php
	}
}
