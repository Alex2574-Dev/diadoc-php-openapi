<?php

declare(strict_types=1);

use Test\enums\ConfigNames;
use Test\helpers\ApiClient;

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

$envPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env';
if (is_file($envPath)) {
    $params = parse_ini_file($envPath);
    if (is_array($params)) {
        foreach ($params as $name => $value) {
            putenv($name . '=' . $value);
        }
    }
}

function out(string $message): void
{
    echo $message . PHP_EOL;
}

function fail(string $message): void
{
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
}

try {
    $client = new ApiClient();
    $api = $client->getApi();
} catch (\Throwable $e) {
    fail('Не удалось инициализировать ApiClient: ' . $e->getMessage());
}

out('Auth mode: ' . $api->getAuthMode());
out('== Diadoc live org checks ==');

try {
    $organizations = $api->getMyOrganizations();
} catch (\Throwable $e) {
    fail('getMyOrganizations() завершился ошибкой: ' . $e->getMessage());
}

$orgItems = $organizations->getOrganizations();
$orgCount = $orgItems !== null ? $orgItems->count() : 0;
out('Organizations count: ' . $orgCount);

$orgId = null;
$orgIdFromEnv = getenv(ConfigNames::ORG_ID);
$orgIdFromEnv = is_string($orgIdFromEnv) ? trim($orgIdFromEnv) : '';

if ($orgCount === 0) {
    out('WARN: GetMyOrganizations вернул пустой список.');
    out('      Для логин/пароля (authenticate_v3) без КЭП API часто не отдаёт организации,');
    out('      хотя запросы по boxId (как V3/GetDocuments в Postman) работают.');

    if ($orgIdFromEnv !== '') {
        out('      Продолжаем с ORG_ID из .env: ' . $orgIdFromEnv);
        $orgId = $orgIdFromEnv;
    } else {
        $boxId = getenv(ConfigNames::FROM_BOX_ID);
        $boxId = is_string($boxId) ? trim($boxId) : '';
        if ($boxId !== '') {
            try {
                $api->getBox($boxId);
                out('getBox(FROM_BOX_ID): OK — токен и ящик доступны.');
            } catch (\Throwable $e) {
                fail('getBox(FROM_BOX_ID) завершился ошибкой: ' . $e->getMessage());
            }
        }
        fail(
            'GetMyOrganizations пустой: задайте ORG_ID в .env '
            . '(OrgId организации, не путать с boxId для GetDocuments).'
        );
    }
} else {
    out('Available organizations:');
    foreach ($orgItems as $org) {
        $name = method_exists($org, 'getFullName') ? (string) $org->getFullName() : '';
        $inn = method_exists($org, 'getInn') ? (string) $org->getInn() : '';
        $listedOrgId = method_exists($org, 'getOrgId') ? (string) $org->getOrgId() : '';
        out('- ' . $listedOrgId . ' | ' . $inn . ' | ' . $name);
    }

    if ($orgIdFromEnv !== '') {
        $orgId = $orgIdFromEnv;
        out('ORG_ID из .env: ' . $orgId);
    } else {
        $first = null;
        foreach ($orgItems as $org) {
            $first = $org;
            break;
        }
        if ($first && method_exists($first, 'getOrgId')) {
            $orgId = (string) $first->getOrgId();
            out('ORG_ID не задан, используется первый доступный orgId: ' . $orgId);
        } else {
            fail('ORG_ID не задан и не удалось взять первый orgId.');
        }
    }
}

out('Selected ORG_ID: ' . $orgId);

try {
    $permissions = $api->getMyPermissions($orgId);
    out('getMyPermissions(): OK');
    if (method_exists($permissions, 'serializeToString')) {
        out('Permissions payload bytes: ' . strlen((string) $permissions->serializeToString()));
    }
} catch (\Throwable $e) {
    out('getMyPermissions(): ERROR: ' . $e->getMessage());
}

try {
    $counteragents = $api->getCountragentsV2($orgId);
    out('getCountragentsV2(): OK');
    out('Counteragents totalCount: ' . $counteragents->getTotalCount());
} catch (\Throwable $e) {
    out('getCountragentsV2(): ERROR: ' . $e->getMessage());
}

out('Done.');

