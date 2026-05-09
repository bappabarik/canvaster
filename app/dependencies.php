<?php

declare(strict_types=1);

use App\Application\Services\JwtService;
use App\Application\Settings\SettingsInterface;
use DI\ContainerBuilder;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Processor\UidProcessor;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

return function (ContainerBuilder $containerBuilder) {
    $containerBuilder->addDefinitions([

        LoggerInterface::class => function (ContainerInterface $c) {
            $settings = $c->get(SettingsInterface::class)->get('logger');
            $logger   = new Logger($settings['name']);
            $logger->pushProcessor(new UidProcessor());
            $logger->pushHandler(new StreamHandler($settings['path'], $settings['level']));
            return $logger;
        },

        PDO::class => function (ContainerInterface $c) {
            $db  = $c->get(SettingsInterface::class)->get('db');
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $db['host'],
                $db['port'],
                $db['name']
            );
            return new PDO($dsn, $db['user'], $db['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        },

        JwtService::class => function (ContainerInterface $c) {
            return new JwtService(
                $c->get(SettingsInterface::class)->get('jwt')
            );
        },

        \App\Application\Middleware\RateLimitMiddleware::class => function (ContainerInterface $c) {
            return new \App\Application\Middleware\RateLimitMiddleware(
                $c->get(PDO::class),
                $c->get(SettingsInterface::class),
            );
        },

        \App\Application\Validation\RequestValidator::class => function () {
            return new \App\Application\Validation\RequestValidator();
        },

        \App\Application\Middleware\JwtAuthMiddleware::class => function (ContainerInterface $c) {
            return new \App\Application\Middleware\JwtAuthMiddleware(
                $c->get(\App\Application\Services\JwtService::class),
                $c->get(\App\Domain\User\UserRepository::class),
            );
        },

        \App\Application\Services\PlaceholderExtractor::class => function () {
            return new \App\Application\Services\PlaceholderExtractor();
        },

        \App\Application\Services\CsvParser::class => function () {
            return new \App\Application\Services\CsvParser();
        },

        \App\Application\Services\CloudinaryService::class => function (ContainerInterface $c) {
            return new \App\Application\Services\CloudinaryService(
                $c->get(\App\Application\Settings\SettingsInterface::class)->get('cloudinary')
            );
        },

        \App\Application\Services\RazorpayService::class => function (ContainerInterface $c) {
            return new \App\Application\Services\RazorpayService(
                $c->get(\App\Application\Settings\SettingsInterface::class)->get('razorpay')
            );
        },

    ]);
};
