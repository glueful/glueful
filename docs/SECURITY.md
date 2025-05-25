# Security Hardening Guide

This guide provides comprehensive security hardening steps for Glueful applications in production environments.

## Table of Contents

1. [Pre-Production Security Checklist](#pre-production-security-checklist)
2. [Authentication & Authorization](#authentication--authorization)
3. [Database Security](#database-security)
4. [API Security](#api-security)
5. [Infrastructure Security](#infrastructure-security)
6. [Monitoring & Auditing](#monitoring--auditing)
7. [Security Headers](#security-headers)
8. [Regular Maintenance](#regular-maintenance)

## Pre-Production Security Checklist

### ✅ Essential Security Tasks

- [ ] **Generate secure encryption keys**
  ```bash
  php glueful key:generate
  ```

- [ ] **Set production environment**
  ```env
  APP_ENV=production
  APP_DEBUG=false
  ```

- [ ] **Configure secure database credentials**
  - Use strong, unique passwords (minimum 16 characters)
  - Create dedicated database user with minimal privileges
  - Enable SSL for database connections

- [ ] **Enable HTTPS enforcement**
  ```env
  FORCE_HTTPS=true
  ```

- [ ] **Configure CORS properly**
  ```env
  # Replace * with specific domains
  CORS_ALLOWED_ORIGINS=https://yourdomain.com,https://www.yourdomain.com
  ```

- [ ] **Set production rate limits**
  ```env
  IP_RATE_LIMIT_MAX=30
  USER_RATE_LIMIT_MAX=500
  ENDPOINT_RATE_LIMIT_MAX=15
  ```

- [ ] **Configure secure logging**
  ```env
  LOG_LEVEL=error
  LOG_TO_DB=true
  ENABLE_AUDIT=true
  ```

## Authentication & Authorization

### JWT Security

1. **Generate Strong JWT Keys**
   ```bash
   # Generate a secure 256-bit key
   openssl rand -base64 32
   ```

2. **Configure JWT Settings**
   ```env
   JWT_KEY=your-generated-256-bit-key
   JWT_ALGORITHM=HS256
   ACCESS_TOKEN_LIFETIME=900    # 15 minutes
   REFRESH_TOKEN_LIFETIME=604800 # 7 days
   ```

3. **Token Rotation Strategy**
   - Implement automatic token refresh before expiration
   - Revoke tokens on logout
   - Implement token blacklisting for compromised tokens

### Password Security

1. **Password Requirements**
   - Minimum 12 characters
   - Mix of uppercase, lowercase, numbers, and symbols
   - No common dictionary words
   - No personal information

2. **Account Security**
   ```env
   # Enable account lockout after failed attempts
   MAX_LOGIN_ATTEMPTS=5
   LOCKOUT_DURATION=1800  # 30 minutes
   ```

### Role-Based Access Control (RBAC)

1. **Principle of Least Privilege**
   - Grant minimum necessary permissions
   - Regular permission audits
   - Remove unused roles and permissions

2. **Admin Account Security**
   ```env
   # Require stronger authentication for admin endpoints
   ADMIN_REQUIRE_2FA=true
   ADMIN_SESSION_TIMEOUT=1800  # 30 minutes
   ```

## Database Security

### Connection Security

1. **Database User Privileges**
   ```sql
   -- Create dedicated application user
   CREATE USER 'glueful_app'@'%' IDENTIFIED BY 'very_strong_password';
   
   -- Grant only necessary privileges
   GRANT SELECT, INSERT, UPDATE, DELETE ON glueful.* TO 'glueful_app'@'%';
   
   -- No DDL privileges for application user
   -- Use separate user for migrations
   ```

2. **SSL Encryption**
   ```env
   DB_SSL_MODE=REQUIRED
   DB_SSL_CERT=/path/to/client-cert.pem
   DB_SSL_KEY=/path/to/client-key.pem
   DB_SSL_CA=/path/to/ca-cert.pem
   ```

### Data Protection

1. **Sensitive Data Encryption**
   ```env
   ENABLE_API_FIELD_ENCRYPTION=true
   ENCRYPTION_KEY=your-secure-encryption-key
   ```

2. **Database Backups**
   ```bash
   # Encrypt backups
   mysqldump --single-transaction glueful | gpg --encrypt --recipient admin@company.com > backup.sql.gpg
   ```

## API Security

### Input Validation

1. **Request Size Limits**
   ```env
   MAX_REQUEST_SIZE=10MB
   MAX_UPLOAD_SIZE=10485760
   ```

2. **Content Type Validation**
   ```env
   REQUIRE_CONTENT_TYPE=true
   ALLOWED_CONTENT_TYPES=application/json,multipart/form-data
   ```

### Rate Limiting

1. **Multi-Layer Rate Limiting**
   ```env
   # Global API rate limiting
   GLOBAL_RATE_LIMIT=1000  # requests per hour
   
   # Per-IP rate limiting
   IP_RATE_LIMIT_MAX=30    # requests per minute
   
   # Per-user rate limiting
   USER_RATE_LIMIT_MAX=500 # requests per hour
   
   # Per-endpoint rate limiting
   ENDPOINT_RATE_LIMIT_MAX=15 # requests per minute
   ```

2. **Adaptive Rate Limiting**
   ```env
   ENABLE_ADAPTIVE_RATE_LIMITING=true
   RATE_LIMIT_INCREASE_FACTOR=1.5  # Increase limits for failed attempts
   ```

### API Key Management

1. **API Key Security**
   ```env
   API_KEY_LENGTH=32
   API_KEY_ROTATION_DAYS=90
   ```

2. **Key Rotation Process**
   ```bash
   # Generate new API keys regularly
   php glueful api:key:rotate --notify-users
   ```

## Infrastructure Security

### Web Server Configuration

1. **Nginx Security Headers**
   ```nginx
   # Add to your nginx.conf
   add_header X-Frame-Options "SAMEORIGIN" always;
   add_header X-Content-Type-Options "nosniff" always;
   add_header X-XSS-Protection "1; mode=block" always;
   add_header Referrer-Policy "strict-origin-when-cross-origin" always;
   add_header Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'" always;
   add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
   ```

2. **Apache Security Headers**
   ```apache
   # Add to your .htaccess or apache.conf
   Header always set X-Frame-Options "SAMEORIGIN"
   Header always set X-Content-Type-Options "nosniff"
   Header always set X-XSS-Protection "1; mode=block"
   Header always set Referrer-Policy "strict-origin-when-cross-origin"
   Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
   ```

### File System Security

1. **Directory Permissions**
   ```bash
   # Set restrictive permissions
   chmod -R 755 /path/to/glueful
   chmod -R 644 /path/to/glueful/storage
   chmod -R 600 /path/to/glueful/.env
   
   # Storage directories should be writable by web server
   chown -R www-data:www-data /path/to/glueful/storage
   ```

2. **File Upload Security**
   ```env
   # Restrict file types
   ALLOWED_FILE_TYPES=jpg,jpeg,png,pdf,doc,docx
   SCAN_UPLOADED_FILES=true
   QUARANTINE_SUSPICIOUS_FILES=true
   ```

## Monitoring & Auditing

### Audit Logging

1. **Enable Comprehensive Auditing**
   ```env
   ENABLE_AUDIT=true
   AUDIT_ALL_REQUESTS=true
   AUDIT_FAILED_LOGINS=true
   AUDIT_PERMISSION_CHANGES=true
   AUDIT_DATA_CHANGES=true
   ```

2. **Log Analysis**
   ```bash
   # Monitor failed login attempts
   php glueful audit:failed-logins --last-24h
   
   # Check permission changes
   php glueful audit:permissions --since="2024-01-01"
   ```

### Security Monitoring

1. **Real-time Alerts**
   ```env
   SECURITY_ALERT_EMAIL=security@company.com
   ALERT_ON_MULTIPLE_FAILED_LOGINS=true
   ALERT_ON_PRIVILEGE_ESCALATION=true
   ALERT_ON_SUSPICIOUS_ACTIVITY=true
   ```

2. **Monitoring Dashboards**
   - Failed authentication attempts
   - Rate limit violations
   - Unusual API usage patterns
   - Database connection failures

### Log Management

1. **Secure Log Storage**
   ```env
   LOG_ENCRYPTION=true
   LOG_ROTATION_DAYS=90
   LOG_ARCHIVE_LOCATION=s3://security-logs-bucket
   ```

2. **Log Analysis Tools**
   ```bash
   # Analyze security events
   php glueful logs:security-analysis --output=json
   
   # Generate security reports
   php glueful security:report --format=pdf --email=admin@company.com
   ```

## Security Headers

### Content Security Policy (CSP)

1. **Strict CSP Configuration**
   ```env
   CSP_HEADER=default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self'; connect-src 'self'; media-src 'self'; object-src 'none'; child-src 'none'; worker-src 'none'; frame-ancestors 'none'; form-action 'self'; base-uri 'self'
   ```

2. **CSP Reporting**
   ```env
   CSP_REPORT_URI=/api/v1/security/csp-report
   CSP_REPORT_ONLY=false
   ```

### HTTP Strict Transport Security (HSTS)

1. **HSTS Configuration**
   ```env
   HSTS_HEADER=max-age=31536000; includeSubDomains; preload
   HSTS_PRELOAD=true
   ```

## Regular Maintenance

### Security Updates

1. **Monthly Security Checklist**
   - [ ] Update Glueful framework
   - [ ] Update PHP version
   - [ ] Update database server
   - [ ] Update web server
   - [ ] Review and rotate API keys
   - [ ] Audit user permissions
   - [ ] Review security logs
   - [ ] Test backup restoration

2. **Quarterly Security Reviews**
   - [ ] Penetration testing
   - [ ] Security code review
   - [ ] Infrastructure assessment
   - [ ] Update security documentation
   - [ ] Security training for team

### Vulnerability Management

1. **Automated Vulnerability Scanning**
   ```bash
   # Run security scan
   php glueful security:scan
   
   # Check for known vulnerabilities
   php glueful security:check-vulnerabilities
   ```

2. **Dependency Management**
   ```bash
   # Update dependencies regularly
   composer audit
   composer update --with-dependencies
   ```

### Backup and Recovery

1. **Secure Backup Strategy**
   ```bash
   # Automated encrypted backups
   php glueful backup:create --encrypt --verify
   
   # Test backup restoration monthly
   php glueful backup:test-restore --latest
   ```

2. **Disaster Recovery Plan**
   - Document recovery procedures
   - Test recovery processes regularly
   - Maintain offline backup copies
   - Define RTO and RPO requirements

## Security Incident Response

### Incident Detection

1. **Automated Alerts**
   ```env
   ENABLE_INTRUSION_DETECTION=true
   ALERT_ON_BRUTE_FORCE=true
   ALERT_ON_SQL_INJECTION_ATTEMPTS=true
   ALERT_ON_XSS_ATTEMPTS=true
   ```

### Response Procedures

1. **Immediate Response**
   - Isolate affected systems
   - Preserve evidence
   - Notify security team
   - Document incident timeline

2. **Recovery Actions**
   ```bash
   # Emergency lockdown
   php glueful security:lockdown --reason="security incident"
   
   # Revoke all tokens
   php glueful auth:revoke-all-tokens
   
   # Force password reset
   php glueful users:force-password-reset --all
   ```

## Security Testing

### Automated Security Testing

1. **Security Test Suite**
   ```bash
   # Run security tests
   php glueful test:security
   
   # SQL injection tests
   php glueful test:sql-injection
   
   # XSS tests
   php glueful test:xss
   
   # Authentication bypass tests
   php glueful test:auth-bypass
   ```

### Manual Security Testing

1. **Regular Penetration Testing**
   - External penetration testing (quarterly)
   - Internal security assessments (monthly)
   - Code security reviews (per release)

## Compliance Considerations

### Data Protection Regulations

1. **GDPR Compliance**
   ```env
   ENABLE_GDPR_MODE=true
   DATA_RETENTION_DAYS=365
   ENABLE_RIGHT_TO_ERASURE=true
   ```

2. **Data Handling**
   - Implement data anonymization
   - Provide data export functionality
   - Maintain data processing records

### Industry Standards

1. **Security Frameworks**
   - OWASP Top 10 compliance
   - ISO 27001 guidelines
   - NIST Cybersecurity Framework

## Additional Resources

- [OWASP API Security Top 10](https://owasp.org/www-project-api-security/)
- [PHP Security Best Practices](https://www.php.net/manual/en/security.php)
- [MySQL Security Guidelines](https://dev.mysql.com/doc/refman/8.0/en/security-guidelines.html)
- [Nginx Security](https://nginx.org/en/docs/http/ngx_http_secure_link_module.html)

---

**Remember**: Security is an ongoing process, not a one-time setup. Regularly review and update your security measures to address new threats and vulnerabilities.