<?php
declare(strict_types=1);

use App\Domain\Auth\RefreshTokenRepository;
use App\Domain\User\UserRepository;
use App\Infrastructure\Persistence\Auth\PdoRefreshTokenRepository;
use App\Infrastructure\Persistence\User\PdoUserRepository;
use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;

return function (ContainerBuilder $containerBuilder) {
    $containerBuilder->addDefinitions([

        UserRepository::class => function (ContainerInterface $c) {
            return new PdoUserRepository($c->get(PDO::class));
        },

        RefreshTokenRepository::class => function (ContainerInterface $c) {
            return new PdoRefreshTokenRepository($c->get(PDO::class));
        },

    ]);
};