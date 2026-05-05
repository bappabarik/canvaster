<?php
declare(strict_types=1);

use App\Application\Middleware\RateLimitMiddleware;
use Slim\App;

return function (App $app) {
    $app->addBodyParsingMiddleware();
    $app->add(RateLimitMiddleware::class);
};