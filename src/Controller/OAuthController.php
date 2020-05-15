<?php
declare(strict_types=1);

namespace OAuthServer\Controller;

use Cake\Core\Configure;
use Cake\Event\EventInterface;
use Cake\Http\Exception\HttpException;
use Cake\Http\Response;
use League\OAuth2\Server\Exception\OAuthServerException;
use OAuthServer\Bridge\Entity\User;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Class OAuthController
 *
 * @property \OAuthServer\Controller\Component\OAuthComponent $OAuth
 * @mixin Controller
 */
class OAuthController extends AppController
{
    /**
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();

        $this->loadComponent('OAuthServer.OAuth', Configure::read('OAuthServer', []));
        $this->loadComponent('RequestHandler');
        $this->RequestHandler->setConfig('enableBeforeRedirect', false);

        if (!$this->components()->has('Auth')) {
            throw new RuntimeException('OAuthServer requires Auth component to be loaded and properly configured');
        }

        $this->Auth->allow(['oauth', 'accessToken']);
        $this->Auth->deny(['authorize']);

        // if accessToken action, disable CsrfComponent|SecurityComponent
        if ($this->request->getParam('action') === 'accessToken') {
            if ($this->components()->has('Csrf')) {
                $this->components()->unload('Csrf');
            }
            if ($this->components()->has('Security')) {
                $this->components()->unload('Security');
            }
        }
    }

    /**
     * on Controller.initialize
     *
     * @param \Cake\Event\EventInterface $event the event
     * @return \Cake\Http\Response|null
     */
    public function beforeFilter(EventInterface $event): ?Response
    {
        // if prompt=login on authorize action, then logout and remove prompt params
        if (
            $this->request->getParam('action') === 'authorize'
            && $this->request->getQuery('prompt') === 'login'
        ) {
            $this->Auth->logout();

            $query = $this->request->getQueryParams();
            unset($query['prompt']);

            return $this->redirect([
                'action' => 'authorize',
                '?' => $query,
            ]);
        }

        return null;
    }

    /**
     * @return void
     */
    public function oauth()
    {
        $this->redirect([
            'action' => 'authorize',
            '_ext' => $this->request->getParam('_ext'),
            '?' => $this->request->getQueryParams(),
        ], 301);
    }

    /**
     * @return \Cake\Http\Response|\Psr\Http\Message\ResponseInterface|void
     */
    public function authorize()
    {
        try {
            $server = $this->OAuth->getServer();
            $authRequest = $server->validateAuthorizationRequest($this->request);

            $this->dispatchEvent('OAuthServer.beforeAuthorize', [$authRequest]);

            $userId = $this->Auth->user($this->OAuth->getPrimaryKey());
            if ($userId) {
                $authRequest->setUser(new User($userId));
            }

            if ($this->request->getData('authorization') === 'Approve') {
                $authRequest->setAuthorizationApproved(true);
            }

            if ($this->request->is('post')) {
                $response = $server->completeAuthorizationRequest($authRequest, $this->response);

                $event = $this->dispatchEvent('OAuthServer.afterAuthorize', [$authRequest, $response]);
                if (!$event->isStopped() && $event->getResult() instanceof ResponseInterface) {
                    return $event->getResult();
                }

                return $response;
            }
        } catch (OAuthServerException $e) {
            if ($e->getErrorType() === 'access_denied') {
                $this->dispatchEvent('OAuthServer.afterDeny', [$authRequest]);

                $redirectUri = $authRequest->getRedirectUri() . http_build_query([
                        'error' => $e->getErrorType(),
                        'message' => $e->getMessage(),
                    ]);

                return $this->redirect($redirectUri);
            }

            // ignoring $e->getHttpHeaders() for now
            // it only sends WWW-Authenticate header in case of InvalidClientException
            throw new HttpException($e->getMessage(), $e->getHttpStatusCode(), $e);
        }

        $authParams = [
            'redirectUri' => $authRequest->getRedirectUri(),
            'client' => $authRequest->getClient(),
            'scopes' => $authRequest->getScopes(),
        ];
        $user = $this->Auth->user();

        $this->set(compact('authParams', 'user'));
        $this->viewBuilder()->setOption('serialize', ['authParams', 'user']);
    }

    /**
     * @return \Cake\Http\Response|\Psr\Http\Message\ResponseInterface|null
     */
    public function accessToken()
    {
        try {
            return $this->OAuth->getServer()->respondToAccessTokenRequest($this->request, $this->response);
        } catch (OAuthServerException $e) {
            // ignoring $e->getHttpHeaders() for now
            // it only sends WWW-Authenticate header in case of InvalidClientException
            throw new HttpException($e->getMessage(), $e->getHttpStatusCode(), $e);
        }
    }
}
