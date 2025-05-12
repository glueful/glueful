#!/bin/bash

# Run PHPUnit tests with MAMP PHP path
# Usage: ./run-tests.sh [phpunit arguments]
#   Example: ./run-tests.sh --testsuite Unit --filter Auth

# Path to MAMP PHP
PHP_PATH="/Applications/MAMP/bin/php/php8.2.0/bin/php"

# Run PHPUnit with provided arguments
$PHP_PATH vendor/bin/phpunit "$@"
