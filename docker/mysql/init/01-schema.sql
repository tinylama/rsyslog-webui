CREATE DATABASE IF NOT EXISTS rsyslogdb
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE rsyslogdb;

-- Minimal schema required by this Web UI (events.php + events_summary.php + maintenance script).
CREATE TABLE IF NOT EXISTS SystemEvents (
  ID int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ReceivedAt datetime NULL,
  DeviceReportedTime datetime NULL,
  Facility smallint NULL,
  Priority smallint NULL,
  FromHost varchar(63) NULL,
  SysLogTag varchar(60) NULL,
  Messagetype varchar(60) NULL,
  processid int NULL,
  Message text NULL
);

CREATE INDEX idx_priority ON SystemEvents (Priority);
CREATE INDEX idx_facility ON SystemEvents (Facility);
CREATE INDEX idx_fromhost ON SystemEvents (FromHost);

-- Minimal RBAC/auth schema for enforcing login on UI + JSON endpoints.
-- Admin/user seeding is handled at runtime by auth.php using env vars.

CREATE TABLE IF NOT EXISTS Roles (
  ID int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  Name varchar(50) NOT NULL UNIQUE
);

CREATE TABLE IF NOT EXISTS Users (
  ID int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  Username varchar(100) NOT NULL UNIQUE,
  Email varchar(255) NULL DEFAULT NULL,
  PasswordHash varchar(255) NOT NULL,
  ExternalProvider varchar(32) NULL DEFAULT NULL,
  ExternalSubject varchar(36) NULL DEFAULT NULL,
  MfaTotpSecret mediumtext NULL DEFAULT NULL,
  MfaEmailEnabled tinyint(1) NOT NULL DEFAULT 0,
  MfaRequired tinyint(1) NOT NULL DEFAULT 0,
  CreatedAt datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS UserRoles (
  UserID int unsigned NOT NULL,
  RoleID int unsigned NOT NULL,
  PRIMARY KEY (UserID, RoleID),
  CONSTRAINT fk_userroles_user FOREIGN KEY (UserID) REFERENCES Users(ID) ON DELETE CASCADE,
  CONSTRAINT fk_userroles_role FOREIGN KEY (RoleID) REFERENCES Roles(ID) ON DELETE CASCADE
);

CREATE INDEX idx_users_username ON Users (Username);
CREATE UNIQUE INDEX idx_users_external_subject ON Users (ExternalSubject);
CREATE INDEX idx_roles_name ON Roles (Name);

CREATE TABLE IF NOT EXISTS AppSettings (
  SettingKey varchar(100) NOT NULL PRIMARY KEY,
  SettingValue mediumtext NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS UserWebAuthnCredentials (
  ID int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  UserID int unsigned NOT NULL,
  CredentialId varbinary(512) NOT NULL,
  PublicKey mediumtext NOT NULL,
  SignCount bigint unsigned NOT NULL DEFAULT 0,
  FriendlyName varchar(100) NULL,
  CreatedAt datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_webauthn_user FOREIGN KEY (UserID) REFERENCES Users(ID) ON DELETE CASCADE,
  UNIQUE KEY uq_webauthn_cred (CredentialId(255)),
  KEY idx_webauthn_user (UserID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS MfaEmailChallenges (
  ID int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  UserID int unsigned NOT NULL,
  CodeHash varchar(255) NOT NULL,
  ExpiresAt datetime NOT NULL,
  ConsumedAt datetime NULL,
  KEY idx_mfa_email_user_exp (UserID, ExpiresAt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

