<?php

declare(strict_types=1);

use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

error_reporting(E_ALL & ~E_DEPRECATED);

require __DIR__ . '/../app/env.php';
load_env(__DIR__ . '/../.env');

require __DIR__ . '/../vendor/autoload.php';

$isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] === '443');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'httponly' => true,
    'secure' => $isSecure,
    'samesite' => 'Lax',
]);
session_start();

$app = AppFactory::create();

$twig = Twig::create(__DIR__ . '/../templates', [
    'cache' => false,
]);
$basePath = $_SERVER['SCRIPT_NAME'] ?? '';
$basePath = str_replace('/index.php', '', $basePath);
$basePath = rtrim($basePath, '/');
$app->setBasePath($basePath);
$twig->getEnvironment()->addGlobal('base_path', $basePath);
$app->add(TwigMiddleware::create($app, $twig));

$routes = require __DIR__ . '/../app/routes.php';
$routes($app);

$app->run();
