<?php

namespace think\worker\resetters;

use think\App;
use think\Paginator;
use think\worker\contract\ResetterInterface;
use think\worker\Sandbox;

class ResetPaginator implements ResetterInterface
{

    public function handle(App $app, Sandbox $sandbox)
    {
        Paginator::currentPathResolver(function () use ($sandbox) {
            return $sandbox->getApplication()->request->baseUrl();
        });

        Paginator::currentPageResolver(function ($varPage = 'page') use ($sandbox) {

            $page = $sandbox->getApplication()->request->param($varPage);

            if (filter_var($page, FILTER_VALIDATE_INT) !== false && (int) $page >= 1) {
                return (int) $page;
            }

            return 1;
        });
    }
}
