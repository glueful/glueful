# MAPI - Modern API Framework

A modern PHP API framework with JWT authentication, and role-based access control.

## Features

- JWT-based authentication
- Role-based access control (RBAC)
- JSON-based API definitions
- File upload and blob management
- Security levels (Flexible, Moderate, Strict)
- Environment configuration
- PDO support
- Caching support (Redis, File, None)

## Installation

1. Clone the repository:
```bash
git clone https://github.com/yourusername/mapi.git
cd mapi
```

2. Copy and configure environment file:
```bash
cp .env.example .env
# Edit .env with your settings
```

3. Set up database:
```bash
# Create database
createdb mapi_dev

# Import schema
psql mapi_dev < database/init/init.sql
```

4. Configure web server:
```apache
<VirtualHost *:80>
    ServerName api.yourdomain.com
    DocumentRoot /path/to/mapi/public
    
    <Directory /path/to/mapi/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

## Environment Configuration

Configure the following in your `.env` file:

```bash
# Database
DB_CONNECTION=primary
DB_HOST=localhost
DB_PORT=5432
DB_DATABASE=mapi_dev
DB_USERNAME=your_username
DB_PASSWORD=your_password

# Security
DEFAULT_SECURITY_LEVEL=2
ENABLE_PERMISSIONS=true

# Cache
CACHE_DRIVER=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

## Database Schema

The system includes the following core tables:

- `users`: User management
- `roles`: Role definitions
- `permissions`: Permission settings
- `user_roles_lookup`: User-role relationships
- `blobs`: File storage management

## Making API Requests

```php
// Authentication
POST /api/auth/login
{
    "username": "admin@example.com",
    "password": "your_password"
}

// List users
GET /api/users?token=your-jwt-token

// Create user
POST /api/users
{
    "token": "your-jwt-token",
    "username": "john_doe",
    "email": "john@example.com",
    "password": "secure_password"
}
```

## JSON API Definitions

API endpoints are defined using JSON definition files in `api/api-json-definitions`:

```json
{
    "table": {
        "name": "users",
        "fields": [
            {
                "name": "id",
                "api_field": "id",
                "type": "bigint(20)",
                "nullable": false
            }
        ]
    },
    "access": {
        "mode": "rw"
    }
}
```

## Security Levels

1. **Flexible (Level 1)**
   - Basic JWT token validation
   - Suitable for development

2. **Moderate (Level 2)**
   - JWT token validation
   - IP address validation
   - Rate limiting
   - Recommended for production

3. **Strict (Level 3)**
   - All Moderate features
   - User agent validation
   - Request encryption
   - Required for sensitive data

## Storage Options

The framework supports multiple storage drivers for handling file uploads and blob storage:

1. **Local Storage**
   - Files stored on local filesystem
   - Configure path in `.env`: `STORAGE_PATH=/storage/app`
   - Best for single-server deployments
   - Default option

2. **S3-Compatible Storage**
   - Amazon S3 or compatible services
   - Scalable cloud storage solution
   - Configure with:
     ```
     STORAGE_DRIVER=s3
     S3_KEY=your_key
     S3_SECRET=your_secret
     S3_BUCKET=your_bucket
     S3_REGION=your_region
     ```

3. **FTP Storage**
   - Remote FTP server storage
   - Configure with:
     ```
     STORAGE_DRIVER=ftp
     FTP_HOST=ftp.example.com
     FTP_USERNAME=your_username
     FTP_PASSWORD=your_password
     FTP_ROOT=/path
     ```

File upload settings:
- Maximum upload size: `MAX_UPLOAD_SIZE` in bytes
- Allowed file types defined in `api/config/upload.php`
- Automatic image resizing and thumbnail generation
- File deduplication using content hashing

## Directory Structure

```
mapi/
├── api/
│   ├── api-extensions/        # API extensions
│   ├── api-library/          # Core library files
│   ├── api-json-definitions/ # JSON API definitions
│   └── _config.php           # Main configuration
├── database/
│   ├── init/                 # Database initialization
│   │   ├── tables/          # Table definitions
│   │   ├── functions/       # Database functions
│   │   └── seed/            # Initial data
│   └── migrations/          # Database migrations
├── public/                   # Public directory
├── logs/                     # Log files
├── storage/                  # File storage
└── .env                     # Environment configuration
```

## API Documentation
- Auto-generated OpenAPI/Swagger documentation
- Available at /docs/swagger.json

## Troubleshooting

Common issues and solutions:

1. **Database Connection Failed**
   - Verify database credentials in `.env`
   - Ensure database service is running
   - Check network connectivity

2. **Invalid JWT Token**
   - Token may be expired
   - Verify token signing key
   - Check clock sync between services

3. **Permission Denied**
   - Verify user roles
   - Check permission configuration
   - Ensure proper token scope

## Development Guidelines

1. **Code Style**
   - Follow PSR-12 standards
   - Use type hints
   - Document all methods

2. **API Endpoints**
   - Use versioning (v1, v2)
   - Follow REST principles
   - Include validation rules

3. **Security**
   - Sanitize all inputs
   - Use prepared statements
   - Implement rate limiting

## Contributing

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add some amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## License

This project is licensed under the MIT License - see the LICENSE file for details.
