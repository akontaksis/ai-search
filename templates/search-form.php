<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="ais-wrapper">
	<form class="ais-form" role="search" aria-label="<?php esc_attr_e( 'Αναζήτηση', 'ai-search' ); ?>">
		<input
			type="text"
			class="ais-input"
			placeholder="<?php echo esc_attr( get_option( 'ais_placeholder', 'Τι ψάχνετε;' ) ); ?>"
			aria-label="<?php esc_attr_e( 'Αναζήτηση', 'ai-search' ); ?>"
			autocomplete="off"
			maxlength="300"
		>
		<button type="submit" class="ais-btn">
			<?php esc_html_e( 'Αναζήτηση', 'ai-search' ); ?>
		</button>
	</form>

	<div class="ais-loading" aria-live="polite">
		<div class="ais-spinner"></div>
		<span><?php esc_html_e( 'Αναζήτηση...', 'ai-search' ); ?></span>
	</div>

	<div class="ais-error" role="alert"></div>

	<div class="ais-results" role="region" aria-label="<?php esc_attr_e( 'Αποτελέσματα', 'ai-search' ); ?>"></div>

	<div class="ais-no-results" aria-live="polite">
		<?php echo esc_html( get_option( 'ais_fallback_message', 'Δεν βρέθηκε σχετική υπηρεσία.' ) ); ?>
	</div>
</div>
