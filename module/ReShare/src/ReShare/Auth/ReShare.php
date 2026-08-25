<?php

namespace ReShare\Auth;

use VuFind\Exception\Auth as AuthException;

class ReShare extends \VuFind\Auth\AbstractBase
{
    protected $userService;
    protected $ilsAuthenticator;
    protected $reShareConfig;

    public function __construct($userService, $ilsAuthenticator, $reShareConfig)
    {
        $this->userService = $userService;
        $this->ilsAuthenticator = $ilsAuthenticator;
        $this->reShareConfig = $reShareConfig;
    }

    /**
     * Build the patron validation endpoint URL.
     *
     * @param string $institution Institution selected during login
     *
     * @return string
     */
    protected function getPatronValidationUrl(string $institution): string
    {
        $patronApi = rtrim($this->reShareConfig['API']['patron_api'], '/');
        return $patronApi . '/' . $institution . '/patron/validate';
    }

    /**
     * Attempt to authenticate the current user.  Throws exception if login fails.
     *
     * @param \Laminas\Http\PhpEnvironment\Request $request Request object containing
     * account credentials.
     *
     * @throws AuthException
     * @return \VuFind\Db\Entity\UserEntityInterface Authenticated user entity
     */
    public function authenticate($request)
    {
        $username = trim($request->getPost()->get('username', ''));
        $password = trim($request->getPost()->get('password', ''));
        $college = trim($request->getPost()->get('institution', ''));
        if ($username == '' || $password == '' || $college == '') {
            throw new AuthException('authentication_error_blank');
        }

        $loginData = [
            'barcode' => $username,
            'pin' => $password,
        ];
        $validationUrl = $this->getPatronValidationUrl($college);
        $this->debug('Patron validation request: POST ' . $validationUrl);
        $ch = curl_init($validationUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($loginData));
        curl_setopt($ch, CURLOPT_TIMEOUT, (int)$this->reShareConfig['API']['http_timeout']);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type:application/json']);
        $response = curl_exec($ch);
        $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $effectiveUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $curlError = curl_error($ch);
        $responseLength = is_string($response) ? strlen($response) : 0;
        $this->debug(
            sprintf(
                'Patron validation response: POST %s returned HTTP %d; '
                . 'content type: %s; response bytes: %d%s',
                $effectiveUrl ?: $validationUrl,
                $httpStatus,
                $contentType ?: 'unknown',
                $responseLength,
                $curlError === '' ? '' : '; cURL error: ' . $curlError
            )
        );

        curl_close($ch);

        if ($httpStatus === 200) {
            $user = $this->processReShareUser($username, $password, $college);
        } else {
            throw new AuthException('authentication_error_invalid');
        }

        return $user;
    }

    /**
     * Store the authenticated ReShare user.
     *
     * @param string $username The user's ILS username
     * @param string $password The user's ILS password
     * @param string $college  The user's ReShare institution
     *
     * @return \VuFind\Db\Entity\UserEntityInterface Processed user entity
     */
    protected function processReShareUser($username, $password, $college)
    {
        $user = $this->userService->getUserByUsername($username)
            ?? $this->userService->createEntityForUsername($username);
        $user->setCatUsername($username);
        $user->setCollege($college);
        $this->userService->persistEntity($user);
        $this->ilsAuthenticator->saveUserCatalogCredentials(
            $user,
            $username,
            $password
        );
        return $user;
    }

    /**
     * Get the URL to redirect to after logout.
     *
     * @param string $url VuFind's default logout destination
     *
     * @return string
     */
    public function getLogoutRedirectUrl(string $url): string
    {
        return !empty($this->reShareConfig['Site']['logout_url'])
            ? $this->reShareConfig['Site']['logout_url']
            : $url;
    }

}
