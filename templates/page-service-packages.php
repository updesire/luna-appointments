<?php
/** Full-width service packages landing template. */
if (! defined('ABSPATH')) {
	exit;
}

get_header();
echo Luna_Appointments_Service_Packages::render_landing(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
get_footer();
