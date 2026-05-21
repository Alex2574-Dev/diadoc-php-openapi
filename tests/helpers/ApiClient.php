<?php

declare(strict_types=1);

namespace Test\helpers;

use MagDv\Diadoc\DiadocApi;
use MagDv\Diadoc\Signer\OpensslSignerProvider;
use Test\enums\ConfigNames;

class ApiClient
{
    /**
     * Срок кеша legacy ddauth-токена (~11 часов, как в старых версиях SDK).
     */
    private const LEGACY_TOKEN_CACHE_TTL = 39600;

    /**
     * @var DiadocApi|null
     */
    private $api;

    /**
     * @var SimpleFileCache
     */
    private $cache;

    /**
     * @return DiadocApi
     */
    public function getApi()
    {
        $base = dirname(__DIR__);
        $caFile = $base . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . 'domain.crt';
        $certFile = $base . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . 'domain.csr';
        $keyFile = $base . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . 'domain.key';
        $signedProvider = new OpensslSignerProvider($caFile, $certFile, $keyFile);

        if ($this->api === null) {
            $this->cache = Cache::getCache();

            $diadocUrl = getenv(ConfigNames::DIADOC_URL);
            $diadocUrl = ($diadocUrl !== false && $diadocUrl !== '') ? $diadocUrl : 'https://diadoc-api.kontur.ru/';

            $authModeRaw = getenv(ConfigNames::DIADOC_AUTH_MODE);
            $authMode = $authModeRaw !== false && $authModeRaw !== ''
                ? strtolower(trim($authModeRaw))
                : DiadocApi::AUTH_MODE_OIDC;

            if ($authMode === DiadocApi::AUTH_MODE_AUTHENTICATE_V3) {
                $this->api = $this->createLegacyApi($diadocUrl, $signedProvider);
            } else {
                if ($authMode !== DiadocApi::AUTH_MODE_OIDC) {
                    throw new \RuntimeException(
                        'Недопустимый DIADOC_AUTH_MODE="' . $authMode . '". '
                        . 'Допустимо: ' . DiadocApi::AUTH_MODE_OIDC . ' или ' . DiadocApi::AUTH_MODE_AUTHENTICATE_V3 . '.'
                    );
                }
                $this->api = $this->createOidcApi($diadocUrl, $signedProvider);
            }
        }

        if ($this->api->getAuthMode() === DiadocApi::AUTH_MODE_AUTHENTICATE_V3) {
            $this->restoreOrLoadLegacySession();
        } else {
            $this->restoreOrLoadOAuthSession();
        }

        return $this->api;
    }

    /**
     * @param mixed $signedProvider
     *
     * @return DiadocApi
     */
    private function createOidcApi(string $diadocUrl, $signedProvider)
    {
        $clientId = getenv(ConfigNames::OAUTH_CLIENT_ID);
        $clientSecret = getenv(ConfigNames::OAUTH_CLIENT_SECRET);
        if ($clientId === false || $clientId === '' || $clientSecret === false || $clientSecret === '') {
            throw new \RuntimeException(
                'Режим OIDC (DIADOC_AUTH_MODE=oidc или не задан): в .env нужны OAUTH_CLIENT_ID и OAUTH_CLIENT_SECRET.'
            );
        }

        $identityUrl = getenv(ConfigNames::OAUTH_IDENTITY_URL);
        $identityUrl = $identityUrl !== false && $identityUrl !== '' ? $identityUrl : 'https://identity.kontur.ru';

        $api = new DiadocApi(
            $clientId,
            $clientSecret,
            $diadocUrl,
            $identityUrl,
            false,
            $signedProvider
        );
        $api->setAuthMode(DiadocApi::AUTH_MODE_OIDC);

        $self = $this;
        $api->setOAuthSessionPersistenceCallback(static function (array $session) use ($self) {
            $cacheExp = time() + 86400 * 30;
            if (!empty($session['expires_at'])) {
                $cacheExp = max($cacheExp, (int) $session['expires_at'] + 7200);
            }
            $self->cache->set(
                ConfigNames::DIADOC_OAUTH_CACHE_KEY,
                json_encode($session, JSON_UNESCAPED_UNICODE),
                $cacheExp
            );
        });

        return $api;
    }

    /**
     * @param mixed $signedProvider
     *
     * @return DiadocApi
     */
    private function createLegacyApi(string $diadocUrl, $signedProvider)
    {
        $ddAuth = getenv(ConfigNames::DD_AUTH);
        if ($ddAuth === false || $ddAuth === '') {
            throw new \RuntimeException(
                'Режим authenticate_v3: в .env должен быть задан DD_AUTH (ddauth_api_client_id).'
            );
        }

        $identityUrl = getenv(ConfigNames::OAUTH_IDENTITY_URL);
        $identityUrl = $identityUrl !== false && $identityUrl !== '' ? $identityUrl : 'https://identity.kontur.ru';

        $api = new DiadocApi(
            $ddAuth,
            '',
            $diadocUrl,
            $identityUrl,
            false,
            $signedProvider
        );
        $api->setAuthMode(DiadocApi::AUTH_MODE_AUTHENTICATE_V3);

        return $api;
    }

    private function restoreOrLoadLegacySession(): void
    {
        $tokenFromEnv = getenv(ConfigNames::DIADOC_LEGACY_TOKEN);
        if ($tokenFromEnv !== false && $tokenFromEnv !== '') {
            $this->api->setLegacyToken($tokenFromEnv);

            return;
        }

        $cached = $this->cache->get(ConfigNames::DIADOC_LEGACY_CACHE_KEY);
        if ($cached !== false && is_string($cached) && $cached !== '') {
            $this->api->setLegacyToken($cached);

            return;
        }

        $login = getenv(ConfigNames::AUTH_LOGIN);
        $password = getenv(ConfigNames::AUTH_PASSWORD);
        if ($login === false || $login === '' || $password === false || $password === '') {
            throw new \RuntimeException(
                'Режим authenticate_v3: задайте DIADOC_LEGACY_TOKEN в .env, '
                . 'либо положите токен в кеш (ключ ' . ConfigNames::DIADOC_LEGACY_CACHE_KEY . '), '
                . 'либо укажите AUTH_LOGIN и AUTH_PASSWORD для вызова /V3/Authenticate.'
            );
        }

        try {
            $token = $this->api->authenticateLoginV3($login, $password);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'authenticate_v3: не удалось получить ddauth_token через /V3/Authenticate: ' . $e->getMessage(),
                0,
                $e
            );
        }

        if ($token !== '') {
            $this->cache->set(
                ConfigNames::DIADOC_LEGACY_CACHE_KEY,
                $token,
                time() + self::LEGACY_TOKEN_CACHE_TTL
            );
        }
    }

    private function restoreOrLoadOAuthSession(): void
    {
        $sourceRaw = getenv(ConfigNames::OAUTH_TOKEN_SOURCE);
        $source = $sourceRaw !== false && $sourceRaw !== '' ? strtolower(trim($sourceRaw)) : 'env_first';
        $ignoreCacheRaw = getenv(ConfigNames::OAUTH_IGNORE_CACHE);
        $ignoreCache = $ignoreCacheRaw !== false && in_array(strtolower(trim((string) $ignoreCacheRaw)), ['1', 'true', 'yes', 'on'], true);

        if ($source === 'env' || $source === 'env_first') {
            if ($this->loadOAuthSessionFromEnv()) {
                return;
            }
            if ($source === 'env') {
                throw new \RuntimeException(
                    'OAUTH_TOKEN_SOURCE=env, но DIADOC_ACCESS_TOKEN не задан в .env.'
                );
            }
        }

        if (!$ignoreCache && ($source === 'cache' || $source === 'cache_first' || $source === 'env_first')) {
            if ($this->loadOAuthSessionFromCache()) {
                return;
            }
            if ($source === 'cache') {
                throw new \RuntimeException(
                    'OAUTH_TOKEN_SOURCE=cache, но в кеше нет корректной OAuth-сессии (ключ ' . ConfigNames::DIADOC_OAUTH_CACHE_KEY . ').'
                );
            }
        }

        if ($source === 'cache_first') {
            if ($this->loadOAuthSessionFromEnv()) {
                return;
            }
        }

        throw new \RuntimeException(
            'Нет OAuth-сессии: заполните в .env OAUTH_CLIENT_ID / OAUTH_CLIENT_SECRET и '
            . 'либо положите токены в кеш (ключ ' . ConfigNames::DIADOC_OAUTH_CACHE_KEY . '), '
            . 'либо задайте DIADOC_ACCESS_TOKEN (и при необходимости DIADOC_REFRESH_TOKEN, DIADOC_ACCESS_EXPIRES_AT). '
            . 'Первичное получение кода: откройте buildAuthorizationUrl() в браузере и выполните exchangeAuthorizationCode().'
        );
    }

    private function loadOAuthSessionFromCache(): bool
    {
        $cached = $this->cache->get(ConfigNames::DIADOC_OAUTH_CACHE_KEY);
        if ($cached === false || !is_string($cached) || $cached === '') {
            return false;
        }
        $data = json_decode($cached, true);
        if (!is_array($data) || empty($data['access_token'])) {
            return false;
        }

        $this->api->setOAuthSession(
            $data['access_token'],
            isset($data['refresh_token']) ? $data['refresh_token'] : null,
            isset($data['expires_at']) ? (int) $data['expires_at'] : null
        );

        return true;
    }

    private function loadOAuthSessionFromEnv(): bool
    {
        $access = getenv(ConfigNames::DIADOC_ACCESS_TOKEN);
        if ($access === false || $access === '') {
            return false;
        }
        $exp = getenv(ConfigNames::DIADOC_ACCESS_EXPIRES_AT);
        $this->api->setOAuthSession(
            $access,
            getenv(ConfigNames::DIADOC_REFRESH_TOKEN) ?: null,
            $exp !== false && $exp !== '' ? (int) $exp : null
        );

        return true;
    }
}
