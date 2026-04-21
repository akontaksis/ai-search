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
		add_submenu_page(
			'options-general.php',
			'AI Search — Dashboard',
			'AI Search Dashboard',
			'manage_options',
			'ais-dashboard',
			[ $this, 'render_dashboard' ]
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

			<h3 style="margin-bottom:4px;">Βήμα 1 — Basic Re-index</h3>
			<p style="margin-top:0;">Σκανάρει σελίδες/posts και εξάγει βασικά keywords από το κείμενο.</p>
			<button id="ais-reindex-btn" class="button button-secondary">Re-index τώρα</button>
			<span id="ais-reindex-status" style="margin-left:12px;color:#555;"></span>

			<h3 style="margin-bottom:4px;margin-top:20px;">Βήμα 2 — AI Enhancement</h3>
			<p style="margin-top:0;">
				Ο Claude διαβάζει κάθε σελίδα και γράφει καλύτερα keywords, περιγραφή, κατηγορία και τύπο υπηρεσίας.<br>
				<strong>Εκτελέστε μετά το Re-index.</strong> Κόστος: ~$0.01 ανά 20 σελίδες.
			</p>
			<?php
			global $wpdb;
			$pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ai_search_index WHERE category IS NULL" );
			$total   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ai_search_index" );
			?>
			<button id="ais-enhance-btn" class="button button-primary" <?php echo $pending === 0 ? 'disabled' : ''; ?>>
				AI Enhance (<?php echo $pending; ?> εκκρεμούν)
			</button>
			<span id="ais-enhance-status" style="margin-left:12px;color:#555;"></span>
			<div id="ais-enhance-progress" style="display:none;margin-top:8px;max-width:400px;">
				<div style="background:#e0e0e0;border-radius:4px;height:12px;overflow:hidden;">
					<div id="ais-enhance-bar" style="background:#0073aa;height:100%;width:0%;transition:width 0.3s;"></div>
				</div>
				<small id="ais-enhance-label" style="color:#555;"></small>
			</div>

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

		// AI Enhancement
		(function () {
			const btn      = document.getElementById('ais-enhance-btn');
			const status   = document.getElementById('ais-enhance-status');
			const progress = document.getElementById('ais-enhance-progress');
			const bar      = document.getElementById('ais-enhance-bar');
			const label    = document.getElementById('ais-enhance-label');
			if (!btn) return;

			const nonce    = '<?php echo esc_js( wp_create_nonce( 'ais_enhance_nonce' ) ); ?>';
			const total    = <?php echo (int) $total; ?>;
			let   done     = total - <?php echo (int) $pending; ?>;
			const DELAY_MS = 13000; // 5 req/min = 1 req/12s, +1s buffer

			btn.addEventListener('click', function () {
				btn.disabled = true;
				progress.style.display = 'block';
				processNext();
			});

			function processNext() {
				const data = new FormData();
				data.append('action', 'ais_enhance_next');
				data.append('nonce', nonce);

				fetch(ajaxurl, { method: 'POST', body: data })
					.then(r => r.json())
					.then(res => {
						if (!res.success) {
							status.textContent = 'Σφάλμα: ' + res.data;
							btn.disabled = false;
							return;
						}
						done++;
						const pct = total > 0 ? Math.round((done / total) * 100) : 100;
						bar.style.width = pct + '%';
						label.textContent = res.data.enhanced
							? 'Επεξεργάστηκε: ' + res.data.enhanced + ' (' + done + '/' + total + ')'
							: '';

						if (res.data.remaining > 0) {
							const remaining = res.data.remaining;
							let countdown = Math.ceil(DELAY_MS / 1000);
							const timer = setInterval(() => {
								countdown--;
								status.textContent = 'Αναμονή ' + countdown + 's… (εκκρεμούν ' + remaining + ')';
								if (countdown <= 0) clearInterval(timer);
							}, 1000);
							setTimeout(processNext, DELAY_MS);
						} else {
							status.textContent = '✓ Ολοκληρώθηκε! Ανανεώστε τη σελίδα.';
							btn.textContent = 'AI Enhance (0 εκκρεμούν)';
						}
					})
					.catch(() => {
						status.textContent = 'Σφάλμα σύνδεσης. Δοκιμάστε ξανά.';
						btn.disabled = false;
					});
			}
		})();
		</script>
		<?php
	}

	private function render_index_table(): void {
		global $wpdb;
		$table    = $wpdb->prefix . 'ai_search_index';
		$per_page = 50;
		$total    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );

		if ( 0 === $total ) {
			echo '<p><em>Το ευρετήριο είναι άδειο. Πατήστε "Re-index τώρα".</em></p>';
			return;
		}

		$total_pages = (int) ceil( $total / $per_page );
		$current     = max( 1, min( $total_pages, (int) ( $_GET['ais_paged'] ?? 1 ) ) );
		$offset      = ( $current - 1 ) * $per_page;

		$entries = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, post_id, title, url, description, keywords, category, service_type, priority, is_external, last_indexed
				 FROM $table ORDER BY id DESC LIMIT %d OFFSET %d",
				$per_page,
				$offset
			)
		);

		$base_url = add_query_arg( [ 'page' => 'ais-settings' ], admin_url( 'options-general.php' ) );

		printf(
			'<h2>Ευρετηριασμένες Σελίδες <span style="font-weight:normal;color:#888;">(%d-%d από %d)</span></h2>',
			$offset + 1,
			min( $offset + $per_page, $total ),
			$total
		);
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
		<?php if ( $total_pages > 1 ) : ?>
		<div style="margin-bottom:8px;">
			<?php if ( $current > 1 ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'ais_paged', $current - 1, $base_url ) ); ?>">← Προηγούμενη</a>
			<?php endif; ?>
			<?php for ( $p = 1; $p <= $total_pages; $p++ ) : ?>
				<?php if ( $p === $current ) : ?>
					<span class="button button-primary" style="cursor:default;"><?php echo $p; ?></span>
				<?php elseif ( $p === 1 || $p === $total_pages || abs( $p - $current ) <= 2 ) : ?>
					<a class="button" href="<?php echo esc_url( add_query_arg( 'ais_paged', $p, $base_url ) ); ?>"><?php echo $p; ?></a>
				<?php elseif ( abs( $p - $current ) === 3 ) : ?>
					<span style="padding:0 4px;">…</span>
				<?php endif; ?>
			<?php endfor; ?>
			<?php if ( $current < $total_pages ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'ais_paged', $current + 1, $base_url ) ); ?>">Επόμενη →</a>
			<?php endif; ?>
		</div>
		<?php endif; ?>
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

		<?php if ( $total_pages > 1 ) : ?>
		<div style="margin-top:8px;">
			<?php if ( $current > 1 ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'ais_paged', $current - 1, $base_url ) ); ?>">← Προηγούμενη</a>
			<?php endif; ?>
			<span style="margin:0 8px;color:#555;">Σελίδα <?php echo $current; ?> από <?php echo $total_pages; ?></span>
			<?php if ( $current < $total_pages ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'ais_paged', $current + 1, $base_url ) ); ?>">Επόμενη →</a>
			<?php endif; ?>
		</div>
		<?php endif; ?>
		<?php
	}

	public function render_dashboard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $wpdb;
		$log   = $wpdb->prefix . 'ai_search_log';
		$index = $wpdb->prefix . 'ai_search_index';

		$total_queries  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $log" );
		$today_queries  = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM $log WHERE DATE(created_at) = %s", current_time( 'Y-m-d' )
		) );
		$week_queries   = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM $log WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
		);
		$cache_hits     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $log WHERE cache_hit = 1" );
		$no_results     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $log WHERE matched_index_id IS NULL AND cache_hit = 0" );
		$avg_response   = (int) $wpdb->get_var( "SELECT AVG(response_time_ms) FROM $log WHERE response_time_ms > 0" );
		$indexed_total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $index" );
		$enhanced_total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $index WHERE category IS NOT NULL" );

		$cache_pct    = $total_queries > 0 ? round( ( $cache_hits / $total_queries ) * 100 ) : 0;
		$noresult_pct = $total_queries > 0 ? round( ( $no_results / $total_queries ) * 100 ) : 0;

		$top_queries = $wpdb->get_results(
			"SELECT query, COUNT(*) as cnt FROM $log
			 WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
			 GROUP BY query ORDER BY cnt DESC LIMIT 20"
		);

		$failed_queries = $wpdb->get_results(
			"SELECT query, COUNT(*) as cnt FROM $log
			 WHERE matched_index_id IS NULL AND cache_hit = 0
			   AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
			 GROUP BY query ORDER BY cnt DESC LIMIT 20"
		);

		$daily = $wpdb->get_results(
			"SELECT DATE(created_at) as day, COUNT(*) as cnt FROM $log
			 WHERE created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
			 GROUP BY DATE(created_at) ORDER BY day ASC"
		);
		?>
		<div class="wrap">
			<h1>AI Search — Dashboard</h1>
			<p><a href="<?php echo esc_url( admin_url( 'options-general.php?page=ais-settings' ) ); ?>">← Ρυθμίσεις</a></p>

			<style>
			.ais-stats{display:flex;gap:16px;flex-wrap:wrap;margin-bottom:24px;}
			.ais-stat{background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:16px 20px;min-width:130px;flex:1;}
			.ais-stat .num{font-size:32px;font-weight:700;color:#0073aa;line-height:1;}
			.ais-stat .lbl{font-size:12px;color:#888;margin-top:4px;}
			.ais-stat.warn .num{color:#b26200;}.ais-stat.good .num{color:#1a7431;}
			.ais-dash-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px;}
			.ais-panel{background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:16px 20px;}
			.ais-panel h3{margin:0 0 12px;font-size:12px;text-transform:uppercase;color:#888;letter-spacing:.05em;}
			.ais-bar-row{display:flex;align-items:center;gap:8px;margin-bottom:6px;font-size:13px;}
			.ais-bar-row .lbl{width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
			.ais-bar-row .bar{flex:1;background:#f0f0f0;border-radius:4px;height:10px;}
			.ais-bar-row .bar-fill{background:#0073aa;height:100%;border-radius:4px;}
			.ais-bar-row .cnt{width:30px;text-align:right;color:#888;}
			@media(max-width:782px){.ais-dash-grid{grid-template-columns:1fr;}}
			</style>

			<div class="ais-stats">
				<div class="ais-stat">
					<div class="num"><?php echo number_format( $total_queries ); ?></div>
					<div class="lbl">Σύνολο αναζητήσεων</div>
				</div>
				<div class="ais-stat">
					<div class="num"><?php echo $today_queries; ?></div>
					<div class="lbl">Σήμερα</div>
				</div>
				<div class="ais-stat">
					<div class="num"><?php echo $week_queries; ?></div>
					<div class="lbl">Τελ. 7 μέρες</div>
				</div>
				<div class="ais-stat <?php echo $cache_pct >= 50 ? 'good' : ''; ?>">
					<div class="num"><?php echo $cache_pct; ?>%</div>
					<div class="lbl">Cache hit rate</div>
				</div>
				<div class="ais-stat <?php echo $noresult_pct > 20 ? 'warn' : 'good'; ?>">
					<div class="num"><?php echo $noresult_pct; ?>%</div>
					<div class="lbl">Χωρίς αποτέλεσμα</div>
				</div>
				<div class="ais-stat">
					<div class="num"><?php echo $avg_response; ?>ms</div>
					<div class="lbl">Μέσος χρόνος</div>
				</div>
				<div class="ais-stat <?php echo $enhanced_total === $indexed_total && $indexed_total > 0 ? 'good' : ''; ?>">
					<div class="num"><?php echo $enhanced_total; ?>/<?php echo $indexed_total; ?></div>
					<div class="lbl">AI Enhanced</div>
				</div>
			</div>

			<?php if ( ! empty( $daily ) ) : ?>
			<div class="ais-panel" style="margin-bottom:24px;">
				<h3>Αναζητήσεις ανά ημέρα — τελευταίες 14 μέρες</h3>
				<?php $max = max( array_column( $daily, 'cnt' ) ); ?>
				<?php foreach ( $daily as $d ) : $pct = $max > 0 ? round( ( $d->cnt / $max ) * 100 ) : 0; ?>
				<div class="ais-bar-row">
					<span class="lbl"><?php echo esc_html( $d->day ); ?></span>
					<span class="bar"><span class="bar-fill" style="width:<?php echo $pct; ?>%"></span></span>
					<span class="cnt"><?php echo (int) $d->cnt; ?></span>
				</div>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>

			<div class="ais-dash-grid">
				<div class="ais-panel">
					<h3>Top αναζητήσεις — 30 μέρες</h3>
					<?php if ( empty( $top_queries ) ) : ?>
						<p><em>Δεν υπάρχουν δεδομένα ακόμα.</em></p>
					<?php else :
						$max_q = max( array_column( $top_queries, 'cnt' ) );
						foreach ( $top_queries as $q ) :
							$pct = $max_q > 0 ? round( ( $q->cnt / $max_q ) * 100 ) : 0;
					?>
					<div class="ais-bar-row">
						<span class="lbl" title="<?php echo esc_attr( $q->query ); ?>"><?php echo esc_html( $q->query ); ?></span>
						<span class="bar"><span class="bar-fill" style="width:<?php echo $pct; ?>%"></span></span>
						<span class="cnt"><?php echo (int) $q->cnt; ?></span>
					</div>
					<?php endforeach; endif; ?>
				</div>

				<div class="ais-panel">
					<h3>Αναζητήσεις χωρίς αποτέλεσμα — 30 μέρες</h3>
					<?php if ( empty( $failed_queries ) ) : ?>
						<p style="color:#1a7431;"><em>✓ Όλες βρήκαν αποτέλεσμα!</em></p>
					<?php else :
						$max_f = max( array_column( $failed_queries, 'cnt' ) );
						foreach ( $failed_queries as $q ) :
							$pct = $max_f > 0 ? round( ( $q->cnt / $max_f ) * 100 ) : 0;
					?>
					<div class="ais-bar-row">
						<span class="lbl" title="<?php echo esc_attr( $q->query ); ?>"><?php echo esc_html( $q->query ); ?></span>
						<span class="bar"><span class="bar-fill" style="width:<?php echo $pct; ?>%;background:#b26200;"></span></span>
						<span class="cnt"><?php echo (int) $q->cnt; ?></span>
					</div>
					<?php endforeach;
					echo '<p style="margin-top:12px;font-size:12px;color:#888;">💡 Αυτές δεν βρήκαν σελίδα — σκέψου να προσθέσεις σχετικό περιεχόμενο ή keywords.</p>';
					endif; ?>
				</div>
			</div>
		</div>
		<?php
	}
}

