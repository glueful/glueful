# MAPI - Modern API Framework

MAPI is a modern, secure, and scalable API framework designed for building robust applications.

## Features

- JWT-based authentication
- Role-based access control
- Rate limiting
- Caching support (Redis/Memcached)
- Email integration with SMTP and Brevo
- File storage with local and S3 support
- Search functionality with Meillesearch
- Comprehensive logging system
- Queue management
- Firebase Cloud Messaging support

## Requirements

- PHP 8.1+
- MySQL 5.7+ or PostgreSQL 12+
- Redis (optional)
- Memcached (optional)
- Composer

## Quick Start

1. Clone the repository
```bash
git clone https://github.com/yourusername/mapi.git
cd mapi
```

2. Install dependencies:
```bash
composer install
```

3. Copy the environment file:
```bash
cp .env.example .env
```

4. Configure your environment variables in `.env`

5. Initialize the database:
```bash
php artisan migrate
php artisan db:seed
```

## Configuration

### Key Environment Variables

- `APP_ENV`: Application environment (development/production)
- `APP_URL`: Base URL of your application
- `DB_CONNECTION`: Database connection type
- `CACHE_DRIVER`: Cache driver (redis/memcached)
- `STORAGE_DRIVER`: File storage driver (local/s3)

### Security Configuration

- `JWT_SECRET`: Secret key for JWT tokens
- `API_RATE_LIMIT`: Rate limiting for API requests
- `TOKEN_SALT`: Salt for token generation
- `PUBLIC_TOKEN`: Public API token

## API Documentation

API documentation is available at `/api/docs` when `API_DOCS_ENABLED=true`

## Development

Enable development features by setting:
```
APP_ENV=development
APP_DEBUG=true
API_DEBUG_MODE=true
```

## License

[MIT License](LICENSE)

## Support

For issues and feature requests, please use the GitHub issue tracker.

## Contributing

1. Fork the repository
2. Create your feature branch
3. Commit your changes
4. Push to the branch
5. Create a Pull Request
