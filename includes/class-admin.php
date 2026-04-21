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
		register_setting( 'ais_settings_group', 'ais_post_types', [
			'sanitize_callback' => [ $this, 'sanitize_post_types' ],
		] );
	}

	public function sanitize_post_types( $value ): string {
		if ( ! is_array( $value ) ) {
			return 'page';
		}

		$allowed = array_keys( $this->get_indexable_post_types() );
		$clean   = array_filter(
			array_map( 'sanitize_key', $value ),
			fn( $pt ) => in_array( $pt, $allowed, true )
		);

		return implode( ',', $clean ) ?: 'page';
	}

	private function get_indexable_post_types(): array {
		$types = get_post_types( [ 'public' => true ], 'objects' );
		// Exclude attachments — they have no meaningful content to search
		unset( $types['attachment'] );
		return $types;
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
			return;
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
					<tr>
						<th scope="row">Post Types προς ευρετηρίαση</th>
						<td>
							<?php
							$saved_types  = explode( ',', get_option( 'ais_post_types', 'page' ) );
							$all_types    = $this->get_indexable_post_types();
							foreach ( $all_types as $slug => $obj ) :
								$checked = in_array( $slug, $saved_types, true );
							?>
							<label style="display:block;margin-bottom:4px;">
								<input
									type="checkbox"
									name="ais_post_types[]"
									value="<?php echo esc_attr( $slug ); ?>"
									<?php checked( $checked ); ?>
								>
								<strong><?php echo esc_html( $obj->labels->name ); ?></strong>
								<span style="color:#888;font-size:12px;">(<?php echo esc_html( $slug ); ?>)</span>
							</label>
							<?php endforeach; ?>
							<p class="description">Επιλέξτε ποιοι τύποι περιεχομένου θα ευρετηριάζονται. Πατήστε "Re-index τώρα" μετά από κάθε αλλαγή.</p>
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
			$wpdb->prepare(
				"SELECT id, post_id, title, url, description, keywords, category, service_type, priority, is_external, last_indexed
				 FROM $table ORDER BY id DESC LIMIT %d",
				50
			)
		);

		if ( empty( $entries ) ) {
			echo '<p><em>Το ευρετήριο είναι άδειο. Πατήστε "Re-index τώρα".</em></p>';
			return;
		}

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
		printf( '<h2>Ευρετηριασμένες Σελίδες <span style="font-weight:normal;color:#888;">(εμφανίζονται 50 από %d)</span></h2>', $total );
		?>
		<style>
		.ais-index-table { font-size:13px; }
		.ais-index-table td { vertical-align:top; padding:6px 8px !important; }
		.ais-index-table .col-desc, .ais-index-table .col-kw { max-width:220px; color:#555; }
		.ais-index-table .col-desc span, .ais-index-table .col-kw span { display:block; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:220px; cursor:help; }
		.ais-badge { display:inline-block; padding:2px 7px; border-radius:10px; font-size:11px; font-weight:600; }
		.ais-badge-cat { background:#e8f4fd; color:#0073aa; }
		.ais-badge-type { background:#edfaed; color:#1a7431; }
		.ais-badge-ext { background:#fff3cd; color:#856404; }
		</style>
		<table class="widefat striped ais-index-table">
			<thead>
				<tr>
					<th>ID</th>
					<th>Τίτλος</th>
					<th class="col-desc">Περιγραφή</th>
					<th class="col-kw">Keywords</th>
					<th>Κατηγορία</th>
					<th>Τύπος</th>
					<th>Prior.</th>
					<th>Ext.</th>
					<th>Τελ. index</th>
				</tr>
			</thead>
			<tbody>
		<?php foreach ( $entries as $e ) : ?>
				<tr>
					<td><?php echo (int) $e->id; ?></td>
					<td>
						<a href="<?php echo esc_url( $e->url ); ?>" target="_blank">
							<?php echo esc_html( $e->title ); ?>
						</a>
						<?php if ( $e->post_id ) : ?>
							<br><small style="color:#aaa;">post #<?php echo (int) $e->post_id; ?></small>
						<?php endif; ?>
					</td>
					<td class="col-desc">
						<?php if ( $e->description ) : ?>
							<span title="<?php echo esc_attr( $e->description ); ?>">
								<?php echo esc_html( $e->description ); ?>
							</span>
						<?php else : ?>
							<em style="color:#bbb;">—</em>
						<?php endif; ?>
					</td>
					<td class="col-kw">
						<?php if ( $e->keywords ) : ?>
							<span title="<?php echo esc_attr( $e->keywords ); ?>">
								<?php echo esc_html( $e->keywords ); ?>
							</span>
						<?php else : ?>
							<em style="color:#bbb;">—</em>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( $e->category ) : ?>
							<span class="ais-badge ais-badge-cat"><?php echo esc_html( $e->category ); ?></span>
						<?php else : ?>
							<em style="color:#bbb;">—</em>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( $e->service_type ) : ?>
							<span class="ais-badge ais-badge-type"><?php echo esc_html( $e->service_type ); ?></span>
						<?php else : ?>
							<em style="color:#bbb;">—</em>
						<?php endif; ?>
					</td>
					<td><?php echo (int) $e->priority; ?></td>
					<td>
						<?php if ( $e->is_external ) : ?>
							<span class="ais-badge ais-badge-ext">ext</span>
						<?php else : ?>
							<span style="color:#bbb;">—</span>
						<?php endif; ?>
					</td>
					<td style="white-space:nowrap;"><?php echo esc_html( $e->last_indexed ?? '—' ); ?></td>
				</tr>
		<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
