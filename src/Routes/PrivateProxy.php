<?php

namespace Tualo\Office\ReverseProxy\Routes;

use Tualo\Office\Basic\TualoApplication as App;
use Tualo\Office\Basic\RouteSecurityHelper;
use Tualo\Office\Basic\Route as BasicRoute;
use Tualo\Office\Basic\IRoute;

class PrivateProxy extends \Tualo\Office\Basic\RouteWrapper
{
    public static function register()
    {
        /*
        BasicRoute::add('/jsmsgraph-docx/(?P<file>[\w.\/\-]+).js', function ($matches) {

            RouteSecurityHelper::serveSecureStaticFile(
                $matches['file'] . '.js',
                dirname(__DIR__, 1) . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'lazy',
                ['js'],
                [
                    'js' => 'application/javascript',

                ]
            );
        }, ['get'], true);
        */
    }
}
