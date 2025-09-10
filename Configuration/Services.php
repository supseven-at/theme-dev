<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Supseven\ThemeDev\Utility\LipsumGenerator;
use Symfony\Component\DependencyInjection\ContainerBuilder;

return static function (ContainerConfigurator $container, ContainerBuilder $containerBuilder): void {
    $services = $container->services();
    $services->defaults()->private()->autowire()->autoconfigure();

    $services->load('Supseven\\ThemeDev\\Command\\', __DIR__ . '/../Classes/Command/*');
    $services->load('Supseven\\ThemeDev\\ViewHelpers\\', __DIR__ . '/../Classes/ViewHelpers/*');

    $services->set(LipsumGenerator::class)->share()->public();
};
