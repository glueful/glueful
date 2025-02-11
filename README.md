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

1. **Flexible**: Token-only validation
2. **Moderate**: Token + IP validation
3. **Strict**: Token + IP + User Agent validation

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

## Contributing

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add some amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## License

This project is licensed under the MIT License - see the LICENSE file for details.
