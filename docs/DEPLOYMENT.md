# Deployment Guide

This comprehensive guide covers deploying Glueful applications across various environments and platforms.

## Table of Contents

1. [Pre-Deployment Checklist](#pre-deployment-checklist)
2. [Environment Configuration](#environment-configuration)
3. [Docker Deployment](#docker-deployment)
4. [Cloud Platform Deployment](#cloud-platform-deployment)
5. [Traditional Server Deployment](#traditional-server-deployment)
6. [Load Balancer Configuration](#load-balancer-configuration)
7. [Database Migration Strategies](#database-migration-strategies)
8. [Zero-Downtime Deployment](#zero-downtime-deployment)
9. [Monitoring and Health Checks](#monitoring-and-health-checks)
10. [Rollback Procedures](#rollback-procedures)

## Pre-Deployment Checklist

### ✅ Essential Pre-Deployment Tasks

- [ ] **Security hardening complete** (see [SECURITY.md](SECURITY.md))
- [ ] **Environment variables configured**
- [ ] **Database migrations tested**
- [ ] **SSL certificates obtained**
- [ ] **DNS records configured**
- [ ] **Backup strategy implemented**
- [ ] **Monitoring tools configured**
- [ ] **Load testing completed**
- [ ] **Rollback plan documented**

### Code Preparation

```bash
# Run final tests
composer test

# Security scan
php glueful security:scan

# Optimize for production
composer install --no-dev --optimize-autoloader

# Generate optimized configuration
php glueful config:cache

# Clear development caches
php glueful cache:clear
```

## Environment Configuration

### Production Environment Variables

```env
# Application
APP_ENV=production
APP_DEBUG=false
API_DOCS_ENABLED=false

# Security
FORCE_HTTPS=true
CORS_ALLOWED_ORIGINS=https://yourdomain.com
DEFAULT_SECURITY_LEVEL=5

# Performance
CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis

# Logging
LOG_LEVEL=error
LOG_TO_DB=true
ENABLE_AUDIT=true

# Rate Limiting
IP_RATE_LIMIT_MAX=30
USER_RATE_LIMIT_MAX=500
ENDPOINT_RATE_LIMIT_MAX=15
```

### Staging Environment

```env
# Application
APP_ENV=staging
APP_DEBUG=false
API_DOCS_ENABLED=true

# Security (relaxed for testing)
FORCE_HTTPS=true
DEFAULT_SECURITY_LEVEL=3

# Logging (more verbose for debugging)
LOG_LEVEL=warning
LOG_TO_DB=true
ENABLE_AUDIT=true
```

## Docker Deployment

### Dockerfile

```dockerfile
# Multi-stage build for optimized production image
FROM php:8.2-fpm-alpine AS base

# Install system dependencies
RUN apk add --no-cache \
    git \
    zip \
    unzip \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    mysql-client \
    nginx \
    supervisor

# Install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        gd \
        pdo \
        pdo_mysql \
        opcache \
        bcmath

# Install Redis extension
RUN pecl install redis && docker-php-ext-enable redis

# Production build stage
FROM base AS production

# Set working directory
WORKDIR /var/www/html

# Copy composer files first for better cache utilization
COPY composer.json composer.lock ./

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader --no-scripts

# Copy application code
COPY . .

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 775 /var/www/html/storage

# Copy configuration files
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/php.ini /usr/local/etc/php/php.ini
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Expose port
EXPOSE 80

# Start supervisor
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
```

### docker-compose.yml

```yaml
version: '3.8'

services:
  app:
    build:
      context: .
      target: production
    ports:
      - "80:80"
      - "443:443"
    environment:
      - APP_ENV=production
    volumes:
      - ./storage:/var/www/html/storage
      - ./logs:/var/www/html/storage/logs
    depends_on:
      - database
      - redis
    networks:
      - glueful-network

  database:
    image: mysql:8.0
    environment:
      MYSQL_ROOT_PASSWORD: ${DB_ROOT_PASSWORD}
      MYSQL_DATABASE: ${DB_DATABASE}
      MYSQL_USER: ${DB_USER}
      MYSQL_PASSWORD: ${DB_PASSWORD}
    volumes:
      - mysql_data:/var/lib/mysql
      - ./docker/mysql/init.sql:/docker-entrypoint-initdb.d/init.sql
    ports:
      - "3306:3306"
    networks:
      - glueful-network

  redis:
    image: redis:7-alpine
    command: redis-server --appendonly yes --requirepass ${REDIS_PASSWORD}
    volumes:
      - redis_data:/data
    ports:
      - "6379:6379"
    networks:
      - glueful-network

  nginx:
    image: nginx:alpine
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./docker/nginx/nginx.conf:/etc/nginx/nginx.conf
      - ./docker/nginx/ssl:/etc/ssl/certs
    depends_on:
      - app
    networks:
      - glueful-network

volumes:
  mysql_data:
  redis_data:

networks:
  glueful-network:
    driver: bridge
```

### Docker Deployment Commands

```bash
# Build and deploy
docker-compose up --build -d

# Run migrations
docker-compose exec app php glueful migrate run

# Monitor logs
docker-compose logs -f app

# Scale application
docker-compose up --scale app=3 -d
```

## Cloud Platform Deployment

### AWS Deployment

#### Using AWS ECS

```yaml
# task-definition.json
{
  "family": "glueful-app",
  "networkMode": "awsvpc",
  "requiresCompatibilities": ["FARGATE"],
  "cpu": "512",
  "memory": "1024",
  "executionRoleArn": "arn:aws:iam::account:role/ecsTaskExecutionRole",
  "taskRoleArn": "arn:aws:iam::account:role/ecsTaskRole",
  "containerDefinitions": [
    {
      "name": "glueful-app",
      "image": "your-account.dkr.ecr.region.amazonaws.com/glueful:latest",
      "portMappings": [
        {
          "containerPort": 80,
          "protocol": "tcp"
        }
      ],
      "environment": [
        {
          "name": "APP_ENV",
          "value": "production"
        }
      ],
      "secrets": [
        {
          "name": "DB_PASSWORD",
          "valueFrom": "arn:aws:secretsmanager:region:account:secret:glueful/db-password"
        }
      ],
      "logConfiguration": {
        "logDriver": "awslogs",
        "options": {
          "awslogs-group": "/ecs/glueful-app",
          "awslogs-region": "us-east-1",
          "awslogs-stream-prefix": "ecs"
        }
      }
    }
  ]
}
```

#### AWS RDS Configuration

```bash
# Create RDS instance
aws rds create-db-instance \
  --db-instance-identifier glueful-production \
  --db-instance-class db.t3.medium \
  --engine mysql \
  --engine-version 8.0.35 \
  --master-username admin \
  --master-user-password $(aws secretsmanager get-secret-value --secret-id prod/glueful/db --query SecretString --output text | jq -r .password) \
  --allocated-storage 100 \
  --storage-type gp2 \
  --storage-encrypted \
  --vpc-security-group-ids sg-xxxxxxxxx \
  --db-subnet-group-name glueful-db-subnet-group \
  --backup-retention-period 7 \
  --multi-az
```

### Google Cloud Platform (GCP)

#### Using Cloud Run

```yaml
# cloudrun.yaml
apiVersion: serving.knative.dev/v1
kind: Service
metadata:
  name: glueful-app
  annotations:
    run.googleapis.com/ingress: all
spec:
  template:
    metadata:
      annotations:
        autoscaling.knative.dev/maxScale: "10"
        run.googleapis.com/cpu-throttling: "false"
        run.googleapis.com/memory: "1Gi"
        run.googleapis.com/cpu: "1"
    spec:
      containerConcurrency: 80
      containers:
      - image: gcr.io/PROJECT_ID/glueful:latest
        ports:
        - containerPort: 80
        env:
        - name: APP_ENV
          value: production
        - name: DB_PASSWORD
          valueFrom:
            secretKeyRef:
              name: glueful-db-password
              key: password
        resources:
          limits:
            memory: "1Gi"
            cpu: "1"
```

#### Deploy to Cloud Run

```bash
# Build and push image
gcloud builds submit --tag gcr.io/PROJECT_ID/glueful

# Deploy to Cloud Run
gcloud run deploy glueful-app \
  --image gcr.io/PROJECT_ID/glueful \
  --platform managed \
  --region us-central1 \
  --allow-unauthenticated \
  --memory 1Gi \
  --cpu 1 \
  --max-instances 10
```

### Azure Deployment

#### Using Azure Container Instances

```yaml
# azure-deployment.yaml
apiVersion: '2019-12-01'
location: eastus
name: glueful-app
properties:
  containers:
  - name: glueful-app
    properties:
      image: your-registry.azurecr.io/glueful:latest
      resources:
        requests:
          cpu: 1
          memoryInGb: 1
      ports:
      - port: 80
        protocol: TCP
      environmentVariables:
      - name: APP_ENV
        value: production
      - name: DB_PASSWORD
        secureValue: "$(DB_PASSWORD)"
  osType: Linux
  ipAddress:
    type: Public
    ports:
    - protocol: tcp
      port: 80
  restartPolicy: Always
```

## Traditional Server Deployment

### Ubuntu/Debian Server Setup

```bash
# Update system
sudo apt update && sudo apt upgrade -y

# Install required packages
sudo apt install -y nginx mysql-server redis-server php8.2-fpm \
  php8.2-mysql php8.2-redis php8.2-mbstring php8.2-xml \
  php8.2-curl php8.2-zip php8.2-bcmath php8.2-json \
  php8.2-opcache certbot python3-certbot-nginx

# Install Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Configure PHP-FPM
sudo nano /etc/php/8.2/fpm/pool.d/glueful.conf
```

#### PHP-FPM Configuration

```ini
[glueful]
user = www-data
group = www-data
listen = /run/php/php8.2-fpm-glueful.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660

pm = dynamic
pm.max_children = 50
pm.start_servers = 5
pm.min_spare_servers = 5
pm.max_spare_servers = 35
pm.max_requests = 1000

php_admin_value[memory_limit] = 512M
php_admin_value[upload_max_filesize] = 50M
php_admin_value[post_max_size] = 50M
php_admin_value[max_execution_time] = 300
```

#### Nginx Configuration

```nginx
server {
    listen 80;
    server_name yourdomain.com www.yourdomain.com;
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    server_name yourdomain.com www.yourdomain.com;
    
    root /var/www/glueful;
    index index.php;
    
    # SSL Configuration
    ssl_certificate /etc/letsencrypt/live/yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/yourdomain.com/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers ECDHE-RSA-AES256-GCM-SHA512:DHE-RSA-AES256-GCM-SHA512;
    
    # Security Headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    
    # Rate Limiting
    limit_req zone=api burst=50 nodelay;
    limit_req_status 429;
    
    # Glueful API routing
    location / {
        try_files $uri $uri/ /api/index.php?$query_string;
    }
    
    # PHP processing
    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm-glueful.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        
        # Security
        fastcgi_hide_header X-Powered-By;
        fastcgi_buffer_size 128k;
        fastcgi_buffers 4 256k;
        fastcgi_busy_buffers_size 256k;
    }
    
    # Deny access to sensitive files
    location ~ /\. {
        deny all;
    }
    
    location ~ composer\.(json|lock)$ {
        deny all;
    }
    
    location ~ \.env$ {
        deny all;
    }
}
```

### Application Deployment Script

```bash
#!/bin/bash
# deploy.sh

set -e

DEPLOY_DIR="/var/www/glueful"
BACKUP_DIR="/var/backups/glueful"
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")

echo "Starting deployment..."

# Create backup
echo "Creating backup..."
mkdir -p $BACKUP_DIR
sudo tar -czf $BACKUP_DIR/backup_$TIMESTAMP.tar.gz -C $DEPLOY_DIR .

# Update code
echo "Updating code..."
cd $DEPLOY_DIR
git pull origin main

# Install dependencies
echo "Installing dependencies..."
composer install --no-dev --optimize-autoloader

# Run migrations
echo "Running database migrations..."
php glueful migrate run --force

# Clear caches
echo "Clearing caches..."
php glueful cache:clear

# Set permissions
echo "Setting permissions..."
sudo chown -R www-data:www-data $DEPLOY_DIR
sudo chmod -R 755 $DEPLOY_DIR
sudo chmod -R 775 $DEPLOY_DIR/storage

# Reload services
echo "Reloading services..."
sudo systemctl reload php8.2-fpm
sudo systemctl reload nginx

# Health check
echo "Performing health check..."
if curl -f http://localhost/health > /dev/null 2>&1; then
    echo "Deployment successful!"
else
    echo "Health check failed! Rolling back..."
    cd $DEPLOY_DIR
    sudo tar -xzf $BACKUP_DIR/backup_$TIMESTAMP.tar.gz
    sudo systemctl reload php8.2-fpm
    exit 1
fi
```

## Load Balancer Configuration

### Nginx Load Balancer

```nginx
upstream glueful_backend {
    server 10.0.1.10:80 weight=3 max_fails=3 fail_timeout=30s;
    server 10.0.1.11:80 weight=3 max_fails=3 fail_timeout=30s;
    server 10.0.1.12:80 weight=2 max_fails=3 fail_timeout=30s backup;
}

server {
    listen 80;
    server_name api.yourdomain.com;
    
    location / {
        proxy_pass http://glueful_backend;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        
        # Health check
        proxy_next_upstream error timeout invalid_header http_500 http_502 http_503 http_504;
        proxy_connect_timeout 5s;
        proxy_send_timeout 10s;
        proxy_read_timeout 10s;
    }
    
    # Health check endpoint
    location /health {
        access_log off;
        proxy_pass http://glueful_backend;
    }
}
```

### AWS Application Load Balancer

```yaml
# alb-target-group.yaml
Resources:
  GluefulTargetGroup:
    Type: AWS::ElasticLoadBalancingV2::TargetGroup
    Properties:
      Name: glueful-targets
      Port: 80
      Protocol: HTTP
      VpcId: !Ref VPC
      HealthCheckPath: /health
      HealthCheckProtocol: HTTP
      HealthCheckIntervalSeconds: 30
      HealthyThresholdCount: 2
      UnhealthyThresholdCount: 3
      TargetType: ip
      
  ApplicationLoadBalancer:
    Type: AWS::ElasticLoadBalancingV2::LoadBalancer
    Properties:
      Name: glueful-alb
      Type: application
      Scheme: internet-facing
      SecurityGroups:
        - !Ref ALBSecurityGroup
      Subnets:
        - !Ref PublicSubnet1
        - !Ref PublicSubnet2
```

## Database Migration Strategies

### Blue-Green Deployment with Database

```bash
#!/bin/bash
# blue-green-deploy.sh

BLUE_ENV="blue"
GREEN_ENV="green"
CURRENT_ENV=$(cat /var/current_env)

if [ "$CURRENT_ENV" = "$BLUE_ENV" ]; then
    NEW_ENV=$GREEN_ENV
else
    NEW_ENV=$BLUE_ENV
fi

echo "Deploying to $NEW_ENV environment"

# Deploy new version to standby environment
deploy_to_environment $NEW_ENV

# Run database migrations
php glueful migrate run --env=$NEW_ENV

# Run smoke tests
run_smoke_tests $NEW_ENV

# Switch traffic
switch_traffic_to $NEW_ENV

# Update current environment marker
echo $NEW_ENV > /var/current_env

echo "Deployment complete. Traffic now on $NEW_ENV"
```

### Rolling Migrations

```bash
#!/bin/bash
# rolling-migration.sh

echo "Starting rolling migration..."

# Step 1: Deploy backward-compatible changes
php glueful migrate run --compatibility-mode

# Step 2: Deploy application updates
deploy_application_update

# Step 3: Complete migration after deployment
php glueful migrate run --complete

echo "Rolling migration complete"
```

## Zero-Downtime Deployment

### Strategy 1: Rolling Updates

```bash
#!/bin/bash
# rolling-update.sh

SERVERS=("server1" "server2" "server3")
HEALTH_CHECK_URL="http://localhost/health"

for server in "${SERVERS[@]}"; do
    echo "Updating $server..."
    
    # Remove from load balancer
    remove_from_lb $server
    
    # Wait for connections to drain
    sleep 30
    
    # Deploy update
    ssh $server "cd /var/www/glueful && git pull && composer install --no-dev"
    
    # Health check
    if curl -f $HEALTH_CHECK_URL; then
        # Add back to load balancer
        add_to_lb $server
        echo "$server updated successfully"
    else
        echo "Health check failed for $server"
        exit 1
    fi
    
    # Wait before next server
    sleep 10
done
```

### Strategy 2: Canary Deployment

```bash
#!/bin/bash
# canary-deploy.sh

echo "Starting canary deployment..."

# Deploy to canary servers (10% of traffic)
deploy_to_canary

# Monitor metrics for 10 minutes
monitor_canary_metrics 600

# If metrics are good, proceed with full deployment
if [ $? -eq 0 ]; then
    echo "Canary metrics look good, proceeding with full deployment"
    deploy_to_all_servers
else
    echo "Canary metrics failed, rolling back"
    rollback_canary
    exit 1
fi
```

## Monitoring and Health Checks

### Application Health Check

```php
// api/Controllers/HealthController.php
public function comprehensive(): array
{
    $checks = [
        'database' => $this->checkDatabase(),
        'redis' => $this->checkRedis(),
        'storage' => $this->checkStorage(),
        'external_apis' => $this->checkExternalAPIs(),
        'memory' => $this->checkMemoryUsage(),
        'disk_space' => $this->checkDiskSpace()
    ];
    
    $allHealthy = !in_array(false, array_column($checks, 'healthy'));
    
    return [
        'status' => $allHealthy ? 'healthy' : 'unhealthy',
        'timestamp' => date('c'),
        'checks' => $checks,
        'version' => config('app.version')
    ];
}
```

### Monitoring Configuration

```yaml
# docker-compose.monitoring.yml
version: '3.8'

services:
  prometheus:
    image: prom/prometheus
    ports:
      - "9090:9090"
    volumes:
      - ./monitoring/prometheus.yml:/etc/prometheus/prometheus.yml
    
  grafana:
    image: grafana/grafana
    ports:
      - "3000:3000"
    environment:
      - GF_SECURITY_ADMIN_PASSWORD=admin
    volumes:
      - grafana_data:/var/lib/grafana
      
  alertmanager:
    image: prom/alertmanager
    ports:
      - "9093:9093"
    volumes:
      - ./monitoring/alertmanager.yml:/etc/alertmanager/alertmanager.yml

volumes:
  grafana_data:
```

## Rollback Procedures

### Automated Rollback Script

```bash
#!/bin/bash
# rollback.sh

ROLLBACK_VERSION=${1:-previous}
DEPLOY_DIR="/var/www/glueful"
BACKUP_DIR="/var/backups/glueful"

echo "Rolling back to version: $ROLLBACK_VERSION"

# Stop application traffic
echo "Removing from load balancer..."
remove_from_load_balancer

# Restore code
echo "Restoring code..."
if [ "$ROLLBACK_VERSION" = "previous" ]; then
    BACKUP_FILE=$(ls -t $BACKUP_DIR/backup_*.tar.gz | head -1)
else
    BACKUP_FILE="$BACKUP_DIR/backup_$ROLLBACK_VERSION.tar.gz"
fi

cd $DEPLOY_DIR
sudo tar -xzf $BACKUP_FILE

# Restore database (if needed)
echo "Checking database rollback requirements..."
if [ -f "database_rollback_required" ]; then
    echo "Rolling back database..."
    php glueful db:rollback --to-version=$ROLLBACK_VERSION
fi

# Clear caches
php glueful cache:clear

# Set permissions
sudo chown -R www-data:www-data $DEPLOY_DIR
sudo chmod -R 755 $DEPLOY_DIR

# Restart services
sudo systemctl restart php8.2-fpm
sudo systemctl restart nginx

# Health check
echo "Performing health check..."
if curl -f http://localhost/health > /dev/null 2>&1; then
    echo "Adding back to load balancer..."
    add_to_load_balancer
    echo "Rollback successful!"
else
    echo "Rollback failed! Manual intervention required."
    exit 1
fi
```

### Database Rollback Strategy

```bash
# Database rollback with point-in-time recovery
restore_database_to_point_in_time() {
    local target_time=$1
    
    echo "Restoring database to $target_time"
    
    # Stop application
    systemctl stop glueful-app
    
    # Restore from backup
    mysql -u root -p glueful < /backups/glueful_backup_before_$target_time.sql
    
    # Apply any necessary data fixes
    php glueful db:fix-data --after-rollback
    
    # Start application
    systemctl start glueful-app
}
```

## Performance Optimization

### Production Optimizations

```bash
# Enable OPcache
echo "opcache.enable=1
opcache.enable_cli=1
opcache.memory_consumption=512
opcache.interned_strings_buffer=64
opcache.max_accelerated_files=32531
opcache.validate_timestamps=0
opcache.save_comments=1
opcache.fast_shutdown=0" >> /etc/php/8.2/fpm/conf.d/10-opcache.ini

# Configure Redis for production
echo "maxmemory 2gb
maxmemory-policy allkeys-lru
save 900 1
save 300 10
save 60 10000" >> /etc/redis/redis.conf
```

### CDN Integration

```bash
# Configure CloudFlare/AWS CloudFront
configure_cdn() {
    echo "Configuring CDN settings..."
    
    # Cache static assets
    cache_static_assets
    
    # Configure cache headers
    set_cache_headers
    
    # Enable compression
    enable_gzip_compression
}
```

---

This deployment guide provides comprehensive coverage for deploying Glueful applications across various environments. Choose the deployment strategy that best fits your infrastructure and requirements.

Remember to test all deployment procedures in a staging environment before applying them to production.