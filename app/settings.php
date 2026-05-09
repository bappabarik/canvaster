<?php

declare(strict_types=1);

use App\Application\Settings\Settings;
use App\Application\Settings\SettingsInterface;
use DI\ContainerBuilder;
use Monolog\Logger;

return function (ContainerBuilder $containerBuilder) {
    $containerBuilder->addDefinitions([
        SettingsInterface::class => function () {
            return new Settings([
                'displayErrorDetails' => ($_ENV['APP_DEBUG'] ?? 'false') === 'true',
                'logError'            => true,
                'logErrorDetails'     => true,
                'logger' => [
                    'name'  => 'bdp-api',
                    'path'  => isset($_ENV['docker'])
                        ? 'php://stdout'
                        : __DIR__ . '/../logs/app.log',
                    'level' => Logger::DEBUG,
                ],
                'db' => [
                    'host' => $_ENV['DB_HOST'],
                    'port' => $_ENV['DB_PORT'] ?? '3306',
                    'name' => $_ENV['DB_NAME'] ?? 'bdp',
                    'user' => $_ENV['DB_USER'] ?? 'root',
                    'pass' => $_ENV['DB_PASS'] ?? '',
                ],
                'jwt' => [
                    'secret'      => $_ENV['JWT_SECRET'] ?? '',
                    'access_ttl'  => (int)($_ENV['JWT_ACCESS_TTL']  ?? 900),
                    'refresh_ttl' => (int)($_ENV['JWT_REFRESH_TTL'] ?? 2592000),
                ],
                'cloudinary' => [
                    'cloud_name' => $_ENV['CLOUDINARY_CLOUD_NAME'] ?? '',
                    'api_key'    => $_ENV['CLOUDINARY_API_KEY']    ?? '',
                    'api_secret' => $_ENV['CLOUDINARY_API_SECRET'] ?? '',
                ],
                'rate_limit' => [
                    'guest_per_minute' => 60,
                    'user_per_minute'  => 300,
                    'auth_per_minute'  => 10,  // login/register brute-force protection
                ],
                'razorpay' => [
                    'key_id'         => $_ENV['RAZORPAY_KEY_ID']         ?? '',
                    'key_secret'     => $_ENV['RAZORPAY_KEY_SECRET']     ?? '',
                    'webhook_secret' => $_ENV['RAZORPAY_WEBHOOK_SECRET'] ?? '',
                ],
            ]);
        },
    ]);
};
