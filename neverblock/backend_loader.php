<?php
/**
 * NeverBlock
 *
 * Ad loader script that allows loading external ads without
 * making detectable browser requests to external javascript files or other resources.
 *
 *
 * Version 3.8
 * Copyright (C) 2016 EXADS
 */


define('SCRIPT_VERSION', 'php_3.8');
if (!defined('CONNECT_TIMEOUT_MS')) {
    define('CONNECT_TIMEOUT_MS', 300);
}
if (!defined('REQUEST_TIMEOUT_MS')) {
    define('REQUEST_TIMEOUT_MS', 600);
}
if (!defined('LOGFILE')) {
    define('LOGFILE', NULL);
}
if (!defined('WRITABLE_PATH')) {
    define('WRITABLE_PATH', NULL);
}
if (!defined('CACHE_PREFIX')) {
    define('CACHE_PREFIX', 'exo_v3_');
}
if (!defined('CACHE_INTERVAL_BANNERS')) {
    define('CACHE_INTERVAL_BANNERS', 3600); // id set to 0, banners won't be cached
}
if (!defined('CACHE_KEYS_LIMIT_BANNERS')) {
    define('CACHE_KEYS_LIMIT_BANNERS', 500); //if set to 0, there will be no limit for the amount of keys this script can set
}
if (!defined('CACHE_INTERVAL_SCRIPTS')) {
    define('CACHE_INTERVAL_SCRIPTS', 3600);
}
if (!defined('MULTI_ADS_RESOURCE_URL')) {
    define('MULTI_ADS_RESOURCE_URL', "http://syndication-adblock.exoclick.com/ads-multi.php?v=1");
}
if (!defined('ADS_COOKIE_NAME')) {
    define('ADS_COOKIE_NAME', 'yuo1');
}
if (!defined('BANNER_BASE_URL')) {
    define('BANNER_BASE_URL', "http://static.exoclick.com/library/");
}
if (!defined('ALLOW_MULTI_CURL')) {
    define('ALLOW_MULTI_CURL', true);
}
if (!defined('VERIFY_PEER')) {
    define('VERIFY_PEER', false);
}
if (!defined('LINK_URL_PREFIX')) {
    define('LINK_URL_PREFIX', '');
}
if (!defined('BANNER_URL_PREFIX')) {
    define('BANNER_URL_PREFIX', '');
}
if (!defined('MAX_REDIRECTS')) {
    define('MAX_REDIRECTS', 3);
}
if(!defined('KEY_1')) {
    define('KEY_1', "t8Sn7cvBv2n8duxU28eUxqd6i+gJywzoNo72ItPEtdU=");
}
if(!defined('KEY_2')) {
    define('KEY_2', "zCeopESQfMDIVekZTQjm52lbdnQ7iC2RgLuh3RAhexU=");
}
if(!defined('STATIC_GET_PARAMS')) {
    define('STATIC_GET_PARAMS', false);
}
if(!defined('LINK_GET_PARAM')) {
    define('LINK_GET_PARAM', 'rl');
}
if(!defined('BANNER_GET_PARAM')) {
    define('BANNER_GET_PARAM', 'bn');
}

global $userEnvironment;
global $logger;

$userEnvironment = new UserEnvironment(
    isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null,
    isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : null,
    isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : null,
    isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : null,
    isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? $_SERVER['HTTP_ACCEPT_LANGUAGE'] : null
);

if (isDebugMode()) {
    error_reporting(E_ALL);
    $displayErrors = ini_get("display_errors");
    if (!$displayErrors && ini_set("display_errors", 1) === false) {
        register_shutdown_function("fatalHandler");
    }
}

function fatalHandler() {
    $error = error_get_last();
    if ($error !== NULL) {
        echo "<br><b>Error " . $error['type'] . "</b>: " . $error['message'] . " in <b>" . $error['file'] . "</b> on line <b>" . $error['line'] . "</b><br>";
    }
}

class UserEnvironment
{
    private $ip;
    private $httpUserAgent;
    private $httpReferer;
    private $httpXForwardedFor;
    private $httpAcceptLanguage;

    public function __construct($ip, $httpXForwardedFor, $httpUserAgent, $httpReferer, $httpAcceptLanguage)
    {
        $this->ip = $ip;
        $this->httpXForwardedFor = $httpXForwardedFor;
        $this->httpUserAgent = $httpUserAgent;
        $this->httpReferer = $httpReferer;
        $this->httpAcceptLanguage = $httpAcceptLanguage;
    }

    /**
     * @return mixed
     */
    public function getIp()
    {
        return $this->ip;
    }

    /**
     * @return mixed
     */
    public function getHttpUserAgent()
    {
        return $this->httpUserAgent;
    }

    /**
     * @return mixed
     */
    public function getHttpReferer()
    {
        return $this->httpReferer;
    }

    /**
     * @return mixed
     */
    public function getHttpXForwardedFor()
    {
        return $this->httpXForwardedFor;
    }

    /**
     * @return mixed
     */
    public function getHttpAcceptLanguage()
    {
        return $this->httpAcceptLanguage;
    }
}

interface ResponseInterface
{
    public function getBody();
    public function getHeaders();
    public function getHeader($name);
    public function getHttpCode();
    public function getCookies();
    public function setBody($body);
    public function setHeaders(array $rawHeaders);
    public function appendHeader($rawHeader);
    public function appendCookies(array $cookies);
}

interface RequestGetterInterface
{
    public function resolve($url, $verify_peer = true);
    public function resolveMulti(array $urls, $verify_peer = true);
}

interface LoggerInterface
{
    public function logError($error);
    public function logMessage($message);
    public function getErrors();
    public function getMessages();
}

interface CacheInterface
{
    public function get($key);
    public function set($key, $value, $ttl);
    public function delete($key);
    public function increment($key, $step = 1);
    public function decrement($key, $step = 1);
}

class SimpleHttpResponse implements ResponseInterface
{
    private $httpCode;
    private $body;
    private $rawHeaders = array();
    private $headers = array();
    private $cookies = array();

    public function setBody($body) {
        $this->body = $body;
    }

    public function getBody() {
        return $this->body;
    }

    public function getHttpCode() {
        return $this->httpCode;
    }

    public function setHeaders(array $rawHeaders) {
        $this->rawHeaders = $rawHeaders;
        $parsedHeaders = $this->parseHeaders($rawHeaders);
        $this->httpCode = $parsedHeaders['http_code'];
        $this->headers = $parsedHeaders['headers'];
        $this->appendCookies($parsedHeaders['cookies']);
    }

    public function appendHeader($rawHeader) {
        $this->rawHeaders[] = $rawHeader;
        $parsedHeaders = $this->parseHeaders(array($rawHeader));
        if (!empty($parsedHeaders['http_code'])) {
            $this->httpCode = $parsedHeaders['http_code'];
        }
        $this->headers = array_merge($this->headers, $parsedHeaders['headers']);
        $this->appendCookies($parsedHeaders['cookies']);
    }

    public function getHeaders() {
        return $this->headers;
    }

    public function getHeader($name) {
        if (isset($this->headers[$name])) {
            return $this->headers[$name];
        }
        return null;
    }

    public function appendCookies(array $cookies) {
        $this->cookies = array_merge($this->cookies, $cookies);
    }

    public function getCookies() {
        return $this->cookies;
    }

    /**
     * @param array $headerLines
     * @return array
     */
    public function parseHeaders($headerLines)
    {
        $head = array(
            'headers' => array(),
            'cookies' => array(),
            'http_code' => ''
        );
        foreach($headerLines as $k=>$v)
        {
            $t = explode( ':', $v, 2 );
            if( isset( $t[1] ) )
                if (preg_match('/^Set-Cookie:\s*([^;]*)/mi', $v, $cookie) == 1) {
                    if (!empty($cookie[1])) {
                        $key_val = explode('=', $cookie[1]);
                        $head['cookies'][$key_val[0]] = urldecode($key_val[1]);
                    }
                } else {
                    $head['headers'][trim($t[0])] = trim($t[1]);
                }
            else
            {
                $head[] = $v;
                if( preg_match( "#HTTP/[0-9\.]+\s+([0-9]+)#",$v, $out ) )
                    $head['http_code'] = intval($out[1]);
            }
        }
        return $head;
    }
}

class FsockGetter implements RequestGetterInterface
{
    /**
     * @var LoggerInterface
     */
    private $logger;
    private $connectTimeoutMs;
    private $requestTimeoutMs;
    private $dnsCache;
    private $userEnvironment;

    public function __construct(LoggerInterface &$logger, $connectTimeoutMs, $requestTimeoutMs, UserEnvironment $userEnvironment, DnsCache $dnsCache = null) {
        $this->logger = $logger;
        $this->connectTimeoutMs = $connectTimeoutMs;
        $this->requestTimeoutMs = $requestTimeoutMs;
        $this->userEnvironment = $userEnvironment;
        $this->dnsCache = $dnsCache;
    }

    /**
     * @param $responseBody
     * @return bool|number
     */
    protected function parseNextChunkSize($responseBody)
    {
        $chunkSize = mb_strstr($responseBody, "\r\n", true);
        if ($chunkSize === false
            || $chunkSize === ""
            || !preg_match("/^([a-zA-Z0-9]*)(;.*)?$/", $chunkSize, $matches)
        ) {
            //something is wrong, can't get valid chunk size
            $this->logger->logError("can't get chunk size");
            return false;
        }
        return hexdec($matches[1]);
    }

    /**
     * @param $responseBody
     * @return bool|string
     */
    protected function parseChunkedContent($responseBody)
    {
        $content = "";
        while ($size = $this->parseNextChunkSize($responseBody)) {
            $responseBody = mb_strstr($responseBody, "\r\n");
            $chunk = substr($responseBody, 2, $size);
            $content .= $chunk;
            if (substr($responseBody, $size + 2, 2) !== "\r\n") {
                var_dump(substr($responseBody, $size + 2, 2));
                //there should be a new line after the chunk
                $this->logger->logError("malformated chunk");
                return false;
            }
            $responseBody = substr($responseBody, $size + 4);
        }
        if ($size === false) {
            return false;
        }
        return $content;
    }

    public function resolveMulti(array $urls, $verify_peer = true)
    {
        $responses = array();
        foreach ($urls as $key => $url) {
            $responses[$key] = $this->resolve($url, $verify_peer);
        }
        return $responses;
    }

    /**
     * @param $url
     * @param bool $verify_peer
     * @return bool|ResponseInterface
     * @internal param array $prev_cookies
     */
    public function resolve($url, $verify_peer = true)
    {
        return $this->resolveRecursive($url, $verify_peer, MAX_REDIRECTS);
    }

    /**
     * @param $url
     * @param bool $verify_peer
     * @param int $redirectsCounter
     * @return bool|ResponseInterface
     * @internal param array $prev_cookies
     */
    private function resolveRecursive($url, $verify_peer = true, $redirectsCounter = 1)
    {
        $response = new SimpleHttpResponse();
        $rawResponse = "";
        $urlParts = parse_url($url);

        if (empty($urlParts["host"])
            || !isset($urlParts["scheme"])
            || !in_array($urlParts["scheme"], array("http", "https"))
        ) {
            $this->logger->logError("invalid url");
            return false;
        }

        $sslPrefix = ($urlParts["scheme"] == "https") ? "ssl://" : "";
        $port = ($urlParts["scheme"] == "https") ? 443 : 80;
        $path = isset($urlParts["path"]) ? $urlParts["path"] : "/";
        $query = isset($urlParts["query"]) ? "?" . $urlParts["query"] : "";


        $timeStart = microtime(true);
        $host = (!is_null($this->dnsCache)) ? $this->dnsCache->getHostByName($urlParts["host"]) : $urlParts["host"];
        if (!$verify_peer) {
            $context = stream_context_create();
            stream_context_set_option($context, "ssl", "allow_self_signed", true);
            stream_context_set_option($context, "ssl", "verify_peer", false);
            $fp = stream_socket_client($sslPrefix . $host . ":" . $port, $errno, $errstr, $this->connectTimeoutMs/1000, STREAM_CLIENT_CONNECT, $context);
        } else {
            $fp = fsockopen($sslPrefix . $host, $port, $errno, $errstr, $this->connectTimeoutMs/1000); //timeout is float seconds
        }
        $timeElapsedMs = round((microtime(true) - $timeStart) * 1000);

        if (!$fp) {
            $this->logger->logError("fsockopen failed: " . $errno . " - " . $errstr . " for url " . $url);
            return false;
        } else {
            $timeout = $this->requestTimeoutMs - $timeElapsedMs;
            if ($timeout <= 0) {
                $this->logger->logError("fsockopen failed: request timed out after " . $timeElapsedMs . "ms for url " . $url);
                return false;
            }
            stream_set_timeout($fp, 0, $timeout * 1000); //last parameter is microseconds
            $out = "GET " . $path . $query . " HTTP/1.1\r\n";
            $out .= "Host: " . $urlParts["host"] . "\r\n";
            if (!is_null($this->userEnvironment->getHttpUserAgent())) {
                $out .= "User-Agent: " . $this->userEnvironment->getHttpUserAgent() . "\r\n";
            }
            if (!is_null($this->userEnvironment->getHttpReferer())) {
                $out .= "Referer: " . $this->userEnvironment->getHttpReferer() . "\r\n";
            }
            if (!is_null($this->userEnvironment->getHttpXForwardedFor())) {
                $out .= "X-Forwarded-For: " . $this->userEnvironment->getHttpXForwardedFor() . "\r\n";
            }
            if (!is_null($this->userEnvironment->getHttpAcceptLanguage())) {
                $out .= "Accept-Language: " . $this->userEnvironment->getHttpAcceptLanguage() . "\r\n";
            }
            $out .= "Exo-Script-Version: " . SCRIPT_VERSION . "\r\n";
            $out .= "Connection: Close\r\n\r\n";
            fwrite($fp, $out);
            while (!feof($fp)) {
                $rawResponse .= fgets($fp, 128);
            }
            $timeElapsedMs = round((microtime(true) - $timeStart) * 1000);
            $info = stream_get_meta_data($fp);
            if ($info['timed_out']) {
                $this->logger->logError("fsockopen failed: request timed out after " . $timeElapsedMs . "ms for url " . $url);
                return false;
            }
            fclose($fp);
        }

        $parts = explode("\r\n\r\n", $rawResponse, 2);
        $headerLines = !empty($parts[0]) ? explode("\r\n", $parts[0]) : array();
        $response->setHeaders($headerLines);

        if (in_array($response->getHttpCode(), array(301, 302, 303, 307, 308)) && $redirectsCounter > 0) {
            $newLocation = $response->getHeader('Location');
            if (!empty($newLocation)) {
                return $this->resolveRecursive($newLocation, $verify_peer, --$redirectsCounter);
            }
        }
        if ($response->getHttpCode() != 200) {
            $this->logger->logError("http response code: " . $response->getHttpCode() . " for url " . $url);
            return false;
        }

        if (!isset($parts[1])) {
            $this->logger->logError("no response body found");
            return false;
        }

        if ($response->getHeader('Transfer-Encoding') == 'chunked') {
            $content = $this->parseChunkedContent($parts[1]);
        } else {
            $content = $parts[1];
        }
        $response->setBody($content);

        return $response;
    }
}

class HeaderFunctionProvider {
    /**
     * @var ResponseInterface
     */
    private $response;

    public function __construct(ResponseInterface $response) {
        $this->response = $response;
    }

    public function headerFunction(/** @noinspection PhpUnusedParameterInspection */
        $res, $rawHeader) {
        $this->response->appendHeader($rawHeader);
        return strlen($rawHeader);
    }
}

class CurlGetter implements RequestGetterInterface
{
    /** @var loggerInterface $logger */
    private $logger;
    private $connectTimeoutMs;
    private $requestTimeoutMs;
    private $dnsCache;
    private $userEnvironment;

    public function __construct(LoggerInterface &$logger, $connectTimeoutMs, $requestTimeoutMs, UserEnvironment $userEnvironment, DnsCache $dnsCache = null) {
        $this->logger = $logger;
        $this->connectTimeoutMs = $connectTimeoutMs;
        $this->requestTimeoutMs = $requestTimeoutMs;
        $this->userEnvironment = $userEnvironment;
        $this->dnsCache = $dnsCache;
    }

    private function getOptions($url, $verify_peer, ResponseInterface $response)
    {
        $options = array(
            CURLOPT_HEADER => false,
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => ceil($this->connectTimeoutMs / 1000),
            CURLOPT_TIMEOUT => ceil($this->requestTimeoutMs / 1000),
            CURLOPT_NOSIGNAL => 1,
            CURLOPT_CONNECTTIMEOUT_MS => $this->connectTimeoutMs,
            CURLOPT_TIMEOUT_MS => $this->requestTimeoutMs,
            CURLOPT_FOLLOWLOCATION => 1,
            CURLOPT_MAXREDIRS => MAX_REDIRECTS,
            CURLOPT_ENCODING => "",
        );
        $options[CURLOPT_HTTPHEADER] = array();
        if (!is_null($this->dnsCache)) {
            $urlParts = parse_url($url);
            $host = $this->dnsCache->getHostByName($urlParts['host']);
            $requestUrl = str_replace($urlParts['host'], $host, $url);
            $options[CURLOPT_URL] = $requestUrl;
            $options[CURLOPT_HTTPHEADER][] = 'Host: ' . $urlParts['host'];
        }
        if (defined('CURLOPT_IPRESOLVE')) {
            $options[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
        }
        if (!is_null($this->userEnvironment->getHttpUserAgent())) {
            $options[CURLOPT_HTTPHEADER][] = 'User-Agent: ' . $this->userEnvironment->getHttpUserAgent();
        }
        if (!is_null($this->userEnvironment->getHttpReferer())) {
            $options[CURLOPT_HTTPHEADER][] = 'Referer: ' . $this->userEnvironment->getHttpReferer();
        }
        if (!is_null($this->userEnvironment->getHttpXForwardedFor())) {
            $options[CURLOPT_HTTPHEADER][] = 'X-Forwarded-For: ' . $this->userEnvironment->getHttpXForwardedFor();
        }
        if (!is_null($this->userEnvironment->getHttpAcceptLanguage())) {
            $options[CURLOPT_HTTPHEADER][] = 'Accept-Language: ' . $this->userEnvironment->getHttpAcceptLanguage();
        }
        $options[CURLOPT_HTTPHEADER][] = 'Exo-Script-Version: ' . SCRIPT_VERSION;

        if (!$verify_peer) {
            // for self-signed certificates (testing)
            $options[CURLOPT_SSL_VERIFYPEER] = 0;
        }
        $headerFunctionProvider = new HeaderFunctionProvider($response);
        $options[CURLOPT_HEADERFUNCTION] = array($headerFunctionProvider, 'headerFunction');
        return $options;
    }

    public function resolveMulti(array $urls, $verify_peer = true)
    {
        $responses = array();
        if (version_compare(phpversion(), '5.2') < 1 || !ALLOW_MULTI_CURL || !function_exists('curl_multi_exec')) {
            foreach ($urls as $key => $url) {
                $responses[$key] = $this->resolve($url, $verify_peer);
            }
            return $responses;
        }

        $multi_curl = curl_multi_init();
        $handles = array();
        foreach ($urls as $key => $url) {
            $response = new SimpleHttpResponse();
            $responses[$key] = $response;
            $curl = curl_init();
            curl_setopt_array($curl, $this->getOptions($url, $verify_peer, $response));
            curl_multi_add_handle($multi_curl, $curl);
            $handles[$key] = $curl;
        }

        $active = null;
        do {
            $mrc = curl_multi_exec($multi_curl, $active);
        } while ($mrc == CURLM_CALL_MULTI_PERFORM);

        while ($active && $mrc == CURLM_OK) {
            if (curl_multi_select($multi_curl) == -1) {
                usleep(1);
            }
            do {
                $mrc = curl_multi_exec($multi_curl, $active);
            } while ($mrc == CURLM_CALL_MULTI_PERFORM);
        }
        foreach ($handles as $key => $handle) {
            if (($error = curl_error($handle)) !== '') {
                $this->logger->logError("curl failed: " . $error . " for url " . $urls[$key]);
                $responses[$key] = false;
            } else {
                $responses[$key]->setBody(curl_multi_getcontent($handle));
                if ($responses[$key]->getHttpCode() != 200) {
                    $this->logger->logError("http response code: " . $responses[$key]->getHttpCode() . " for url " . $urls[$key]);
                    $responses[$key] = false;
                }
            }
            curl_multi_remove_handle($multi_curl, $handle);
            curl_close($handle);
        }
        curl_multi_close($multi_curl);
        return $responses;
    }

    /**
     * @param $url
     * @param bool $verify_peer
     * @return bool|ResponseInterface
     */
    public function resolve($url, $verify_peer = true)
    {
        $response = new SimpleHttpResponse();
        $options = $this->getOptions($url, $verify_peer, $response);

        $curl = curl_init();
        curl_setopt_array($curl, $options);
        $response->setBody(curl_exec($curl));

        if (($error = curl_error($curl)) !== '') {
            curl_close($curl);
            $this->logger->logError("curl failed: " . $error . " for url " . $url);
            return false;
        }
        $info = curl_getinfo($curl);
        curl_close($curl);

        if ($info["http_code"] != 200) {
            $this->logger->logError("http response code: " . $info["http_code"] . " for url " . $url);
            return false;
        }
        return $response;
    }
}

class ArrayLogger implements LoggerInterface
{
    protected $errors = array();
    protected $messages = array();

    public function logError($error) {
        $trace = debug_backtrace();
        $errorMsg = ((isset($trace[1]["function"])) ? $trace[1]["function"] . " -- " : "" ) . $error;
        $this->errors[] = $errorMsg;
        // Try to persist to a file
        if (!is_null(LOGFILE) && is_writable(LOGFILE)) {
            $logFile = fopen(LOGFILE, 'a');
            if ($logFile) {
                fwrite($logFile, str_replace(" ", "|", microtime()) . "|" . $errorMsg . "\n");
                fclose($logFile);
            }
        }
    }

    public function getErrors() {
        return $this->errors;
    }

    public function logMessage($message)
    {
        $this->messages[] = $message;
    }

    public function getMessages()
    {
        return $this->messages;
    }
}

class FileCache implements CacheInterface {

    private $path = '';

    public function __construct($writablePath) {
        if (!is_dir($writablePath) || !is_writable($writablePath)) {
            throw new InvalidArgumentException("Provided path is not writable");
        }
        if (substr($writablePath, -1, 1) != '/') {
            $writablePath .= "/";
        }
        $this->path = $writablePath;
    }

    private function getFileName($key) {
        if (preg_match('/[.]{2,}/', $key)) {
            throw new InvalidArgumentException("Malformed key");
        }
        return $this->path . $key;
    }

    public function get($key) {
        $filename = $this->getFileName($key);
        $h = @fopen($filename, 'r');
        if (!$h) {
            return false;
        }
        flock($h, LOCK_SH);
        $data = file_get_contents($filename);
        fclose($h);

        if ($data === false) {
            return false;
        } else {
            $data = @unserialize($data);
            if (!$data) {
                unlink($filename);
                return false;
            }
            if (time() > $data[0]) {
                unlink($filename);
                return false;
            }
            return $data[1];
        }
    }

    public function set($key, $value, $ttl)
    {
        $data = serialize(array(time() + $ttl, $value));
        $written = file_put_contents($this->getFileName($key), $data, LOCK_EX);
        if ($written === false) {
            return false;
        }
        return true;
    }

    public function delete($key)
    {
        $filename = $this->getFileName($key);
        if (!file_exists($filename)) {
            return false;
        }
        return unlink($filename);
    }

    public function increment($key, $step = 1)
    {
        $filename = $this->getFileName($key);
        $h = fopen($filename, 'c+');
        if (!$h) {
            return false;
        }
        flock($h, LOCK_EX);
        $data = fread($h, filesize($filename));
        $data = @unserialize($data);
        if (!$data) {
            unlink($filename);
            return false;
        }
        if (time() > $data[0]) {
            unlink($filename);
            return false;
        }
        if (!is_numeric($data[1])) {
            return false;
        }
        $data[1] += $step;
        $newData = serialize($data);
        fseek($h,0);
        ftruncate($h,0);
        if (fwrite($h, $newData) === false) {
            fclose($h);
            return false;
        }
        fclose($h);
        return $data[1];
    }

    public function decrement($key, $step = 1)
    {
        $filename = $this->getFileName($key);
        $h = fopen($filename, 'c+');
        if (!$h) {
            return false;
        }
        flock($h, LOCK_EX);
        $data = fread($h, filesize($filename));
        $data = @unserialize($data);
        if (!$data) {
            unlink($filename);
            return false;
        }
        if (time() > $data[0]) {
            unlink($filename);
            return false;
        }
        if (!is_numeric($data[1])) {
            return false;
        }
        $data[1] -= $step;
        $newData = serialize($data);
        fseek($h,0);
        ftruncate($h,0);
        if (fwrite($h, $newData) === false) {
            fclose($h);
            return false;
        }
        fclose($h);
        return $data[1];
    }

    /**
     * Cleans up all expired keys (files) in directory. (Otherwise the expired ones, that are not accessed anymore, pile up and are never removed)
     */
    public function cleanup() {
        $files = array_diff(scandir($this->path), array('..', '.'));
        foreach ($files as $key) {
            if (!is_dir($key)) {
                $this->get($key);
            }
        }
    }
}

class XcacheCache implements CacheInterface {
    public function get($key)
    {
        if (xcache_isset($key)) {
            return xcache_get($key);
        }
        return false;
    }

    public function set($key, $value, $ttl)
    {
        return xcache_set($key, $value, $ttl);
    }

    public function delete($key)
    {
        return xcache_unset($key);
    }

    public function increment($key, $step = 1)
    {
        return xcache_inc($key, $step);
    }

    public function decrement($key, $step = 1)
    {
        return xcache_dec($key, $step);
    }
}

class ApcCache implements CacheInterface {
    public function get($key)
    {
        $res = false;
        $data = apc_fetch($key, $res);
        return ($res) ? $data : false;
    }

    public function set($key, $value, $ttl)
    {
        return apc_store($key, $value, $ttl);
    }

    public function delete($key)
    {
        return apc_delete($key);
    }

    public function increment($key, $step = 1)
    {
        return apc_inc($key, $step);
    }

    public function decrement($key, $step = 1)
    {
        return apc_dec($key, $step);
    }
}

class ApcuCache implements CacheInterface {
    public function get($key)
    {
        $res = false;
        $data = apcu_fetch($key, $res);
        return ($res) ? $data : false;
    }

    public function set($key, $value, $ttl)
    {
        return apcu_store($key, $value, $ttl);
    }

    public function delete($key)
    {
        return apcu_delete($key);
    }

    public function increment($key, $step = 1)
    {
        return apcu_inc($key, $step);
    }

    public function decrement($key, $step = 1)
    {
        return apcu_dec($key, $step);
    }
}

class DnsCache {
    private $cache;

    public function __construct(CacheInterface $cache) {
        $this->cache = $cache;
    }

    public function getHostByName($hostName)
    {
        $host = $this->cache->get(CACHE_PREFIX . $hostName);
        if ($host) {
            return $host;
        }
        $host = gethostbyname($hostName . ".");
        $this->cache->set(CACHE_PREFIX . $hostName, $host, 3600);
        return $host;
    }
}

class UniqueMap {
    private $mapData = array();
    private $currentId = 0;

    public function appendAndGetId($data) {
        if (isset($this->mapData[$data])) {
            return $this->mapData[$data];
        } else {
            $id = $this->currentId;
            $this->mapData[$data] = $id;
            $this->currentId++;
            return $id;
        }
    }

    public function getValues() {
        return array_flip($this->mapData);
    }
}

function xorThis($string, $key) {
    $outText = '';

    for ($i=0; $i < strlen($string);)
    {
        for ($j=0; ($j < strlen($key) && $i < strlen($string)); $j++, $i++)
        {
            $outText .= $string[$i] ^ $key[$j];
        }
    }
    return $outText;
}

/**
 * @param LoggerInterface $logger
 * @param int $connectTimeoutMs
 * @param int $requestTimeoutMs
 * @param UserEnvironment $userEnvironment
 * @return RequestGetterInterface
 */
function createRequestGetter($logger, UserEnvironment $userEnvironment, $connectTimeoutMs = CONNECT_TIMEOUT_MS, $requestTimeoutMs = REQUEST_TIMEOUT_MS)
{
    $cache = getCacheInstance();
    $dnsCache = null;
    if ($cache) {
        $dnsCache = new DnsCache($cache);
    }
    if (!in_array('curl', get_loaded_extensions())) {
        //Here we get the content with fsockopen
        $logger->logMessage("No cURL");
        $getter = new FsockGetter($logger, $connectTimeoutMs, $requestTimeoutMs, $userEnvironment, $dnsCache);
    } else {
        //Here we get the content with cURL
        $logger->logMessage("cURL present");
        $getter = new CurlGetter($logger, $connectTimeoutMs, $requestTimeoutMs, $userEnvironment, $dnsCache);
    }
    return $getter;
}

/**
 * @param LoggerInterface $logger
 * @return CacheInterface|bool
 */
function getCacheInstance(LoggerInterface $logger = null) {
    if (WRITABLE_PATH != "" && is_dir(WRITABLE_PATH) && is_writable(WRITABLE_PATH)) {
        if ($logger) {
            $logger->logMessage("File cache present");
        }
        $filecache = new FileCache(WRITABLE_PATH);
        if (mt_rand(1, 1000) == 1000) {
            //Do the cleanup roughly once in a 1000 requests.
            $filecache->cleanup();
        }
        return $filecache;
    } elseif (extension_loaded('xcache')) {
        if ($logger) {
            $logger->logMessage("XCache present");
        }
        return new XcacheCache();
    } elseif (extension_loaded('apc')) {
        if ($logger) {
            $logger->logMessage("APC present");
        }
        return new ApcCache();
    } elseif (extension_loaded('apcu')) {
        if ($logger) {
            $logger->logMessage("APCu present");
        }
        return new ApcuCache();
    } else {
        if ($logger) {
            $logger->logMessage("No cache present");
        }
        return false;
    }
}

function filterRequestParams($allowedParams, $request) {
    $passedParams = array();
    foreach ($allowedParams as $paramName) {
        if (!empty($request[$paramName])) {
            $passedParams[$paramName] = $request[$paramName];
        }
    }
    return $passedParams;
}

function buildUrl($base, array $params)
{
    global $userEnvironment;
    $url = $base;
    $params['user_ip'] = $userEnvironment->getIp();
    $url .= '&' . http_build_query($params);
    return $url;
}

/**
 * @param LoggerInterface $logger
 * @param RequestGetterInterface $getter
 * @param CacheInterface $cache
 * @param array $scriptUrls
 * @param bool $onlySetCache
 * @return array
 */
function resolveAndCacheScripts(LoggerInterface $logger, RequestGetterInterface $getter, $cache, array $scriptUrls, $onlySetCache = false)
{
    $scripts = array();

    if ($onlySetCache && (!$cache || CACHE_INTERVAL_SCRIPTS <= 0)) {
        // We can't cache anything, so no need to continue
        return $scripts;
    }

    foreach ($scriptUrls as $key => $url) {
        if ($cache && CACHE_INTERVAL_SCRIPTS > 0) {
            if ($body = $cache->get(CACHE_PREFIX . md5($url))) {
                $logger->logMessage("Got from cache: " . $url);
                $scripts[$key] = $body;
                unset($scriptUrls[$key]);
            }
        }
    }

    if (!empty($scriptUrls)) {
        $responses = $getter->resolveMulti($scriptUrls, VERIFY_PEER);

        /** @var ResponseInterface $response */
        foreach ($responses as $key => $response) {
            if (!$response) {
                continue;
            }
            $logger->logMessage("Got from ad server: " . $scriptUrls[$key]);
            $body = $response->getBody();
            if ($cache && CACHE_INTERVAL_SCRIPTS > 0) {
                $cache->set(CACHE_PREFIX . md5($scriptUrls[$key]), $body, CACHE_INTERVAL_SCRIPTS);
            }
            $scripts[$key] = $body;
        }
    }

    return $scripts;
}

/**
 * @param LoggerInterface $logger
 * @param RequestGetterInterface $getter
 * @param CacheInterface $cache
 * @param array $image_urls
 * @param bool $onlySetCache
 * @return array
 */
function resolveAndCacheImages(LoggerInterface $logger, RequestGetterInterface $getter, $cache, array $image_urls, $onlySetCache = false)
{
    $images = array();
    //try cache
    if ($cache && CACHE_INTERVAL_BANNERS > 0) {
        foreach ($image_urls as $key => $url) {
            if ($img = $cache->get(CACHE_PREFIX . md5($url))) {
                $logger->logMessage("Got from cache: " . $url);
                $images[$key] = $img;
                unset($image_urls[$key]); // We now don't need to request this one from ad server
            }
        }
    }

    $ctr_key = CACHE_PREFIX . 'banner_key_counter';
    if ($onlySetCache) {
        if (!$cache || CACHE_INTERVAL_BANNERS <= 0) {
            // We can't cache anything, so no need to continue
            return $images;
        }

        //Check how many more we can save in cache
        if (CACHE_KEYS_LIMIT_BANNERS > 0) {
            $availableCacheSlots = CACHE_KEYS_LIMIT_BANNERS;
            $cache_ctr = $cache->get($ctr_key);
            if ($cache_ctr) {
                $availableCacheSlots = CACHE_KEYS_LIMIT_BANNERS - $cache_ctr;
            }

            if ($availableCacheSlots <= 0) {
                return $images;
            }

            if ($availableCacheSlots > 0 && $availableCacheSlots < count($image_urls)) {
                $image_urls = array_slice($image_urls, 0, $availableCacheSlots, true);
            }
        }
    }

    if (empty($image_urls)) {
        return $images;
    }

    $imageResponses = $getter->resolveMulti($image_urls, VERIFY_PEER);

    $imageTypes = array('image/gif', 'image/png', 'image/jpeg');
    $cache_ctr = 0;
    foreach ($image_urls as $key => $url) {
        if (!isset($imageResponses[$key])) {
            $logger->logError("less responses than requests");
        }
        /** @var ResponseInterface $img */
        $img = $imageResponses[$key];
        if ($img && in_array($img->getHeader('Content-Type'), $imageTypes)) {
            $logger->logMessage("Retrieved from ad server: " . $url);
            $images[$key]['img'] = $img->getBody();
            $images[$key]['content_type'] = $img->getHeader('Content-Type');
            if ($cache && CACHE_INTERVAL_BANNERS > 0) {
                if (CACHE_KEYS_LIMIT_BANNERS > 0) {
                    $cache_ctr = $cache->get($ctr_key);
                    if ($cache_ctr == false) {
                        $cache->set($ctr_key, 0, CACHE_INTERVAL_BANNERS);
                    }
                }
                if (CACHE_KEYS_LIMIT_BANNERS == 0 || $cache_ctr < CACHE_KEYS_LIMIT_BANNERS) {
                    $cache->set(CACHE_PREFIX . md5($url), $images[$key], CACHE_INTERVAL_BANNERS);
                    $cache_ctr = $cache->increment($ctr_key, 1);
                } else {
                    $logger->logMessage('Cache key number limit reached for banners');
                }
            }
        }
    }
    return $images;
}

function actionRedirect($url)
{
    addNoCacheHeaders();
    header('Location: ' . $url);
}

function actionShowImage($bannerUrl)
{
    /** @var LoggerInterface $logger */
    global $logger, $userEnvironment;
    $cache = getCacheInstance($logger);
    $urls = array($bannerUrl);
    $getter = createRequestGetter($logger, $userEnvironment);
    $images = resolveAndCacheImages($logger, $getter, $cache, $urls, false);
    if (isset($images[0]) && is_array($images[0])) {
        if (isDebugMode()) {
            return $images[0];
        }
        addCacheHeaders();
        header('Content-Type: ' . $images[0]['content_type']);
        echo $images[0]['img'];
        return true;
    }

    if (isDebugMode()) {
        return false;
    }
    // If something does go wrong, we can avoid displaying ugly missing image icon.
    header('Content-Type: image/gif');
    echo base64_decode('R0lGODlhAQABAJAAAP8AAAAAACH5BAUQAAAALAAAAAABAAEAAAICBAEAOw==');
    return false;
}

function actionProcessAds($requestData, $requestId, $exoLoaderObjName)
{
    addNoCacheHeaders();
    header('Content-type: application/javascript');

    /** @var LoggerInterface $logger */
    global $logger, $userEnvironment;

    $allowedParams = array('idzone', 'cat', 'sub', 'sub2', 'sub3');

    $skippedZonesCount = 0;

    $multiRequest = array();
    foreach ($requestData as $key => $request) {
        $requestParams = filterRequestParams($allowedParams, $request);
        $multiRequest['zones'][] = $requestParams;
    }

    if ($skippedZonesCount == count($multiRequest['zones'])) {
        //No need to request any ads
        return;
    }

    $url = buildUrl(MULTI_ADS_RESOURCE_URL, $multiRequest);
    $logger->logMessage("Banner zones request url: " . $url);
    $getter = createRequestGetter($logger, $userEnvironment);
    /** @var ResponseInterface $response */
    $response = $getter->resolve($url, VERIFY_PEER);

    $cache = getCacheInstance($logger);

    $link_prefix = getLinkUrlPrefix($cache);
    $banner_prefix = getBannerUrlPrefix($cache);

    if (!$response) {
        return;
    }

    $responseData = json_decode($response->getBody(), true);

    if (is_string($responseData) && strpos($responseData, 'ERROR') !== false) {
        $logger->logError($responseData);
    }

    if (!is_array($responseData)
        || !isset($responseData["zones"])
        || !is_array($responseData["zones"])
    ) {
        return;
    }

    $results = array();
    $results["zones"] = array();
    $errorZones = array();
    $imagesMap = new UniqueMap();

    $results['request_id'] = $requestId;
    $results['link_prefix'] = $link_prefix;
    $results['banner_prefix'] = $banner_prefix;

    foreach ($responseData["zones"] as $id => $zone) {
        if ($zone === false) {
            $errorZones[$id] = "No ads for zone";
            continue;
        }

        if (!isset($zone["type"]) || !is_string($zone["type"])) {
            $errorZones[$id] = "Zone type not valid!";
            continue;
        }

        $adType = $zone["type"];
        $formattedResponse = formatAdResponse($adType, $zone, $imagesMap);
        if (!$formattedResponse) {
            $errorZones[$id] = "Invalid zone data";
            continue;
        }
        $results["zones"][$adType][] = array("id" => $id, "data" => $formattedResponse);
    }


    $startAdditionalImagesTag = "//Additional Images START";
    $endAdditionalImagesTag = "//Additional Images END";
    $lengthStartTag = strlen($startAdditionalImagesTag);

    if (isset($responseData['renderers']) && is_array($responseData['renderers'])) {
        $scripts = resolveAndCacheScripts($logger, $getter, $cache, $responseData['renderers']);
        foreach ($scripts as $template => $script) {

            $startAdditionalImages = strpos($script, $startAdditionalImagesTag);
            $endAdditionalImages = strpos($script, $endAdditionalImagesTag);
            if ($startAdditionalImages !== false && $endAdditionalImages !== false) {
                $startAdditionalImages += $lengthStartTag;
                $additionalImagesStr = substr($script, $startAdditionalImages, $endAdditionalImages - $startAdditionalImages);
                $additionalImages = json_decode($additionalImagesStr);
                if (!is_array($additionalImages)) {
                    continue;
                }
                foreach ($additionalImages as $idx => $image) {
                    $results['additional_images'][$template][$idx] = $imagesMap->appendAndGetId($image);
                }
            }
        }
    }

    $imageUrls = $imagesMap->getValues();
    $imageUrlsCodes = array();
    foreach($imageUrls as $key => $url) {
        $imageUrlsCodes[$key] = urlencode(encodeBannerUrl($url));
    }
    $results["images"] = $imageUrlsCodes;

    // Here we do not return images, we try to cache them if possible for future requests
    resolveAndCacheImages($logger, $getter, $cache, $imageUrls, true);
    echo "(function(){\n";
    echo "var ExoLoader = window['" . $exoLoaderObjName . "'];\n";
    echo "var exoNbRequestId = " . $results["request_id"] . ";\n";
    echo "ExoLoader.pushResponseData(" . json_encode($results) . ");";
    if (!empty($scripts)) {
        echo "\n";
        echo implode(";\n", $scripts);
    }
    echo "\n})();\n";

    if (!empty($errorZones)) {
        $logger->logError("Error with some zone responses: " . json_encode($errorZones));
    }
}

function generateRandomString($length = 10) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $charactersLength = strlen($characters);
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[mt_rand(0, $charactersLength - 1)];
    }
    return $randomString;
}

function getScriptUri() {
    static $scriptUri = '';
    if ($scriptUri === '') {
        $scriptUri = htmlspecialchars(strtok($_SERVER["REQUEST_URI"], '?'), ENT_QUOTES, "utf-8");
    }
    return $scriptUri;
}

/**
 * @param CacheInterface|false $cache
 * @return string
 */
function getLinkUrlPrefix($cache) {
    if (LINK_URL_PREFIX != "") {
        return LINK_URL_PREFIX;
    }

    if (STATIC_GET_PARAMS) {
        return getScriptUri() . "?" . LINK_GET_PARAM . "=";
    }

    $getParam = false;
    if ($cache) {
        $getParam = $cache->get(CACHE_PREFIX . 'link_get_param');
    }

    if (!$getParam) {
        $getParam = generateRandomString(mt_rand(3, 6));
        if ($cache) {
            $cache->set(CACHE_PREFIX . 'link_get_param', $getParam, 3600);
        }
    }

    return getScriptUri() . "?" . $getParam . "=";
}

/**
 * @param CacheInterface|false $cache
 * @return string
 */
function getBannerUrlPrefix($cache) {
    if (BANNER_URL_PREFIX != "") {
        return BANNER_URL_PREFIX;
    }

    if (STATIC_GET_PARAMS) {
        return getScriptUri() . "?" . BANNER_GET_PARAM . "=";
    }

    $getParam = false;
    if ($cache) {
        $getParam = $cache->get(CACHE_PREFIX . 'banner_get_param');
    }

    if (!$getParam) {
        $getParam = generateRandomString(mt_rand(3, 6));
        if ($cache) {
            $cache->set(CACHE_PREFIX . 'banner_get_param', $getParam, 3600);
        }
    }

    return getScriptUri() . "?" . $getParam . "=";
}

function formatImageUrl($url) {
    $result = str_replace(' ', '%20', $url); // fix for spaces in urls
    if (strpos($result, 'https://') === 0) {
        $result = 'http://' . substr($result, 8);
    }
    return $result;
}

function formatLinkUrl($url) {
    $result = $url;
    if (strpos($result, 'https://') === 0) {
        $result = substr($result, 8);
    } elseif (strpos($result, 'http://') === 0) {
        $result = substr($result, 7);
    }
    return urlencode(base64_encode($result));
}

function formatAdResponse($adType, $zoneResponse, UniqueMap $imagesMap) {
    switch ($adType) {
        case "native_ad":
            if (!isset($zoneResponse["data"]["data"]) || !is_array($zoneResponse["data"]["data"])) {
                return false;
            }
            $newResponse = array();
            $layoutImageFields = array('branding_logo', 'branding_logo_hover');
            if (isset($zoneResponse["data"]["layout"])) {
                $newResponse["layout"] = $zoneResponse["data"]["layout"];
                foreach ($layoutImageFields as $field) {
                    if (!empty($newResponse["layout"][$field])) {
                        if (strpos($newResponse["layout"][$field], '//') === 0) {
                            $newResponse["layout"][$field] = "http:" . $newResponse["layout"][$field];
                        }
                        if (filter_var($newResponse["layout"][$field], FILTER_VALIDATE_URL)) {
                            $newResponse["layout"][$field] = $imagesMap->appendAndGetId(formatImageUrl($newResponse["layout"][$field]));
                        } else {
                            $newResponse["layout"][$field] = '';
                        }
                    }
                }
            }
            foreach ($zoneResponse["data"]["data"] as $bannerSpot) {
                $bannerSpotNew = $bannerSpot;
                $imageUrl = formatImageUrl($bannerSpot["image"]);
                $bannerSpotNew["image"] = $imagesMap->appendAndGetId($imageUrl);
                $bannerSpotNew["url"] = formatLinkUrl($bannerSpot["url"]);
                $newResponse["data"][] = $bannerSpotNew;
            }
            return $newResponse;
        default:
            if (!isset($zoneResponse["data"])
                || !is_array($zoneResponse["data"])
                || !isset($zoneResponse["data"]["image"])
                || !isset($zoneResponse["data"]["url"])
            ) {
                return false;
            }
            $newResponse = $zoneResponse["data"];
            $newResponse["image"] = $imagesMap->appendAndGetId(formatImageUrl($zoneResponse["data"]["image"]));
            $newResponse["url"] = formatLinkUrl($zoneResponse["data"]["url"]);
            return $newResponse;
    }
}

/**
 * @param $key
 * @param $value
 * @param LoggerInterface $logger
 * @return array|bool
 */
function determineParameterTypeAndValue($key, $value, LoggerInterface $logger)
{
    if (!STATIC_GET_PARAMS || $key === BANNER_GET_PARAM) {
        $bannerUrl = decodeBannerUrl($value);
        if ($bannerUrl) {
            return array('banner', $bannerUrl);
        } elseif (STATIC_GET_PARAMS) {
            $logger->logError('Invalid banner url passed.');
        }
    }

    if (!STATIC_GET_PARAMS || $key === LINK_GET_PARAM) {
        $linkUrl = 'http://' . base64_decode($value);
        if (filter_var($linkUrl, FILTER_VALIDATE_URL)) {
            return array('link', $linkUrl);
        } elseif (STATIC_GET_PARAMS) {
            $logger->logError('Invalid link url passed.');
        }
    }
    return false;
}

function performAction(array $request, array $cookie, LoggerInterface $logger)
{
    if (STATIC_GET_PARAMS) {
        $firstParamKey = false;
        if (isset($request[BANNER_GET_PARAM])) {
            $firstParamKey = BANNER_GET_PARAM;
        } elseif (isset($request[LINK_GET_PARAM])) {
            $firstParamKey = LINK_GET_PARAM;
        }
    } else {
        $firstParamKey = key($request);
    }

    if ($firstParamKey) {
        $detectedParam = determineParameterTypeAndValue($firstParamKey, $request[$firstParamKey], $logger);
        if ($detectedParam) {
            switch ($detectedParam[0]) {
                case 'link':
                    actionRedirect($detectedParam[1]);
                    die();
                case 'banner':
                    actionShowImage($detectedParam[1]);
                    if (isDebugMode()) {
                        displayDebug($logger);
                    }
                    die();
            }
        }
    }

    $requestId = null;
    if (isset($cookie["request_id"])) {
        $requestId = (int) $cookie["request_id"];
    }
    $exoLoaderObjName = isset($cookie['objName']) ? $cookie['objName'] : 'ExoLoader';
    if (isset($cookie['zones'])) {
        actionProcessAds($cookie['zones'], $requestId, $exoLoaderObjName);
        if (isDebugMode()) {
            displayDebug($logger);
        }
        die();
    }

    $logger->logMessage('Unknown action');
    if (isDebugMode()) {
        displayDebug($logger);
    }
}

function encodeBannerUrl($url) {
    $newUrl = str_replace(BANNER_BASE_URL, "", $url);
    $newUrl = $newUrl . "|" . md5($newUrl . KEY_1);
    $newUrl = xorThis($newUrl, KEY_2);
    return base64_encode(";;" . $newUrl);
}

function decodeBannerUrl($url) {
    if (!preg_match("/^[a-zA-Z0-9=\/\+]+$/", $url)) {
        return false;
    }
    $decodedUrl = base64_decode($url);
    if (strpos($decodedUrl, ";;") !== 0) {
        return false;
    }
    $decodedUrl = substr($decodedUrl, 2);
    $decodedUrl = xorThis($decodedUrl, KEY_2);
    $hashPos = strrpos($decodedUrl , "|");
    if ($hashPos === false) {
        return false;
    }
    $hash = substr($decodedUrl, $hashPos + 1);
    $decodedUrl = substr($decodedUrl, 0, $hashPos);
    if (empty($decodedUrl)) {
        return false;
    }
    if (md5($decodedUrl . KEY_1) !== $hash) {
        return false;
    }

    if (preg_match('/^http[s]?:\/\/.+/i', $decodedUrl)) {
        return $decodedUrl;
    }
    return BANNER_BASE_URL . $decodedUrl;
}

function addCacheHeaders()
{
    header('Pragma: public');
    header('Cache-Control: max-age=86400');
    header('Expires: '. gmdate('D, d M Y H:i:s \G\M\T', time() + 86400));
}

function addNoCacheHeaders()
{
    header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
    header("Cache-Control: no-cache, must-revalidate");
    header("Pragma: no-cache");
}

function timeTrack($total = false, $reset = false)
{
    static $start = 0;
    static $startSection = 0;

    if ($reset) {
        $start = 0;
        $startSection = 0;
    }

    if ($start == 0) {
        $start = microtime(true);
        $startSection = $start;
        return 0;
    }

    $newStart = microtime(true);
    $diff = $newStart - $startSection;
    $startSection = $newStart;
    if ($total) {
        return ($newStart - $start);
    }
    return $diff;
}

function isDebugMode() {
    //return true;
    return (!empty($_REQUEST['exoDebug']) && $_REQUEST['exoDebug'] == 'exoDebug');
}

function displayDebug(LoggerInterface $logger) {
    $errors = $logger->getErrors();
    $messages = $logger->getMessages();
    echo "\n<pre>";
    echo "\n-----------------------------------------------------------------\n";
    echo "DEBUG INFO:";
    echo "\n-----------------------------------------------------------------\n";
    echo "Script version: " . SCRIPT_VERSION . "\n";
    echo "Php version: " . phpversion() . "\n";
    echo "CONNECT_TIMEOUT_MS: " . CONNECT_TIMEOUT_MS . "\n";
    echo "REQUEST_TIMEOUT_MS: " . REQUEST_TIMEOUT_MS . "\n";
    echo "LOGFILE: " . ((!is_null(LOGFILE) && is_writable(LOGFILE)) ? 'on' : 'off') . "\n";
    echo "CACHE_PREFIX: " . CACHE_PREFIX . "\n";
    echo "CACHE_PREFIX: " . REQUEST_TIMEOUT_MS . "\n";
    echo "CACHE_INTERVAL_BANNERS: " . CACHE_INTERVAL_BANNERS . "\n";
    echo "CACHE_KEYS_LIMIT_BANNERS: " . CACHE_KEYS_LIMIT_BANNERS . "\n";
    echo "CACHE_INTERVAL_SCRIPTS: " . CACHE_INTERVAL_SCRIPTS . "\n";
    echo "MULTI_ADS_RESOURCE_URL: " . MULTI_ADS_RESOURCE_URL . "\n";
    echo "ADS_COOKIE_NAME: " . ADS_COOKIE_NAME . "\n";
    echo "ALLOW_MULTI_CURL: " . ALLOW_MULTI_CURL . "\n";
    echo "VERIFY_PEER: " . VERIFY_PEER . "\n";
    if (!empty($messages)) {
        echo "\n-------\n";
        echo "Debug Messages:\n";
        print_r($messages);
    }
    if (!empty($errors)) {
        echo "\n-------\n";
        echo "Errors:\n";
        print_r($errors);
    }
    echo "Generated in " . timeTrack(true) . " seconds\n";
    echo "-----------------------------------------------------------------\n";
    echo "</pre>\n";
}

timeTrack();
if (!isset($testRun)) {
    $logger = new ArrayLogger();
    $cookie = array();
    if (!empty($_COOKIE[ADS_COOKIE_NAME])) {
        $cookie = json_decode($_COOKIE[ADS_COOKIE_NAME], true);
    } elseif (!empty($_REQUEST[ADS_COOKIE_NAME])) {
        $cookie = json_decode($_REQUEST[ADS_COOKIE_NAME], true);
    }
    if (!is_array($cookie)) {
        $cookie = array();
        $logger->logError('Cookie set, but has invalid format.');
    }
    performAction($_REQUEST, $cookie, $logger);
}
