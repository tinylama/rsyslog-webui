<?php

	date_default_timezone_set( 'UTC' );

	// Mysql syslog database config
	$mysql_server = '127.0.0.1';
	$mysql_database = '';
	$mysql_user = '';
	$mysql_password = '';

	// Site Settings
	$site_name = 'System Logs';

	// Database Settings
	$keep_logs_for_days = 90;

	// Auth (optional; mirror docker env names in production via your own wiring)
	// Requires Users / Roles / UserRoles tables — see docker/mysql/init/01-schema.sql
	$auth_enabled = true;
	$admin_username = 'admin';
	$admin_password = 'change-me-immediately';
	$session_secret = 'change-me';

	// Microsoft Entra ID (Azure AD) — optional OIDC SSO
	$azure_ad_enabled = false;
	$azure_ad_tenant_id = '';
	$azure_ad_client_id = '';
	$azure_ad_client_secret = '';
	$azure_ad_redirect_uri = ''; // leave empty to use https://your-host/auth/azure/callback.php
	$azure_ad_admin_group_id = ''; // optional Entra group object ID → maps to app admin role (emit “groups” claim)

	// Graph application permission Mail.Send — sender mailbox UPN (see README)
	$graph_mail_sender = '';

	// Encryption key for MFA secrets and DB-stored client secrets (falls back to session_secret)
	// $rsyslog_app_secret = ''; // set RSYSLOG_APP_SECRET in env

// HTTPS IP allowlist (optional)
// When enabled, requests over HTTPS are allowed only from the configured IPs/CIDRs.
$https_whitelist_enabled = false;
$https_whitelist_cidrs = '';
// Example (set in env / config.php):
// RSYSLOG_HTTPS_WHITELIST_ENABLED=1
// RSYSLOG_HTTPS_WHITELIST_CIDRS="192.168.1.10,192.168.1.0/24"
