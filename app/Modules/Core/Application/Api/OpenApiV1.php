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
                'post' => [
                    'operationId' => 'createRole',
                    'tags' => ['identity'],
                    'parameters' => $tenant,
                    'requestBody' => self::json('RoleRequest'),
                    'responses' => self::responses(201, 'IdResponse', [401, 403, 422]),
                ],
            ],
            '/members' => [
                'get' => [
                    'operationId' => 'listMembers',
                    'tags' => ['identity'],
                    'parameters' => $tenant,
                    'responses' => self::responses(200, 'MemberList', [401, 403]),
                ],
            ],
            '/invites' => [
                'get' => [
                    'operationId' => 'listInvites',
                    'tags' => ['identity'],
                    'parameters' => $tenant,
                    'responses' => self::responses(200, 'InviteList', [401, 403]),
                ],
                'post' => [
                    'operationId' => 'createInvite',
                    'tags' => ['identity'],
                    'parameters' => $tenant,
                    'requestBody' => self::json('InviteRequest'),
                    'responses' => self::responses(201, 'InviteCreated', [401, 403, 422]),
                ],
            ],
            '/invites/{invite}' => [
                'delete' => [
                    'operationId' => 'revokeInvite',
                    'tags' => ['identity'],
                    'parameters' => array_merge($tenant, [['name' => 'invite', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']]]),
                    'responses' => ['204' => ['description' => 'Revoked'], '401' => ['$ref' => '#/components/responses/Problem'], '403' => ['$ref' => '#/components/responses/Problem']],
                ],
            ],
            '/invites/{token}/accept' => [
                'post' => [
                    'operationId' => 'acceptInvite',
                    'tags' => ['identity'],
                    'parameters' => [['name' => 'token', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]],
                    'responses' => self::responses(200, 'AcceptedInvite', [401, 403, 429]),
                ],
            ],
            '/auth/register' => [
                'post' => [
                    'operationId' => 'register',
                    'tags' => ['auth'],
                    'security' => [],
                    'requestBody' => self::json('RegisterRequest'),
                    'responses' => self::responses(201, 'RegisteredToken', [422, 429]),
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

    /** @return array<string, mixed> */
    private static function json(string $schema): array
    {
        return ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/'.$schema]]]];
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
                    'is_platform_admin' => ['type' => 'boolean'],
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
            'IdResponse' => ['type' => 'object', 'required' => ['data'], 'properties' => ['data' => ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'string']]]]],
            'RoleRequest' => [
                'type' => 'object',
                'required' => ['key', 'name', 'permissions'],
                'properties' => ['key' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]*$'], 'name' => ['type' => 'string'], 'permissions' => ['type' => 'array', 'items' => ['type' => 'string']]],
            ],
            'Member' => [
                'type' => 'object',
                'required' => ['id', 'name', 'email', 'roles'],
                'properties' => ['id' => ['type' => 'string'], 'name' => ['type' => 'string'], 'email' => ['type' => 'string'], 'roles' => ['type' => 'array', 'items' => ['type' => 'string']]],
            ],
            'MemberList' => ['type' => 'object', 'required' => ['data'], 'properties' => ['data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Member']]]],
            'Invite' => [
                'type' => 'object',
                'required' => ['id', 'email', 'role', 'expires_at'],
                'properties' => ['id' => ['type' => 'string'], 'email' => ['type' => 'string'], 'role' => ['type' => 'string'], 'expires_at' => ['type' => 'string']],
            ],
            'InviteList' => ['type' => 'object', 'required' => ['data'], 'properties' => ['data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Invite']]]],
            'InviteRequest' => ['type' => 'object', 'required' => ['email', 'role_id'], 'properties' => ['email' => ['type' => 'string', 'format' => 'email'], 'role_id' => ['type' => 'string', 'format' => 'uuid']]],
            'InviteCreated' => [
                'type' => 'object',
                'required' => ['data'],
                'properties' => ['data' => ['type' => 'object', 'required' => ['id', 'token', 'expires_at'], 'properties' => ['id' => ['type' => 'string'], 'token' => ['type' => 'string'], 'expires_at' => ['type' => 'string']]]],
            ],
            'AcceptedInvite' => ['type' => 'object', 'required' => ['data'], 'properties' => ['data' => ['type' => 'object', 'required' => ['workspace_id'], 'properties' => ['workspace_id' => ['type' => 'string']]]]],
            'RegisterRequest' => [
                'type' => 'object',
                'required' => ['name', 'email', 'password', 'workspace_name'],
                'properties' => ['name' => ['type' => 'string'], 'email' => ['type' => 'string', 'format' => 'email'], 'password' => ['type' => 'string', 'minLength' => 12], 'workspace_name' => ['type' => 'string'], 'device_name' => ['type' => 'string']],
            ],
            'RegisteredToken' => [
                'type' => 'object',
                'required' => ['token', 'token_type', 'workspace_id'],
                'properties' => ['token' => ['type' => 'string'], 'token_type' => ['type' => 'string', 'const' => 'Bearer'], 'expires_at' => ['type' => ['string', 'null']], 'workspace_id' => ['type' => 'string']],
            ],
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
