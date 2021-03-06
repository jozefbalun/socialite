<?php

namespace Laravel\Socialite\One;

use Illuminate\Http\Request;
use InvalidArgumentException;
use League\OAuth1\Client\Server\Server;
use Mockery\CountValidator\Exception;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Laravel\Socialite\Contracts\Provider as ProviderContract;

abstract class AbstractProvider implements ProviderContract
{
    /**
     * The HTTP request instance.
     *
     * @var Request
     */
    protected $request;

    /**
     * The OAuth server implementation.
     *
     * @var Server
     */
    protected $server;

    /**
     * Create a new provider instance.
     *
     * @param  Request  $request
     * @param  Server  $server
     * @return void
     */
    public function __construct(Request $request, Server $server)
    {
        $this->server = $server;
        $this->request = $request;
    }

    /**
     * Redirect the user to the authentication page for the provider.
     *
     * @return RedirectResponse
     */
    public function redirect()
    {
        if (!$this->isStateless()) {
            $this->request->getSession()->set(
                'oauth.temp', $temp = $this->server->getTemporaryCredentials()
            );
        } else {
            $file = storage_path('app').DIRECTORY_SEPARATOR.'oauth.temp';
            $temp = $this->server->getTemporaryCredentials();
            if (file_exists($file) === false) {
                try {
                    file_put_contents($file, serialize($temp));
                } catch (\Exception $e) {
                    \Log::alert('Could not write temp credentials. Is your storage/app path writable?');
                }
            }
        }

        return new RedirectResponse($this->server->getAuthorizationUrl($temp));
    }

    /**
     * Get the User instance for the authenticated user.
     *
     * @return \Laravel\Socialite\One\User
     */
    public function user()
    {
        if (! $this->hasNecessaryVerifier()) {
            throw new InvalidArgumentException('Invalid request. Missing OAuth verifier.');
        }

        $user = $this->server->getUserDetails($token = $this->getToken());

        $instance = (new User)->setRaw($user->extra)
            ->setToken($token->getIdentifier(), $token->getSecret());

        return $instance->map([
            'id' => $user->uid, 'nickname' => $user->nickname,
            'name' => $user->name, 'email' => $user->email, 'avatar' => $user->imageUrl,
        ]);
    }

    /**
     * Get the token credentials for the request.
     *
     * @return \League\OAuth1\Client\Credentials\TokenCredentials
     */
    protected function getToken()
    {
        if (!$this->isStateless()) {
            $temp = $this->request->getSession()->get('oauth.temp');

            return $this->server->getTokenCredentials(
                $temp, $this->request->get('oauth_token'), $this->request->get('oauth_verifier')
            );
        } else {
            $file = storage_path('app').DIRECTORY_SEPARATOR.'oauth.temp';
            $temp = unserialize(file_get_contents($file));
            @unlink($file);

            return $this->server->getTokenCredentials(
                $temp, $this->request->get('oauth_token'), $this->request->get('oauth_verifier')
            );

        }
    }

    /**
     * Determine if the request has the necessary OAuth verifier.
     *
     * @return bool
     */
    protected function hasNecessaryVerifier()
    {
        return $this->request->has('oauth_token') && $this->request->has('oauth_verifier');
    }

    /**
     * Set the request instance.
     *
     * @param  Request  $request
     * @return $this
     */
    public function setRequest(Request $request)
    {
        $this->request = $request;

        return $this;
    }

    /**
     * Indicates if the session state should be utilized.
     *
     * @var bool
     */
    protected $stateless = false;

    /**
     * Determine if the provider is operating as stateless.
     *
     * @editedBy Felipe Marques <contato@felipemarques.com.br>
     * @return bool
     */
    protected function isStateless()
    {
        return $this->stateless;
    }

    /**
     * Indicates that the provider should operate as stateless.
     *
     * @return $this
     */
    public function stateless()
    {
        $this->stateless = true;

        return $this;
    }
}
