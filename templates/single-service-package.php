<?php
/** Single service package template. */
if (! defined('ABSPATH')) { exit; }
get_header();
echo Luna_Appointments_Service_Packages::render_single(get_queried_object_id()); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
get_footer();
