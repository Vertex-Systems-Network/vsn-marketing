<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Modules\Identity\Application\Federation\ConsumePinnedOidcToken;
use App\Modules\Identity\Application\Sessions\WorkspaceSessionPolicy;
use App\Modules\Identity\Domain\Identity\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;

/** A single configured workspace and pinned provider. Disabled unless explicitly configured. */
final readonly class EnterpriseOidcController
{
    public function __construct(
        private ConsumePinnedOidcToken $assertions,
        private WorkspaceSessionPolicy $sessions,
    ) {}

    public function start(Request $request, string $workspace): RedirectResponse
    {
        $config = $this->configuration($workspace);
        $state = $this->random();
        $nonce = $this->random();
        $verifier = $this->random();
        $request->session()->put('enterprise_oidc', [
            'state' => $state, 'nonce' => $nonce, 'verifier' => $verifier,
            'workspace' => $workspace, 'created_at' => time(),
        ]);

        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $query = http_build_query([
            'response_type' => 'code', 'client_id' => $config['client_id'],
            'redirect_uri' => $config['redirect_uri'], 'scope' => 'openid',
            'state' => $state, 'nonce' => $nonce,
            'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);

        $redirect = redirect()->away($config['issuer'].'authorize?'.$query);
        $redirect->headers->set('Cache-Control', 'no-store');
        $redirect->headers->set('Referrer-Policy', 'no-referrer');

        return $redirect;
    }

    public function callback(Request $request, string $workspace): RedirectResponse
    {
        $config = $this->configuration($workspace);
        // Consume before any validation or network request, including IdP errors.
        $transaction = $request->session()->pull('enterprise_oidc');
        if (! is_array($transaction) || ($transaction['workspace'] ?? null) !== $workspace ||
            ! is_int($transaction['created_at'] ?? null) || time() - $transaction['created_at'] > 300 ||
            $transaction['created_at'] > time() || ! is_string($transaction['state'] ?? null) ||
            ! is_string($transaction['nonce'] ?? null) || ! is_string($transaction['verifier'] ?? null) ||
            ! is_string($request->query('state')) ||
            ! hash_equals($transaction['state'], $request->query('state')) ||
            ! is_string($request->query('code')) || strlen($request->query('code')) > 2048 ||
            $request->query('code') === '' || $request->url() !== $config['redirect_uri']) {
            throw new AuthorizationException('Federation login denied.');
        }

        try {
            $response = Http::acceptJson()->asForm()->timeout(5)
                ->withOptions(['allow_redirects' => false])
                ->post($config['issuer'].'oauth/token', [
                    'grant_type' => 'authorization_code', 'client_id' => $config['client_id'],
                    'client_secret' => $config['client_secret'], 'code' => $request->query('code'),
                    'code_verifier' => $transaction['verifier'], 'redirect_uri' => $config['redirect_uri'],
                ]);
        } catch (ConnectionException) {
            throw new AuthorizationException('Federation login denied.');
        }
        $token = $response->successful() ? $response->json('id_token') : null;
        if (! is_string($token)) {
            throw new AuthorizationException('Federation login denied.');
        }

        $userId = $this->assertions->execute($token, $workspace, $config['workspace_id'],
            $config['issuer'], $config['client_id'], $transaction['nonce'],
            $config['public_key_pem'], time(), $config['key_id']);
        $user = User::query()->findOrFail($userId);
        Auth::guard('web')->login($user, false);
        $request->session()->regenerate();
        $request->session()->put('identity_session_generation', $this->sessions->issue($userId));

        $redirect = redirect()->route('workspaces.directory');
        $redirect->headers->set('Cache-Control', 'no-store');

        return $redirect;
    }

    /** @return array{workspace_id: string, issuer: string, client_id: string, client_secret: string, redirect_uri: string, key_id: string, public_key_pem: string} */
    private function configuration(string $workspace): array
    {
        $config = config('enterprise.oidc');
        if (! is_array($config) || ($config['enabled'] ?? false) !== true ||
            ! is_string($config['workspace_id'] ?? null) || $config['workspace_id'] === '' ||
            ! hash_equals($config['workspace_id'], $workspace) ||
            ! is_string($config['issuer'] ?? null) ||
            ! preg_match('~^https://[a-z0-9.-]+/$~D', $config['issuer']) ||
            ! is_string($config['client_id'] ?? null) || $config['client_id'] === '' ||
            ! is_string($config['client_secret'] ?? null) || $config['client_secret'] === '' ||
            ! is_string($config['key_id'] ?? null) || $config['key_id'] === '' ||
            ! is_string($config['public_key_pem'] ?? null) || $config['public_key_pem'] === '' ||
            ! is_string($config['redirect_uri'] ?? null) ||
            ! str_starts_with($config['redirect_uri'], 'https://') ||
            $config['redirect_uri'] !== route('enterprise.oidc.callback', ['workspace' => $workspace])) {
            throw new AuthorizationException('Federation login is unavailable.');
        }

        return $config;
    }

    private function random(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
