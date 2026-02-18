<?php

declare(strict_types=1);

use Slim\App;
use Slim\Views\Twig;

function register_oauth_routes(App $app): void
{
    $app->get('/oauth/{platform}/start', function ($request, $response, $args) {
        $platform = strtolower((string) ($args['platform'] ?? ''));
        $state = oauth_random_string(24);
        $redirectUri = getenv('ECHOTREE_OAUTH_CALLBACK') ?: 'https://danielpradilla.info/oauth/callback';
        $view = Twig::fromRequest($request);

        if ($platform === 'mastodon') {
            $baseUrl = getenv('ECHOTREE_MASTODON_BASE_URL') ?: '';
            $clientId = getenv('ECHOTREE_MASTODON_CLIENT_ID') ?: '';
            if ($baseUrl === '' || $clientId === '') {
        return $view->render($response, 'oauth/error.twig', [
            'title' => 'Mastodon',
            'message' => 'Missing ECHOTREE_MASTODON_BASE_URL or ECHOTREE_MASTODON_CLIENT_ID.',
            'base_path' => base_path($request),
        ])->withStatus(400);
            }

            oauth_save_state('mastodon', ['state' => $state]);
            $url = rtrim($baseUrl, '/') . '/oauth/authorize'
                . '?response_type=code'
                . '&client_id=' . rawurlencode($clientId)
                . '&redirect_uri=' . rawurlencode($redirectUri)
                . '&scope=' . rawurlencode('read write')
                . '&state=' . rawurlencode($state);

            return $response->withHeader('Location', $url)->withStatus(302);
        }

        if ($platform === 'x') {
            $clientId = getenv('ECHOTREE_X_CLIENT_ID') ?: '';
            if ($clientId === '') {
                return $view->render($response, 'oauth/error.twig', [
                    'title' => 'X',
                    'message' => 'Missing ECHOTREE_X_CLIENT_ID.',
                    'base_path' => base_path($request),
                ])->withStatus(400);
            }

            $codeVerifier = oauth_random_string(64);
            $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
            oauth_save_state('x', [
                'state' => $state,
                'code_verifier' => $codeVerifier,
            ]);

            $scope = 'tweet.write users.read offline.access';
            $url = 'https://twitter.com/i/oauth2/authorize'
                . '?response_type=code'
                . '&client_id=' . rawurlencode($clientId)
                . '&redirect_uri=' . rawurlencode($redirectUri)
                . '&scope=' . rawurlencode($scope)
                . '&state=' . rawurlencode($state)
                . '&code_challenge=' . rawurlencode($codeChallenge)
                . '&code_challenge_method=S256';

            return $response->withHeader('Location', $url)->withStatus(302);
        }

        if ($platform === 'linkedin') {
            $clientId = getenv('ECHOTREE_LINKEDIN_CLIENT_ID') ?: '';
            if ($clientId === '') {
                return $view->render($response, 'oauth/error.twig', [
                    'title' => 'LinkedIn',
                    'message' => 'Missing ECHOTREE_LINKEDIN_CLIENT_ID.',
                    'base_path' => base_path($request),
                ])->withStatus(400);
            }

            oauth_save_state('linkedin', ['state' => $state]);
            $scope = 'r_liteprofile w_member_social';
            $url = 'https://www.linkedin.com/oauth/v2/authorization'
                . '?response_type=code'
                . '&client_id=' . rawurlencode($clientId)
                . '&redirect_uri=' . rawurlencode($redirectUri)
                . '&scope=' . rawurlencode($scope)
                . '&state=' . rawurlencode($state);

            return $response->withHeader('Location', $url)->withStatus(302);
        }

        return $view->render($response, 'oauth/error.twig', [
            'title' => 'OAuth',
            'message' => 'Unknown platform.',
            'base_path' => base_path($request),
        ])->withStatus(404);
    });

    $app->map(['GET', 'POST'], '/oauth/bluesky', function ($request, $response) {
        $error = null;

        if ($request->getMethod() === 'POST') {
            $data = (array) $request->getParsedBody();
            $handle = trim((string) ($data['handle'] ?? ''));
            $password = trim((string) ($data['app_password'] ?? ''));
            $password = str_replace(' ', '', $password);
            $pds = trim((string) ($data['pds'] ?? ''));

            if ($handle === '' || $password === '') {
                $error = 'Handle and app password are required.';
            } else {
                try {
                    $client = new GuzzleHttp\Client(['timeout' => 15]);
                    if ($pds === '') {
                        $resolve = $client->get('https://bsky.social/xrpc/com.atproto.identity.resolveHandle', [
                            'query' => ['handle' => $handle],
                        ]);
                        $resolveData = json_decode((string) $resolve->getBody(), true);
                        $did = (string) ($resolveData['did'] ?? '');
                        if ($did !== '') {
                            $doc = $client->get('https://plc.directory/' . rawurlencode($did));
                            $docData = json_decode((string) $doc->getBody(), true);
                            $services = $docData['service'] ?? [];
                            foreach ($services as $service) {
                                if (($service['type'] ?? '') === 'AtprotoPersonalDataServer') {
                                    $pds = (string) ($service['serviceEndpoint'] ?? '');
                                    break;
                                }
                            }
                        }
                    }

                    if ($pds === '') {
                        $pds = getenv('ECHOTREE_BLUESKY_PDS') ?: 'https://bsky.social';
                    }

                    $resp = $client->post(rtrim($pds, '/') . '/xrpc/com.atproto.server.createSession', [
                        'json' => [
                            'identifier' => $handle,
                            'password' => $password,
                        ],
                    ]);
                    $data = json_decode((string) $resp->getBody(), true);
                    $token = (string) ($data['accessJwt'] ?? '');
                    $refresh = (string) ($data['refreshJwt'] ?? '');
                    $displayName = (string) ($data['handle'] ?? $handle);
                    if ($token === '' || $refresh === '') {
                        $error = 'Failed to create Bluesky session.';
                    } else {
                        $payload = json_encode([
                            'type' => 'bluesky',
                            'access' => $token,
                            'refresh' => $refresh,
                        ]);
                        oauth_upsert_account('bluesky', $displayName, $handle, $payload);
                        return $response->withHeader('Location', url_for($request, '/accounts'))->withStatus(302);
                    }
                } catch (GuzzleHttp\Exception\RequestException $e) {
                    $body = '';
                    if ($e->hasResponse()) {
                        $body = (string) $e->getResponse()->getBody();
                    }
                    $details = $body !== '' ? $body : $e->getMessage();
                    $error = 'Bluesky auth failed (' . $pds . '): ' . $details;
                } catch (GuzzleHttp\Exception\GuzzleException $e) {
                    $error = 'Bluesky auth failed (' . $pds . '): ' . $e->getMessage();
                } catch (Throwable $e) {
                    $error = 'Bluesky auth failed: ' . $e->getMessage();
                }
            }
        }

        $view = Twig::fromRequest($request);
        return $view->render($response, 'oauth/bluesky.twig', [
            'title' => 'Bluesky',
            'csrf' => csrf_token(),
            'error' => $error,
            'pds' => getenv('ECHOTREE_BLUESKY_PDS') ?: 'https://bsky.social',
            'base_path' => base_path($request),
        ]);
    });

    $app->get('/oauth/callback', function ($request, $response) {
        $query = $request->getQueryParams();
        $code = (string) ($query['code'] ?? '');
        $state = (string) ($query['state'] ?? '');
        $redirectUri = getenv('ECHOTREE_OAUTH_CALLBACK') ?: 'https://danielpradilla.info/oauth/callback';
        $view = Twig::fromRequest($request);

        if ($code === '' || $state === '') {
            return $view->render($response, 'oauth/error.twig', [
                'title' => 'OAuth',
                'message' => 'Missing authorization code or state.',
                'base_path' => base_path($request),
            ])->withStatus(400);
        }

        if (oauth_get_state('x') && ($state === oauth_get_state('x')['state'])) {
            $clientId = getenv('ECHOTREE_X_CLIENT_ID') ?: '';
            $clientSecret = getenv('ECHOTREE_X_CLIENT_SECRET') ?: '';
            $verifier = oauth_get_state('x')['code_verifier'] ?? '';
            oauth_clear_state('x');

            if ($clientId === '' || $verifier === '') {
                return $view->render($response, 'oauth/error.twig', [
                    'title' => 'X',
                    'message' => 'Missing client ID or PKCE verifier.',
                    'base_path' => base_path($request),
                ])->withStatus(400);
            }

            $client = new GuzzleHttp\Client(['timeout' => 15]);
            $headers = [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ];
            if ($clientSecret !== '') {
                $basic = base64_encode($clientId . ':' . $clientSecret);
                $headers['Authorization'] = 'Basic ' . $basic;
            }
            $resp = $client->post('https://api.twitter.com/2/oauth2/token', [
                'headers' => $headers,
                'form_params' => [
                    'grant_type' => 'authorization_code',
                    'client_id' => $clientId,
                    'redirect_uri' => $redirectUri,
                    'code' => $code,
                    'code_verifier' => $verifier,
                ],
            ]);
            $data = json_decode((string) $resp->getBody(), true);
            $token = (string) ($data['access_token'] ?? '');
            if ($token === '') {
                return $view->render($response, 'oauth/error.twig', [
                    'title' => 'X',
                    'message' => 'Token exchange failed.',
                    'base_path' => base_path($request),
                ])->withStatus(400);
            }

            $username = '';
            $name = '';
            try {
                $me = $client->get('https://api.twitter.com/2/users/me', [
                    'headers' => ['Authorization' => 'Bearer ' . $token],
                ]);
                $meData = json_decode((string) $me->getBody(), true);
                $username = (string) ($meData['data']['username'] ?? '');
                $name = (string) ($meData['data']['name'] ?? '');
            } catch (Throwable $e) {
                $username = 'x-user';
                $name = 'X account';
            }
            if ($username === '') {
                $username = 'x-user';
            }

            oauth_upsert_account('twitter', $name !== '' ? $name : $username, $username, $token);
            return $response->withHeader('Location', url_for($request, '/accounts'))->withStatus(302);
        }

        if (oauth_get_state('linkedin') && ($state === oauth_get_state('linkedin')['state'])) {
            $clientId = getenv('ECHOTREE_LINKEDIN_CLIENT_ID') ?: '';
            $clientSecret = getenv('ECHOTREE_LINKEDIN_CLIENT_SECRET') ?: '';
            oauth_clear_state('linkedin');

            if ($clientId === '' || $clientSecret === '') {
                return $view->render($response, 'oauth/error.twig', [
                    'title' => 'LinkedIn',
                    'message' => 'Missing client ID or client secret.',
                    'base_path' => base_path($request),
                ])->withStatus(400);
            }

            $client = new GuzzleHttp\Client(['timeout' => 15]);
            $resp = $client->post('https://www.linkedin.com/oauth/v2/accessToken', [
                'form_params' => [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $redirectUri,
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                ],
            ]);
            $data = json_decode((string) $resp->getBody(), true);
            $token = (string) ($data['access_token'] ?? '');
            if ($token === '') {
                return $view->render($response, 'oauth/error.twig', [
                    'title' => 'LinkedIn',
                    'message' => 'Token exchange failed.',
                    'base_path' => base_path($request),
                ])->withStatus(400);
            }

            $me = $client->get('https://api.linkedin.com/v2/me', [
                'headers' => ['Authorization' => 'Bearer ' . $token],
            ]);
            $meData = json_decode((string) $me->getBody(), true);
            $id = (string) ($meData['id'] ?? '');
            $localized = $meData['localizedFirstName'] ?? '';
            $localizedLast = $meData['localizedLastName'] ?? '';
            $display = trim($localized . ' ' . $localizedLast);
            $handle = $id !== '' ? $id : 'linkedin-user';

            oauth_upsert_account('linkedin', $display !== '' ? $display : $handle, $handle, $token);
            return $response->withHeader('Location', url_for($request, '/accounts'))->withStatus(302);
        }

        if (oauth_get_state('mastodon') && ($state === oauth_get_state('mastodon')['state'])) {
            $baseUrl = getenv('ECHOTREE_MASTODON_BASE_URL') ?: '';
            $clientId = getenv('ECHOTREE_MASTODON_CLIENT_ID') ?: '';
            $clientSecret = getenv('ECHOTREE_MASTODON_CLIENT_SECRET') ?: '';
            oauth_clear_state('mastodon');

            if ($baseUrl === '' || $clientId === '' || $clientSecret === '') {
                return $view->render($response, 'oauth/error.twig', [
                    'title' => 'Mastodon',
                    'message' => 'Missing base URL or client credentials.',
                    'base_path' => base_path($request),
                ])->withStatus(400);
            }

            $client = new GuzzleHttp\Client(['timeout' => 15]);
            $resp = $client->post(rtrim($baseUrl, '/') . '/oauth/token', [
                'form_params' => [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $redirectUri,
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'scope' => 'read write',
                ],
            ]);
            $data = json_decode((string) $resp->getBody(), true);
            $token = (string) ($data['access_token'] ?? '');
            if ($token === '') {
                return $view->render($response, 'oauth/error.twig', [
                    'title' => 'Mastodon',
                    'message' => 'Token exchange failed.',
                    'base_path' => base_path($request),
                ])->withStatus(400);
            }

            $me = $client->get(rtrim($baseUrl, '/') . '/api/v1/accounts/verify_credentials', [
                'headers' => ['Authorization' => 'Bearer ' . $token],
            ]);
            $meData = json_decode((string) $me->getBody(), true);
            $handle = (string) ($meData['acct'] ?? 'mastodon-user');
            $display = (string) ($meData['display_name'] ?? $handle);
            oauth_upsert_account('mastodon', $display, $handle, $token);
            return $response->withHeader('Location', url_for($request, '/accounts'))->withStatus(302);
        }

        return $view->render($response, 'oauth/error.twig', [
            'title' => 'OAuth',
            'message' => 'Invalid or expired state.',
            'base_path' => base_path($request),
        ])->withStatus(400);
    });
}
