<?php

namespace Tualo\Office\ReverseProxy\Routes;

use Tualo\Office\Basic\TualoApplication as App;
use Tualo\Office\Basic\Route as BasicRoute;
use Tualo\Office\DS\DataRenderer;
use Tualo\Office\ReverseProxy\ReverseProxy;

class PublicProxy extends \Tualo\Office\Basic\SessionRouteWrapper
{
    public static function register()
    {
        try {
            $session = App::get('session');
            if (!is_null($session) && $session->isLoggedIn()) {
                $db = App::get('session')->getDB();
                $routes = $db->direct('select * from reverse_proxy_public_routes where active=1');
                foreach ($routes as $route) {
                    $methods = ['get', 'post', 'put', 'patch', 'delete', 'options', 'head'];
                    $path = $route['route_path'];
                    $route['allowed_methods'] = trim(str_replace(',,', ',', str_replace("\n", ',', str_replace(' ', '', strtolower($route['allowed_methods'])))));
                    $route['allowed_forward_headers'] = trim(str_replace(',,', ',', str_replace("\n", ',', str_replace(' ', '', strtolower($route['allowed_forward_headers'])))));
                    $route['filter_response_headers'] = trim(str_replace(',,', ',', str_replace("\n", ',', str_replace(' ', '', strtolower($route['filter_response_headers'])))));
                    if (trim($route['allowed_methods']) != '') {
                        $methods = explode(',', $route['allowed_methods']);
                    }

                    BasicRoute::add($path, function ($matches) use ($route, $methods) {
                        try {
                            $cookies = [];
                            if ($route['store_cookies_in_session'] == 1) {
                                if (isset($_SESSION['reverse_proxy_public_routes']) && isset($_SESSION['reverse_proxy_public_routes']['cookies']) && is_array($_SESSION['reverse_proxy_public_routes']['cookies'])) {
                                    $cookies = $_SESSION['reverse_proxy_public_routes']['cookies'];
                                }
                            }

                            $route['target_url'] = DataRenderer::renderTemplate($route['target_url'], $matches);
                            $proxy = new ReverseProxy($route['target_url']);

                            $proxy->setAllowedMethods($methods);
                            if (trim($route['allowed_forward_headers']) != '') {
                                $proxy->setAllowedForwardHeaders(explode(',', $route['allowed_forward_headers']));
                            }
                            // Optionale Header-Filterung
                            $filterHeaders = explode(',', $route['filter_response_headers']);
                            $proxy->setFilterResponseHeaders($filterHeaders);
                            // Cookies aus Session hinzufügen
                            foreach ($cookies as $cookie) {
                                $proxy->addCookie($cookie);
                            }
                            // Optionale Modifikation des Response-Inhalts
                            if (trim($route['response_modifier_code']) != '') {
                                $code = $route['response_modifier_code'];
                                $proxy->setResponseModifier(function ($body, $contentType) use ($code) {
                                    // Achtung: unsicher, da eval verwendet wird!
                                    eval($code);
                                    return $body;
                                });
                            }
                            $proxy->handleRequest();

                            // Cookies in Session speichern
                            if ($route['store_cookies_in_session'] == 1) {
                                $responseCookies = $proxy->getCookies();
                                if (!isset($_SESSION['reverse_proxy_public_routes'])) {
                                    $_SESSION['reverse_proxy_public_routes'] = [];
                                }
                                $_SESSION['reverse_proxy_public_routes']['cookies'] = $responseCookies;
                            }
                            exit();
                        } catch (\Exception $e) {

                            http_response_code(500);
                            header('Content-Type: application/json; charset=utf-8');
                            echo json_encode([
                                'error' => $e->getMessage()
                            ]);
                        }
                    }, $methods, true);
                }
            }
        } catch (\Exception $e) {
            App::logger("ReverseProxy")->error($e->getMessage());
        }
    }
}
