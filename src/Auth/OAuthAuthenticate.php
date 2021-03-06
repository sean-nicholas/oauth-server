<?php
namespace OAuthServer\Auth;

use Cake\Auth\BaseAuthenticate;
use Cake\Controller\ComponentRegistry;
use Cake\Database\Exception;
use Cake\Event\Event;
use Cake\Event\EventManager;
use Cake\Network\Exception\BadRequestException;
use Cake\Network\Request;
use Cake\Network\Response;
use Cake\ORM\TableRegistry;
use League\OAuth2\Server\Exception\OAuthException;
use League\OAuth2\Server\ResourceServer;
use OAuthServer\Model\Storage;

class OAuthAuthenticate extends BaseAuthenticate
{

    /**
     * @var \League\OAuth2\Server\ResourceServer
     */
    public $Server;

    /**
     * Exception that was thrown by oauth server
     *
     * @var \League\OAuth2\Server\Exception\OAuthException
     */
    protected $_exception;

    /**
     * @var array
     */
    protected $_defaultConfig = [
        'continue' => false,
    ];

    /**
     * @param \Cake\Controller\ComponentRegistry $registry Component registry
     * @param array $config Config array
     */
    public function __construct(ComponentRegistry $registry, $config)
    {
        parent::__construct($registry, $config);

        if ($this->config('server')) {
            $this->Server = $this->config('server');
        } else {
            $sessionStorage = new Storage\SessionStorage();
            $accessTokenStorage = new Storage\AccessTokenStorage();
            $clientStorage = new Storage\ClientStorage();
            $scopeStorage = new Storage\ScopeStorage();

            $server = new ResourceServer(
                $sessionStorage,
                $accessTokenStorage,
                $clientStorage,
                $scopeStorage
            );

            $this->Server = $server;
        }
    }

    /**
     * Authenticate a user based on the request information.
     *
     * @param \Cake\Network\Request $request Request to get authentication information from.
     * @param \Cake\Network\Response $response A response object that can have headers added.
     * @return bool
     */
    public function authenticate(Request $request, Response $response)
    {
        return false;
    }

    /**
     * @param \Cake\Network\Request $request Request to get authentication information from.
     * @param \Cake\Network\Response $response A response object that can have headers added.
     * @return bool|\Cake\Network\Response
     */
    public function unauthenticated(Request $request, Response $response)
    {
        if ($this->_config['continue']) {
            return false;
        }
        if (isset($this->_exception)) {
            $response->statusCode($this->_exception->httpStatusCode);

            //add : to http code for cakephp (header method in Network/Response expects header separated with colon notation)
            $headers = $this->_exception->getHttpHeaders();
            $code = (string)$this->_exception->httpStatusCode;
            $headers = array_map(function ($header) use ($code) {
                $pos = strpos($header, $code);
                if ($pos !== false) {
                    return substr($header, 0, $pos + strlen($code)) . ':' . substr($header, $pos + strlen($code) + 1);
                }

                return $header;
            }, $headers);
            $response->header($headers);

            $response->body(
                json_encode(
                    [
                        'error' => $this->_exception->errorType,
                        'message' => $this->_exception->getMessage()
                    ]
                )
            );
            return $response;
        }
        $message = __d('authenticate', 'You are not authenticated.');
        throw new BadRequestException($message);
    }

    /**
     * @param \Cake\Network\Request $request Request object
     * @return array|bool|mixed
     */
    public function getUser(Request $request)
    {
        try {
            $this->Server->isValidRequest(true, $request->query('access_token'));
        } catch (OAuthException $e) {
            $this->_exception = $e;
            return false;
        }
        $ownerModel = $this->Server
            ->getAccessToken()
            ->getSession()
            ->getOwnerType();
        $ownerId = $this->Server
            ->getAccessToken()
            ->getSession()
            ->getOwnerId();

        try {
            $owner = TableRegistry::get($ownerModel)
                ->get($ownerId)
                ->toArray();
        } catch (Exception $e) {
            $this->_exception = $e;
            $owner = null;
        }

        $event = new Event('OAuthServer.getUser', $request, [$ownerModel, $ownerId, $owner]);
        EventManager::instance()->dispatch($event);
        if ($event->result !== null) {
            return $event->result;
        } else {
            return $owner;
        }
    }
}
