<?php
declare(strict_types=1);

namespace TestApp;

use Cake\Http\BaseApplication;
use Cake\Http\MiddlewareQueue;
use Cake\Routing\Middleware\RoutingMiddleware;

class Application extends BaseApplication
{
    /**
     * @inheritDoc
     */
    public function bootstrap(): void
    {
        parent::bootstrap();

        $this->addPlugin('OAuthServer', ['path' => dirname(dirname(__DIR__)) . DS, 'bootstrap' => true, 'route' => true]);
    }

    /**
     * @inheritDoc
     */
    public function middleware($middleware): MiddlewareQueue
    {
        $middleware->add(new RoutingMiddleware($this));

        return $middleware;
    }
}
