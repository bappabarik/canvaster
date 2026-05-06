<?php

declare(strict_types=1);

use App\Domain\Auth\RefreshTokenRepository;
use App\Domain\User\UserRepository;
use App\Infrastructure\Persistence\Auth\PdoRefreshTokenRepository;
use App\Infrastructure\Persistence\User\PdoUserRepository;
use App\Domain\Template\TemplateRepository;
use App\Infrastructure\Persistence\Template\PdoTemplateRepository;
use App\Domain\Project\ProjectRepository;
use App\Infrastructure\Persistence\Project\PdoProjectRepository;
use App\Domain\Asset\AssetRepository;
use App\Infrastructure\Persistence\Asset\PdoAssetRepository;
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

        TemplateRepository::class => function (ContainerInterface $c) {
            return new PdoTemplateRepository($c->get(PDO::class));
        },

        ProjectRepository::class => function (ContainerInterface $c) {
            return new PdoProjectRepository($c->get(PDO::class));
        },

        AssetRepository::class => function (ContainerInterface $c) {
            return new PdoAssetRepository($c->get(PDO::class));
        },

    ]);
};
