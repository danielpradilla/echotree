<?php

declare(strict_types=1);

use Slim\App;
use Slim\Views\Twig;

function register_core_routes(App $app): void
{
    $app->get('/', function ($request, $response) {
        $view = Twig::fromRequest($request);
        return $view->render($response, 'home.twig', [
            'title' => 'EchoTree',
            'csrf' => csrf_token(),
        ]);
    });

    $app->map(['GET', 'POST'], '/login', function ($request, $response) {
        $view = Twig::fromRequest($request);

        if ($request->getMethod() === 'POST') {
            $data = (array) $request->getParsedBody();
            $username = trim((string) ($data['username'] ?? ''));
            $password = (string) ($data['password'] ?? '');

            if ($username !== '' && is_login_throttled($username)) {
                return $view->render($response, 'login.twig', [
                    'title' => 'Login',
                    'error' => 'Too many failed attempts. Please wait and try again.',
                    'csrf' => csrf_token(),
                ]);
            }

            $user = authenticate($username, $password);
            if ($user) {
                $_SESSION['user_id'] = $user['id'];
                return $response
                    ->withHeader('Location', url_for($request, '/'))
                    ->withStatus(302);
            }

            return $view->render($response, 'login.twig', [
                'title' => 'Login',
                'error' => 'Invalid username or password.',
                'csrf' => csrf_token(),
            ]);
        }

        return $view->render($response, 'login.twig', [
            'title' => 'Login',
            'csrf' => csrf_token(),
        ]);
    });

    $app->get('/logout', function ($request, $response) {
        session_destroy();
        return $response
            ->withHeader('Location', url_for($request, '/login'))
            ->withStatus(302);
    });
}
