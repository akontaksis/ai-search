<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$placeholder = get_option( 'ais_placeholder', 'Τι ψάχνετε;' );
$fallback    = get_option( 'ais_fallback_message', 'Δεν βρέθηκε σχετική υπηρεσία.' );
?>
<div class="ais-wrapper">

	<div class="ais-header">
		<span class="ais-badge">
			<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2L9.09 9.09 2 12l7.09 2.91L12 22l2.91-7.09L22 12l-7.09-2.91z"/></svg>
			AI Αναζήτηση
		</span>
		<p class="ais-subtitle">Γράψτε αυτό που ψάχνετε με δικά σας λόγια</p>
	</div>

	<form class="ais-form" role="search" aria-label="<?php esc_attr_e( 'AI Αναζήτηση', 'ai-search' ); ?>">
		<div class="ais-input-wrap">
			<svg class="ais-search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
				<circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
			</svg>
			<input
				type="text"
				class="ais-input"
				placeholder="<?php echo esc_attr( $placeholder ); ?>"
				aria-label="<?php esc_attr_e( 'Αναζήτηση', 'ai-search' ); ?>"
				autocomplete="off"
				maxlength="300"
			>
			<button type="submit" class="ais-btn" aria-label="Αναζήτηση">
				<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
				<span><?php esc_html_e( 'Αναζήτηση', 'ai-search' ); ?></span>
			</button>
		</div>
	</form>

	<div class="ais-examples" aria-label="Παραδείγματα αναζήτησης"></div>

	<div class="ais-loading" aria-live="polite">
		<div class="ais-dots"><span></span><span></span><span></span></div>
		<span><?php esc_html_e( 'Αναλύω το ερώτημά σας...', 'ai-search' ); ?></span>
	</div>

	<div class="ais-error" role="alert"></div>

	<div class="ais-results" role="region" aria-label="<?php esc_attr_e( 'Αποτελέσματα', 'ai-search' ); ?>"></div>

	<div class="ais-no-results" aria-live="polite">
		<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/><path d="M11 8v4M11 16h.01"/></svg>
		<p><?php echo esc_html( $fallback ); ?></p>
	</div>

</div>
