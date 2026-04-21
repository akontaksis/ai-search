<?php

namespace AISearch;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin {

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_notices', [ $this, 'maybe_show_api_key_notice' ] );
		add_action( 'wp_ajax_ais_reindex', [ $this, 'handle_reindex' ] );
	}

	public function add_menu(): void {
		add_options_page(
			'AI Search',
			'AI Search',
			'manage_options',
			'ais-settings',
			[ $this, 'render_settings_page' ]
		);
	}

	public function register_settings(): void {
		register_setting( 'ais_settings_group', 'ais_anthropic_api_key', [
			'sanitize_callback' => [ $this, 'sanitize_api_key' ],
		] );
		register_setting( 'ais_settings_group', 'ais_site_name', [
			'sanitize_callback' => 'sanitize_text_field',
		] );
		register_setting( 'ais_settings_group', 'ais_placeholder', [
			'sanitize_callback' => 'sanitize_text_field',
		] );
		register_setting( 'ais_settings_group', 'ais_fallback_message', [
			'sanitize_callback' => 'sanitize_textarea_field',
		] );
	}

	public function sanitize_api_key( string $new_value ): string {
		$new_value = sanitize_text_field( $new_value );

		if ( '' === $new_value ) {
			return get_option( 'ais_anthropic_api_key', '' );
		}

		return Crypto::encrypt( $new_value );
	}

	public function maybe_show_api_key_notice(): void {
		$screen = get_current_screen();
		if ( $screen && 'settings_page_ais-settings' === $screen->id ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( empty( get_option( 'ais_anthropic_api_key' ) ) ) {
			printf(
				'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
				esc_html( 'AI Search: Δεν έχει οριστεί Anthropic API key.' ),
				esc_url( admin_url( 'options-general.php?page=ais-settings' ) ),
				esc_html( 'Ρυθμίστε εδώ →' )
			);
		}
	}

	public function handle_reindex(): void {
		check_ajax_referer( 'ais_reindex_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Δεν έχετε δικαίωμα.' );
		}

		$indexer = new Indexer();
		$count   = $indexer->index_all();

		wp_send_json_success( [
			'count'   => $count,
			'message' => sprintf( 'Ευρετηριάστηκαν %d σελίδες.', $count ),
		] );
	}

	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1>AI Search — Ρυθμίσεις</h1>

			<form method="post" action="options.php">
				<?php settings_fields( 'ais_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="ais_anthropic_api_key">Anthropic API Key</label>
						</th>
						<td>
							<?php $has_key = ! empty( get_option( 'ais_anthropic_api_key' ) ); ?>
							<input
								type="password"
								id="ais_anthropic_api_key"
								name="ais_anthropic_api_key"
								value=""
								class="regular-text"
								autocomplete="new-password"
								placeholder="<?php echo $has_key ? esc_attr( '••••••••  (αφήστε κενό για να διατηρήσετε)' ) : 'sk-ant-...'; ?>"
							>
							<?php if ( $has_key ) : ?>
								<p class="description" style="color:#1a7431;">
									✓ Το API key είναι αποθηκευμένο κρυπτογραφημένο (AES-256).
									Εισάγετε νέο μόνο αν θέλετε να το αλλάξετε.
								</p>
							<?php else : ?>
								<p class="description">
									Βρείτε το API key στο
									<a href="https://console.anthropic.com/" target="_blank" rel="noopener">console.anthropic.com</a>.
									Αποθηκεύεται κρυπτογραφημένο με AES-256.
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="ais_site_name">Όνομα οργανισμού</label>
						</th>
						<td>
							<input
								type="text"
								id="ais_site_name"
								name="ais_site_name"
								value="<?php echo esc_attr( get_option( 'ais_site_name', get_bloginfo( 'name' ) ) ); ?>"
								class="regular-text"
								placeholder="π.χ. Δήμος Κηφισιάς"
							>
							<p class="description">
								Χρησιμοποιείται στο AI prompt για να κατανοεί το context του site.
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="ais_placeholder">Placeholder κειμένου</label>
						</th>
						<td>
							<input
								type="text"
								id="ais_placeholder"
								name="ais_placeholder"
								value="<?php echo esc_attr( get_option( 'ais_placeholder', 'Τι ψάχνετε;' ) ); ?>"
								class="regular-text"
							>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="ais_fallback_message">Μήνυμα χωρίς αποτέλεσμα</label>
						</th>
						<td>
							<textarea
								id="ais_fallback_message"
								name="ais_fallback_message"
								rows="3"
								class="regular-text"
							><?php echo esc_textarea( get_option( 'ais_fallback_message', 'Δεν βρέθηκε σχετική υπηρεσία.' ) ); ?></textarea>
						</td>
					</tr>
				</table>
				<?php submit_button( 'Αποθήκευση' ); ?>
			</form>

			<hr>

			<h2>Διαχείριση Ευρετηρίου</h2>
			<p>Ευρετηριάζει όλες τις δημοσιευμένες σελίδες από την αρχή.</p>
			<button id="ais-reindex-btn" class="button button-secondary">Re-index τώρα</button>
			<span id="ais-reindex-status" style="margin-left:12px;color:#555;"></span>

			<hr>

			<h2>Shortcode</h2>
			<p>Χρησιμοποιήστε <code>[ai_search]</code> σε οποιαδήποτε σελίδα ή widget.</p>

			<?php $this->render_index_table(); ?>
		</div>

		<script>
		(function () {
			const btn    = document.getElementById('ais-reindex-btn');
			const status = document.getElementById('ais-reindex-status');
			if (!btn) return;

			btn.addEventListener('click', function () {
				btn.disabled = true;
				status.textContent = 'Ευρετηρίαση...';

				const data = new FormData();
				data.append('action', 'ais_reindex');
				data.append('nonce', '<?php echo esc_js( wp_create_nonce( 'ais_reindex_nonce' ) ); ?>');

				fetch(ajaxurl, { method: 'POST', body: data })
					.then(r => r.json())
					.then(res => {
						status.textContent = res.success ? res.data.message : 'Σφάλμα: ' + res.data;
						btn.disabled = false;
					})
					.catch(() => {
						status.textContent = 'Σφάλμα σύνδεσης.';
						btn.disabled = false;
					});
			});
		})();
		</script>
		<?php
	}

	private function render_index_table(): void {
		global $wpdb;
		$table   = $wpdb->prefix . 'ai_search_index';
		$entries = $wpdb->get_results(
			"SELECT id, title, url, category, last_indexed FROM $table ORDER BY id DESC LIMIT 50"
		);

		if ( empty( $entries ) ) {
			echo '<p><em>Το ευρετήριο είναι άδειο. Πατήστε "Re-index τώρα".</em></p>';
			return;
		}

		echo '<h2>Ευρετηριασμένες Σελίδες (τελευταίες 50)</h2>';
		echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Τίτλος</th><th>Κατηγορία</th><th>Τελευταία ευρετηρίαση</th></tr></thead><tbody>';

		foreach ( $entries as $entry ) {
			printf(
				'<tr><td>%d</td><td><a href="%s" target="_blank">%s</a></td><td>%s</td><td>%s</td></tr>',
				(int) $entry->id,
				esc_url( $entry->url ),
				esc_html( $entry->title ),
				esc_html( $entry->category ?? '—' ),
				esc_html( $entry->last_indexed ?? '—' )
			);
		}

		echo '</tbody></table>';
	}
}
