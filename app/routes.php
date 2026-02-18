<?php

declare(strict_types=1);

use Slim\App;

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/posts.php';
require_once __DIR__ . '/summaries.php';
require_once __DIR__ . '/oauth.php';
require_once __DIR__ . '/comments.php';
require_once __DIR__ . '/feed_fetcher.php';
require_once __DIR__ . '/article_extractor.php';
require_once __DIR__ . '/repositories.php';

require_once __DIR__ . '/routes/helpers.php';
require_once __DIR__ . '/routes/core_routes.php';
require_once __DIR__ . '/routes/feed_routes.php';
require_once __DIR__ . '/routes/account_routes.php';
require_once __DIR__ . '/routes/article_routes.php';
require_once __DIR__ . '/routes/oauth_routes.php';

return function (App $app): void {
    $app->add('require_login');
    $app->add('require_csrf');

    register_core_routes($app);
    register_feed_routes($app);
    register_account_routes($app);
    register_article_routes($app);
    register_oauth_routes($app);
};
