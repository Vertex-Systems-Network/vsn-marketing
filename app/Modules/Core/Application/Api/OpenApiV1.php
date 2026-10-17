<?php

namespace App\Modules\Core\Application\Api;

/**
 * Source of truth for the /api/v1 OpenAPI 3.1 document (SRS FR-API-01).
 * Every route named api.v1.* must be described here; a feature test enforces it.
 */
final class OpenApiV1
{
    /** @return array<string, mixed> */
    public static function document(): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'VSN Marketing API',
                'version' => '1.0.0',
                'description' => 'Product API v1. See docs/product/API-V1.md for conventions.',
            ],
            'servers' => [['url' => '/api/v1']],
            'security' => [['bearer' => []], ['cookie' => []]],
            'paths' => self::paths(),
            'components' => [
                'securitySchemes' => [
                    'bearer' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'Personal access token (mobile).'],
                    'cookie' => ['type' => 'apiKey', 'in' => 'cookie', 'name' => 'vsn_session', 'description' => 'Sanctum SPA session (web).'],
                ],
                'parameters' => [
                    'WorkspaceId' => ['name' => 'X-Workspace-Id', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']],
                    'BrandId' => ['name' => 'X-Brand-Id', 'in' => 'header', 'required' => false, 'schema' => ['type' => 'string', 'format' => 'uuid']],
                ],
                'schemas' => self::schemas(),
                'responses' => [
                    'Problem' => ['description' => 'Error', 'content' => ['application/problem+json' => ['schema' => ['$ref' => '#/components/schemas/Problem']]]],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function paths(): array
    {
        $tenant = [['$ref' => '#/components/parameters/WorkspaceId'], ['$ref' => '#/components/parameters/BrandId']];

        $list = fn (string $id, string $tag, string $schema): array => ['get' => [
            'operationId' => $id,
            'tags' => [$tag],
            'parameters' => array_merge($tenant, self::pageParameters()),
            'responses' => self::responses(200, $schema, [401, 403, 422]),
        ]];

        return [
            '/lists' => $list('listContactLists', 'audience', 'NamedItemList'),
            '/tags' => $list('listTags', 'audience', 'NamedItemList'),
            '/segments' => $list('listSegments', 'audience', 'StatusItemList'),
            '/campaigns' => $list('listCampaigns', 'email', 'StatusItemList'),
            '/permissions' => [
                'get' => [
                    'operationId' => 'listPermissions',
                    'tags' => ['identity'],
                    'responses' => self::responses(200, 'PermissionList', [401]),
                ],
            ],
            '/roles' => [
                'get' => [
                    'operationId' => 'listRoles',
                    'tags' => ['identity'],
                    'parameters' => $tenant,
                    'responses' => self::responses(200, 'RoleList', [401, 403]),
                ],
            ],
            '/auth/tokens' => [
                'post' => [
                    'operationId' => 'createAccessToken',
                    'tags' => ['auth'],
                    'security' => [],
                    'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/TokenRequest']]]],
                    'responses' => self::responses(201, 'Token', [422, 429]),
                ],
            ],
            '/auth/tokens/current' => [
                'delete' => [
                    'operationId' => 'revokeCurrentAccessToken',
                    'tags' => ['auth'],
                    'responses' => ['204' => ['description' => 'Revoked'], '401' => ['$ref' => '#/components/responses/Problem']],
                ],
            ],
            '/me' => [
                'get' => [
                    'operationId' => 'getMe',
                    'tags' => ['identity'],
                    'responses' => self::responses(200, 'MeResponse', [401]),
                ],
            ],
            '/me/workspaces' => [
                'get' => [
                    'operationId' => 'listMyWorkspaces',
                    'tags' => ['identity'],
                    'responses' => self::responses(200, 'WorkspaceList', [401]),
                ],
            ],
            '/contacts' => [
                'get' => [
                    'operationId' => 'listContacts',
                    'tags' => ['audience'],
                    'parameters' => array_merge($tenant, self::pageParameters()),
                    'responses' => self::responses(200, 'ContactList', [401, 403, 422]),
                ],
            ],
            '/contacts/{contact}' => [
                'get' => [
                    'operationId' => 'getContact',
                    'tags' => ['audience'],
                    'parameters' => array_merge($tenant, [
                        ['name' => 'contact', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']],
                    ]),
                    'responses' => self::responses(200, 'ContactResponse', [401, 403, 404]),
                ],
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function pageParameters(): array
    {
        return [
            ['name' => 'page[size]', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]],
            ['name' => 'page[cursor]', 'in' => 'query', 'schema' => ['type' => 'string']],
            ['name' => 'filter[search]', 'in' => 'query', 'schema' => ['type' => 'string', 'maxLength' => 120]],
        ];
    }

    /**
     * @param  list<int>  $errors
     * @return array<int|string, mixed>
     */
    private static function responses(int $status, string $schema, array $errors): array
    {
        $out = [(string) $status => [
            'description' => 'OK',
            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/'.$schema]]],
        ]];
        foreach ($errors as $error) {
            $out[(string) $error] = ['$ref' => '#/components/responses/Problem'];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private static function schemas(): array
    {
        $nullableString = ['type' => ['string', 'null']];

        return [
            'Problem' => [
                'type' => 'object',
                'required' => ['type', 'title', 'status', 'code'],
                'properties' => [
                    'type' => ['type' => 'string'],
                    'title' => ['type' => 'string'],
                    'status' => ['type' => 'integer'],
                    'code' => ['type' => 'string'],
                    'request_id' => ['type' => 'string'],
                    'errors' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]],
                ],
            ],
            'TokenRequest' => [
                'type' => 'object',
                'required' => ['email', 'password', 'device_name'],
                'properties' => [
                    'email' => ['type' => 'string', 'format' => 'email'],
                    'password' => ['type' => 'string'],
                    'device_name' => ['type' => 'string', 'maxLength' => 120],
                ],
            ],
            'Token' => [
                'type' => 'object',
                'required' => ['token', 'token_type'],
                'properties' => [
                    'token' => ['type' => 'string'],
                    'token_type' => ['type' => 'string', 'const' => 'Bearer'],
                    'expires_at' => $nullableString,
                ],
            ],
            'Me' => [
                'type' => 'object',
                'required' => ['id', 'name', 'email', 'email_verified'],
                'properties' => [
                    'id' => ['type' => 'string'],
                    'name' => ['type' => 'string'],
                    'email' => ['type' => 'string'],
                    'email_verified' => ['type' => 'boolean'],
                ],
            ],
            'MeResponse' => ['type' => 'object', 'required' => ['data'], 'properties' => ['data' => ['$ref' => '#/components/schemas/Me']]],
            'Workspace' => [
                'type' => 'object',
                'required' => ['id', 'name', 'slug', 'permissions'],
                'properties' => [
                    'id' => ['type' => 'string'],
                    'name' => ['type' => 'string'],
                    'slug' => ['type' => 'string'],
                    'permissions' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
            ],
            'WorkspaceList' => ['type' => 'object', 'required' => ['data'], 'properties' => ['data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Workspace']]]],
            'Contact' => [
                'type' => 'object',
                'required' => ['id'],
                'properties' => [
                    'id' => ['type' => 'string'],
                    'brand_id' => $nullableString,
                    'company_id' => $nullableString,
                    'first_name' => $nullableString,
                    'last_name' => $nullableString,
                    'display_name' => $nullableString,
                    'created_at' => $nullableString,
                    'updated_at' => $nullableString,
                ],
            ],
            'NamedItem' => [
                'type' => 'object',
                'required' => ['id', 'name'],
                'properties' => ['id' => ['type' => 'string'], 'name' => ['type' => 'string'], 'created_at' => $nullableString, 'updated_at' => $nullableString],
            ],
            'NamedItemList' => self::page('NamedItem'),
            'StatusItem' => [
                'type' => 'object',
                'required' => ['id', 'name', 'status'],
                'properties' => ['id' => ['type' => 'string'], 'name' => ['type' => 'string'], 'status' => ['type' => 'string'], 'created_at' => $nullableString, 'updated_at' => $nullableString],
            ],
            'StatusItemList' => self::page('StatusItem'),
            'PermissionList' => ['type' => 'object', 'required' => ['data'], 'properties' => ['data' => ['type' => 'array', 'items' => ['type' => 'string']]]],
            'Role' => [
                'type' => 'object',
                'required' => ['id', 'key', 'name', 'permissions'],
                'properties' => ['id' => ['type' => 'string'], 'key' => ['type' => 'string'], 'name' => ['type' => 'string'], 'permissions' => ['type' => 'array', 'items' => ['type' => 'string']]],
            ],
            'RoleList' => ['type' => 'object', 'required' => ['data'], 'properties' => ['data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Role']]]],
            'ContactResponse' => ['type' => 'object', 'required' => ['data'], 'properties' => ['data' => ['$ref' => '#/components/schemas/Contact']]],
            'ContactList' => self::page('Contact'),
        ];
    }

    /** @return array<string, mixed> */
    private static function page(string $item): array
    {
        return [
            'type' => 'object',
            'required' => ['data', 'meta'],
            'properties' => [
                'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/'.$item]],
                'meta' => ['type' => 'object', 'properties' => ['next_cursor' => ['type' => ['string', 'null']]]],
            ],
        ];
    }
}
