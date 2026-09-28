CREATE TABLE IF NOT EXISTS mcp_tokens (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  label VARCHAR(120) NOT NULL,
  token_prefix VARCHAR(24) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  scopes_json TEXT NOT NULL,
  created_by INT UNSIGNED NOT NULL DEFAULT 0,
  last_used_at DATETIME NULL,
  expires_at DATETIME NULL,
  revoked_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY mcp_tokens_hash (token_hash),
  KEY mcp_tokens_tenant_active (tenant_id, revoked_at, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mcp_action_log (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  token_id INT UNSIGNED NOT NULL,
  action VARCHAR(160) NOT NULL,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY mcp_action_log_token_time (token_id, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
