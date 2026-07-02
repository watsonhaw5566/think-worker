<?php

namespace think\worker\resetters;

use think\App;
use think\Paginator;
use think\worker\contract\ResetterInterface;
use think\worker\Sandbox;
use Throwable;

class ResetPaginator implements ResetterInterface
{

    public function handle(App $app, Sandbox $sandbox)
    {
        // Use the app instance directly; avoid capturing Sandbox to prevent memory leaks.
        // Also guard against missing request in non-HTTP contexts (queue, websocket, CLI).

        Paginator::currentPathResolver(function () use ($app) {
            try {
                return $app->request->baseUrl();
            } catch (Throwable) {
                // Request not available in current context
            }
            return '/';
        });

        Paginator::currentPageResolver(function ($varPage = 'page') use ($app) {
            try {
                $page = $app->request->param($varPage);
                if (filter_var($page, FILTER_VALIDATE_INT) !== false && (int) $page >= 1) {
                    return (int) $page;
                }
            } catch (Throwable) {
                // Request not available in current context
            }
            return 1;
        });
    }
}