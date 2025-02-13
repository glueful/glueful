# MAPI - Modern API Framework

A modern PHP API framework with built-in support for REST endpoints, JWT authentication, and automatic API documentation generation.

## Features

- REST API support
- JWT-based authentication
- Role-based access control
- Automatic API documentation (Swagger/OpenAPI)
- Database abstraction layer
- File upload handling
- Caching support (Redis/Memcached)
- Audit logging
- Environment-based configuration
- CORS support

## Requirements

- PHP >= 8.0 (Strict typing and return type declarations are used)
- MySQL/PostgreSQL
- Redis or Memcached (optional)
- Composer

## Installation

1. Clone the repository:
```bash
git clone https://github.com/yourusername/mapi.git
cd mapi
```

2. Install dependencies:
```bash
composer install
```

3. Create configuration:
```bash
cp .env.example .env
```

4. Update the .env file with your configuration

## Configuration

The framework uses environment-based configuration. Key configuration files:

- `.env` - Environment variables
- `config/*.php` - Configuration files for different components
- `api/api-json-definitions/` - API endpoint definitions

## Directory Structure

```
mapi/
├── api/                    # API core files
│   ├── api-library/       # Core library classes
│   ├── api-extensions/    # API extensions
│   ├── Http/             # HTTP related classes
│   └── bootstrap.php     # Application bootstrap
├── config/               # Configuration files
├── docs/                 # API documentation
├── vendor/              # Composer dependencies
└── .env                 # Environment configuration
```

## API Usage

### REST Endpoints

The API supports standard REST endpoints:

```
GET    /{resource}          # List resources
GET    /{resource}/{id}     # Get single resource
POST   /{resource}          # Create resource
PUT    /{resource}/{id}     # Update resource
DELETE /{resource}/{id}     # Delete resource
```

### Authentication

```http
POST /auth/login
Content-Type: application/json

{
    "username": "user@example.com",
    "password": "password"
}
```

Use the returned token in subsequent requests:

```http
GET /api/users
Authorization: Bearer {token}
```

## Development

### Generate API Documentation

```bash
php api/JsonGenerator.php
```

### Adding New Endpoints

1. Create JSON definition in `api/api-json-definitions/`
2. Run the JSON generator
3. Access the endpoint via REST API

## Testing

Run PHPUnit tests:

```bash
vendor/bin/phpunit
```

## License

MIT License - see [LICENSE](LICENSE) for details.

## Support

For issues and feature requests, please use the GitHub issue tracker.

## Contributing

1. Fork the repository
2. Create your feature branch
3. Commit your changes
4. Push to the branch
5. Create a Pull Request
