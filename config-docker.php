<?php
// Docker/dev configuration.
// Used automatically when `config.php` is not present (and `.gitignore` prevents
// committing `config.php`).

date_default_timezone_set('UTC');

// MySQL syslog database config
$mysql_server = getenv('RSYSLOG_DB_HOST') ?: '127.0.0.1';
$mysql_database = getenv('RSYSLOG_DB_NAME') ?: 'rsyslogdb';
$mysql_user = getenv('RSYSLOG_DB_USER') ?: 'rsyslog';
$mysql_password = getenv('RSYSLOG_DB_PASSWORD') ?: 'rsyslog';

// Site Settings
$site_name = getenv('RSYSLOG_SITE_NAME') ?: 'System Logs';

// Database Settings
$keep_logs_for_days = getenv('RSYSLOG_KEEP_DAYS') ? intval(getenv('RSYSLOG_KEEP_DAYS')) : 90;

// Auth/RBAC settings
$auth_enabled = getenv('RSYSLOG_AUTH_ENABLED');
$auth_enabled = ($auth_enabled === false || $auth_enabled === null) ? true : ($auth_enabled !== '0');
$session_secret = getenv('RSYSLOG_SESSION_SECRET') ?: 'dev-session-secret-change-me';
$admin_username = getenv('RSYSLOG_ADMIN_USERNAME') ?: 'admin';
$admin_password = getenv('RSYSLOG_ADMIN_PASSWORD') ?: 'admin123';

// Microsoft Entra ID (Azure AD) OIDC — optional
$azure_ad_enabled = getenv('RSYSLOG_AZURE_ENABLED');
$azure_ad_enabled = ($azure_ad_enabled === false || $azure_ad_enabled === null) ? false : ($azure_ad_enabled === '1' || strtolower((string) $azure_ad_enabled) === 'true');
$azure_ad_tenant_id = getenv('RSYSLOG_AZURE_TENANT_ID') ?: '';
$azure_ad_client_id = getenv('RSYSLOG_AZURE_CLIENT_ID') ?: '';
$azure_ad_client_secret = getenv('RSYSLOG_AZURE_CLIENT_SECRET') ?: '';
$azure_ad_admin_group_id = getenv('RSYSLOG_AZURE_ADMIN_GROUP_ID') ?: '';

// Microsoft Graph — mailbox UPN/object ID that sends MFA codes (application Mail.Send)
$graph_mail_sender = getenv('RSYSLOG_GRAPH_MAIL_SENDER') ?: '';
$https_whitelist_enabled = getenv('RSYSLOG_HTTPS_WHITELIST_ENABLED');
$https_whitelist_enabled = ($https_whitelist_enabled === false || $https_whitelist_enabled === null)
	? false
	: ($https_whitelist_enabled === '1' || strtolower((string) $https_whitelist_enabled) === 'true');
$https_whitelist_cidrs = getenv('RSYSLOG_HTTPS_WHITELIST_CIDRS') ?: '';
