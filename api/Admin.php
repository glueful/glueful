<?php
declare(strict_types=1);

namespace Glueful;

use Glueful\Http\{Response,Router};
use Glueful\Identity\Auth;
use Glueful\Database\Schema\SchemaManager;
use Glueful\Database\{QueryBuilder,Connection};
use Glueful\Helpers\Utils;


class Admin{
    private QueryBuilder $db;
    private SchemaManager $schema;
    private Router $router;

    public function __construct(){
        $connection = new Connection();

        // Initialize database connection
        $this->db = new QueryBuilder($connection->getPDO(), $connection->getDriver());
        // Initialize schema manager
        $this->schema = $connection->getSchemaManager();
        // Initialize router
        $this->router = Router::getInstance();
    }

    public function initializeRoutes():void{}


}