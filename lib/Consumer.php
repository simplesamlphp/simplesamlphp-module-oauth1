<?php

declare(strict_types=1);

namespace SimpleSAML\Module\oauth;

require_once(dirname(dirname(__FILE__)) . '/libextinc/OAuth.php');

use SimpleSAML\Utils;

/**
 * OAuth Consumer
 *
 * @package SimpleSAMLphp
 */
class Consumer
{
    /** @var \OAuthConsumer */
    private \OAuthConsumer $consumer;

    /** @var \OAuthSignatureMethod */
    private \OAuthSignatureMethod $signer;


    /**
     * @param string $key
     * @param string $secret
     */
    public function __construct(string $key, string $secret)
    {
        $this->consumer = new \OAuthConsumer($key, $secret, null);
        $this->signer = new \OAuthSignatureMethod_HMAC_SHA1();
    }


    /**
     * Used only to load the libextinc library early
     */
    public static function dummy(): void
    {
    }


    /**
     * @param array $hrh
     * @return string|null
     */
    public static function getOAuthError(array $hrh): ?string
    {
        foreach ($hrh as $h) {
            if (preg_match('|OAuth-Error:\s([^;]*)|i', $h, $matches)) {
                return $matches[1];
            }
        }
        return null;
    }


    /**
     * @param array $hrh
     * @return string|null
     */
    public static function getContentType(array $hrh): ?string
    {
        foreach ($hrh as $h) {
            if (preg_match('|Content-Type:\s([^;]*)|i', $h, $matches)) {
                return $matches[1];
            }
        }
        return null;
    }


    /**
     * This static helper function wraps \SimpleSAML\Utils\HTTP::fetch
     * and throws an exception with diagnostics messages if it appear
     * to be failing on an OAuth endpoint.
     *
     * If the status code is not 200, an exception is thrown. If the content-type
     * of the response if text/plain, the content of the response is included in
     * the text of the Exception thrown.
     *
     * @param string $url
     * @param string $context
     * @return string
     * @throws \Exception
     */
    public static function getHTTP(string $url, string $context = ''): string
    {
        try {
            $httpUtils = new Utils\HTTP();
            $response = $httpUtils->fetch($url, [], false);
        } catch (\SimpleSAML\Error\Exception $e) {
            $statuscode = 'unknown';

            /** @psalm-suppress UndefinedVariable */
            if (preg_match('/^HTTP.*\s([0-9]{3})/', $http_response_header[0], $matches)) {
                $statuscode = $matches[1];
            }

            $error = $context . ' [statuscode: ' . $statuscode . ']: ';
            /** @psalm-suppress UndefinedVariable */
            $oautherror = self::getOAuthError($http_response_header);

            if (!empty($oautherror)) {
                $error .= $oautherror;
            }

            throw new \Exception($error . ':' . $url);
        }
        // Fall back to return response, if could not reckognize HTTP header. Should not happen.
        /** @var string $response */
        return $response;
    }


    /**
     * @param string $url
     * @param array|null $parameters
     * @return \OAuthToken
     * @throws \Exception
     */
    public function getRequestToken(string $url, array $parameters = null): \OAuthToken
    {
        $req_req = \OAuthRequest::from_consumer_and_token($this->consumer, null, "GET", $url, $parameters);
        $req_req->sign_request($this->signer, $this->consumer, null);

        $response_req = self::getHTTP(
            $req_req->to_url(),
            'Contacting request_token endpoint on the OAuth Provider'
        );

        parse_str($response_req, $responseParsed);

        if (array_key_exists('error', $responseParsed)) {
            throw new \Exception('Error getting request token: ' . $responseParsed['error']);
        }

        $requestToken = $responseParsed['oauth_token'];
        $requestTokenSecret = $responseParsed['oauth_token_secret'];

        return new \OAuthToken($requestToken, $requestTokenSecret);
    }


    /**
     * @param string $url
     * @param \OAuthToken $requestToken
     * @param bool $redirect
     * @param callable|null $callback
     * @return string
     */
    public function getAuthorizeRequest(
        string $url,
        \OAuthToken $requestToken,
        bool $redirect = true,
        callable $callback = null
    ): string {
        $params = ['oauth_token' => $requestToken->key];
        if ($callback) {
            $params['oauth_callback'] = $callback;
        }
        $httpUtils = new Utils\HTTP();
        $authorizeURL = $httpUtils->addURLParameters($url, $params);
        if ($redirect) {
            $httpUtils->redirectTrustedURL($authorizeURL);
            exit;
        }
        return $authorizeURL;
    }


    /**
     * @param string $url
     * @param \OAuthToken $requestToken
     * @param array|null $parameters
     * @return \OAuthToken
     * @throws \Exception
     */
    public function getAccessToken(string $url, \OAuthToken $requestToken, array $parameters = null): \OAuthToken
    {
        $acc_req = \OAuthRequest::from_consumer_and_token($this->consumer, $requestToken, "GET", $url, $parameters);
        $acc_req->sign_request($this->signer, $this->consumer, $requestToken);

        $httpUtils = new Utils\HTTP();
        try {
            /** @var string $response_acc */
            $response_acc = $httpUtils->fetch($acc_req->to_url(), [], false);
        } catch (\SimpleSAML\Error\Exception $e) {
            throw new \Exception('Error contacting request_token endpoint on the OAuth Provider');
        }

        \SimpleSAML\Logger::debug('oauth: Reponse to get access token: ' . $response_acc);

        $accessResponseParsed = [];
        parse_str($response_acc, $accessResponseParsed);

        if (array_key_exists('error', $accessResponseParsed)) {
            throw new \Exception('Error getting request token: ' . $accessResponseParsed['error']);
        }

        $accessToken = $accessResponseParsed['oauth_token'];
        $accessTokenSecret = $accessResponseParsed['oauth_token_secret'];

        return new \OAuthToken($accessToken, $accessTokenSecret);
    }


    /**
     * @param string $url
     * @param \OAuthToken $accessToken
     * @param array $parameters
     * @return array|string
     * @throws \SimpleSAML\Error\Exception
     */
    public function postRequest(string $url, \OAuthToken $accessToken, array $parameters)
    {
        $data_req = \OAuthRequest::from_consumer_and_token($this->consumer, $accessToken, "POST", $url, $parameters);
        $data_req->sign_request($this->signer, $this->consumer, $accessToken);
        $postdata = $data_req->to_postdata();

        $opts = [
            'ssl' => [
                'verify_peer' => false,
                'capture_peer_cert' => true,
                'capture_peer_chain' => true
            ],
            'http' => [
                'method' => 'POST',
                'content' => $postdata,
                'header' => 'Content-Type: application/x-www-form-urlencoded',
            ],
        ];

        $httpUtils = new Utils\HTTP();
        try {
            $response = $httpUtils->fetch($url, $opts);
        } catch (\SimpleSAML\Error\Exception $e) {
            throw new \SimpleSAML\Error\Exception('Failed to push definition file to ' . $url);
        }
        return $response;
    }


    /**
     * @param string $url
     * @param \OAuthToken $accessToken
     * @param array $opts
     * @return array|null
     */
    public function getUserInfo(string $url, \OAuthToken $accessToken, array $opts = []): ?array
    {
        $data_req = \OAuthRequest::from_consumer_and_token($this->consumer, $accessToken, "GET", $url, null);
        $data_req->sign_request($this->signer, $this->consumer, $accessToken);

        $httpUtils = new Utils\HTTP();
        /** @var string $data */
        $data = $httpUtils->fetch($data_req->to_url(), $opts);

        return json_decode($data, true);
    }
}
