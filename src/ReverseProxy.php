<?php

namespace Tualo\Office\ReverseProxy;

use Tualo\Office\Basic\TualoApplication as App;

class ReverseProxy
{
    private string $targetUrl;
    private array $cookies = [];
    private array $allowedMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'];
    private array $allowedForwardHeaders = ['Accept', 'Accept-Language', 'Authorization', 'Content-Type', 'User-Agent'/*, 'Cookie'*/];
    private array $filterResponseHeaders = [];
    private $responseModifier;

    public function __construct(?string $targetUrl)
    {
        $this->targetUrl = $targetUrl;
    }

    public function setAllowedMethods(array $methods)
    {
        $this->allowedMethods = $methods;
    }

    public function addCookie(string $cookie)
    {
        $this->cookies[]     = $cookie;
    }

    public function getCookies(): array
    {
        return $this->cookies;
    }

    public function setAllowedForwardHeaders(array $headers)
    {
        $this->allowedForwardHeaders = $headers;
    }

    public function setResponseModifier(?callable $modifier)
    {
        $this->responseModifier = $modifier;
    }

    public function setFilterResponseHeaders(array $headers)
    {
        $this->filterResponseHeaders = $headers;
    }

    public function toLower(array $input): array
    {
        $result = [];
        foreach ($input as $key => $value) {
            $result[$key] = strtolower($value);
        }
        return $result;
    }

    public function handleRequest()
    {

        $targetBase = $this->targetUrl; // <-- Ziel-URL (ohne Query oder mit, beides geht)

        // 1) Ziel-URL zusammenbauen (Query-Parameter komplett übernehmen)
        $queryString = $_SERVER['QUERY_STRING'] ?? '';
        $targetUrl = $targetBase . ($queryString !== '' ? (str_contains($targetBase, '?') ? '&' : '?') . $queryString : '');

        // 2) Request-Method & Body übernehmen
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $this->allowedMethods = $this->toLower($this->allowedMethods);
        if (!in_array(strtolower($method), $this->allowedMethods, true)) {
            App::logger("ReverseProxy")->debug("Method not allowed: " . $method . " FILE " . __FILE__ . " LINE " . __LINE__);
            http_response_code(405);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Methode nicht erlaubt.";
            exit;
        }

        $rawBody = file_get_contents('php://input');

        // 3) (Optional) Eingehende Header (Request) teilweise übernehmen
        //    WICHTIG: Nicht blind ALLE Header forwarden, sonst gibts oft Ärger mit Host/Content-Length/etc.
        $incomingHeaders = function_exists('getallheaders') ? getallheaders() : [];
        $forwardHeaders = [];

        // Beispiel: Nur sinnvolle Header weitergeben:
        $allowList = $this->toLower($this->allowedForwardHeaders);

        foreach ($incomingHeaders as $name => $value) {
            // normalize
            $normName = preg_replace('/\s+/', '-', trim($name));
            if (in_array(strtolower($normName), $allowList, true)) {
                // Content-Length lieber nicht setzen, macht cURL selbst passend
                if (strcasecmp($normName, 'Content-Length') === 0) continue;
                $forwardHeaders[] = $normName . ': ' . $value;
            } else {
                App::logger("ReverseProxy")->debug("Filtered-Header: " . $normName);
            }
        }

        if (!empty($this->cookies)) {
            foreach ($this->cookies as $value) {
                if (preg_match('/^Set-Cookie:\s*([^;]*)/mi', $value, $match)) {
                    $cookieParts = explode(':', $value, 2);
                    if (count($cookieParts) == 2) {
                        $cookieValue = trim($cookieParts[1]);
                        curl_setopt($ch, CURLOPT_COOKIE, $cookieValue);
                    }
                }
                $forwardHeaders[] =  'Cookie: ' .   $cookieValue;
            }
        }



        // 4) cURL Setup
        $ch = curl_init($targetUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,  // <-- wichtig: Header + Body zusammen zurückgeben
            CURLOPT_FOLLOWLOCATION => false, // je nach Bedarf true/false
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $forwardHeaders,
            CURLOPT_TIMEOUT        => 30,
        ]);




        // Methoden mit Body (POST/PUT/PATCH/DELETE etc.)
        $methodsWithBody = ['POST', 'PUT', 'PATCH', 'DELETE'];
        if (in_array(strtoupper($method), $methodsWithBody, true)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $rawBody);
        }

        // 5) Ausführen
        $response = curl_exec($ch);
        if ($response === false) {
            App::logger("ReverseProxy")->debug("Proxy-Fehler: " . $method . " FILE " . __FILE__ . " LINE " . __LINE__);
            http_response_code(502);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Proxy-Fehler: " . curl_error($ch);
            exit;
        }

        $info = curl_getinfo($ch);
        curl_close($ch);

        // 6) Response in Header + Body trennen
        $headerSize = $info['header_size'] ?? 0;
        $rawHeaderBlock = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);

        // 7) Header-Block in einzelne Headerzeilen parsen
        $headerLines = preg_split("/\r\n|\n|\r/", trim($rawHeaderBlock));
        $statusLine = array_shift($headerLines); // z.B. HTTP/2 200
        $statusCode = $info['http_code'] ?? 200;


        foreach ($headerLines as $line) {
            if (preg_match('/^Set-Cookie:\s*([^;]*)/mi', $line, $match)) {
                $this->addCookie($line);
            } else {
                continue;
            }
        }

        // 8) >>> HIER kannst du Response-Header bearbeiten <<<
        //    - bestimmte Header entfernen/ersetzen
        //    - eigene Header hinzufügen
        $editedHeaders = [];
        foreach ($headerLines as $line) {
            if ($line === '') continue;

            // z.B. "Transfer-Encoding: chunked" nicht an PHP-Output weiterreichen
            if (stripos($line, 'Transfer-Encoding:') === 0) continue;

            // Beispiel: Location umschreiben (falls du Redirects proxen willst)
            if (stripos($line, 'Location:') === 0) {
                // $line = 'Location: /irgendwas'; // Beispiel
            }

            // Beispiel: Set-Cookie ggf. filtern/ändern
            // if (stripos($line, 'Set-Cookie:') === 0) { ... }

            $editedHeaders[] = $line;
        }

        // Eigene Header hinzufügen
        $editedHeaders[] = 'X-Proxy-By: PHP-Forwarder';

        // 9) >>> HIER kannst du den Body bearbeiten <<<
        // Beispiel: JSON manipulieren, Text ersetzen, etc.
        $contentType = '';
        foreach ($editedHeaders as $h) {
            if (stripos($h, 'Content-Type:') === 0) {
                $contentType = trim(substr($h, strlen('Content-Type:')));
                break;
            }
        }

        /*
        if ($contentType !== '' && stripos($contentType, 'application/json') !== false) {
            $data = json_decode($body, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                // Beispiel-Änderung:
                $data['_proxied'] = true;
                $body = json_encode($data, JSON_UNESCAPED_UNICODE);
                // Content-Length später neu setzen (oder weglassen)
            }
        }
        */

        // 10) Statuscode setzen
        http_response_code($statusCode);

        // 11) Header ausgeben (Content-Length am besten neu setzen oder weglassen)
        foreach ($editedHeaders as $h) {
            // Content-Length entfernen, wenn Body verändert wurde (PHP setzt es ggf. selbst)
            if (stripos($h, 'Content-Length:') === 0) continue;
            // Filter-Header entfernen
            $skip = false;
            foreach ($this->filterResponseHeaders as $filterHeader) {
                if (stripos($h, $filterHeader . ':') === 0) {
                    $skip = true;
                    break;;
                }
            }
            if ($skip) continue;
            header($h, false);
        }


        // 12) Inhalt ggf. modifizieren
        if ($this->responseModifier !== null) {
            $modifier = $this->responseModifier;
            $body = $modifier($body, $contentType);
        }

        echo $body;
    }
}
