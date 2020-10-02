<?php

namespace Inbenta\NexmoConnector\ExternalAPI;

use Exception;
use GuzzleHttp\Client as Guzzle;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Request;


class NexmoAPIClient
{

    /**
     * The graph API URL.
     *
     * @var string
     */
    protected $endpoint = 'https://api.nexmo.com/v0.1/messages';


    /**
     * The Basic Base 64 or JWT auth.
     *
     * @var string|null
     */
    protected $auth;



    /**
     * The Nexmo user id.
     *
     * @var string|null
     */
    public $userPhoneNumber;

    /**
     * The Nexmo Aplication phone number.
     *
     * @var string|null
     */
    public $companyPhoneNumber;

    /**
     * The Nexmo user email.
     *
     * @var string|null
     */
    private $email;

    /**
     * The Nexmo user full name.
     *
     * @var string|null
     */
    private $fullName;

    /**
     * The HC form extra info.
     *
     * @var array|null
     */
    private $extraInfo;

    /**
     * API constructor
     *
     * @param String $auth
     * @param [type] $request
     */

    private $guzzle;

    public function __construct($auth = null, $request = null)
    {
        $this->auth = $auth;
        $this->email = null;
        $this->fullName = null;
        // Messages from Nexmo are json strings
        if (is_string($request)) {
            $request = json_decode($request);
            if (isset($request->to->number) && isset($request->from->number)) {
                //Save numbers data from Nexmo request
                $this->companyPhoneNumber = $request->to->number;
                $this->userPhoneNumber = $request->from->number;
            }
            // Messages from Hyperchat are arrays
        } else {
            return;
        }
    }
    /**
     * Override the default endpoint
     *
     * @param String $newEndpoint
     * @return void
     */
    public function setEndpoint($newEndpoint)
    {
        $this->endpoint = $newEndpoint;
    }

    /**
     * Send an outgoing message.
     *
     * @param array $message
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function send($message)
    {
        
        $response = $this->nexmo('POST', 'messages', [
            'json' => $message,
        ]);
        return $response;
    }

    /**
     * Send a request to the Nexmo Graph API.
     *
     * @param string $method
     * @param string $uri
     * @param array $options
     * @return ResponseInterface
     */
    protected function nexmo($method, $uri, array $options = [])
    {
        if (is_null($this->auth)) {
            throw new Exception('Auth system is not defined');
        }

        if (is_null($this->guzzle)) {
            $this->guzzle = new Guzzle([ 'base_uri' => $this->endpoint ]);
        }
        $response = $this->guzzle->request($method, $uri, array_merge_recursive($options, [
            'headers' => [
                'Authorization' => $this->auth,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ]
        ]));
        
        return $response;
    }

    /**
     * Establishes the Nexmo sender (user) directly with the provided phone numbers
     *
     * @param String $companyPhoneNumber
     * @param String $userPhoneNumber
     * @return void
     */
    public function setSenderFromId($companyPhoneNumber, $userPhoneNumber)
    {
        $this->companyPhoneNumber = $companyPhoneNumber;
        $this->userPhoneNumber = $userPhoneNumber;
    }

    /**
    *   Returns the full name or the user phone number as a full name
    *   @return String
    */
    public function getFullName()
    {
        return $this->fullName ? $this->fullName : $this->userPhoneNumber;
    }
    
    /**
    *   Returns the user email or a default email made with the external ID
    *   @return String
    */
    public function getEmail()
    {
        return $this->email ? $this->email : $this->getExternalId()."@nexmo-connector.com";
    }

    /**
    *   Returns the extra info data
    *   @return Array
    */
    public function getExtraInfo()
    {
        return $this->extraInfo;
    }

    /**
     * Set full name attribute
     *
     * @param String $fullName
     * @return void
     */
    public function setFullName($fullName) {
        $this->fullName = $fullName;
    }

    /**
     * Set extra info attributes
     *
     * @param Array $extraInfo
     * @return void
     */
    public function setExtraInfo($extraInfo) {
        $this->extraInfo = $extraInfo;
    }

    /**
     * Set email attribute
     *
     * @param String $email
     * @return void
     */
    public function setEmail($email) {
        $this->email = $email;
    }

    /**
     *   Generates the external id used by HyperChat to identify one user as external.
     *   This external id will be used by HyperChat adapter to instance this client class from the external id
     *   @return String external Id
     */
    public function getExternalId()
    {
        return 'nexmo-' . $this->companyPhoneNumber . '-' . $this->userPhoneNumber;
    }

    /**
     *   Retrieves the user number from the external ID generated by the getExternalId method
     *  @param String $externalId
     *  @return String|null user phone number or null
     */
    public static function getUserNumberFromExternalId($externalId)
    {
        $externalIdExploded = explode('-', $externalId);
        if (array_shift($externalIdExploded) == 'nexmo') {
            return $externalIdExploded[1];
        }
        return null;
    }

    /**
     *  Retrieves the company phone number from the external ID generated by the getExternalId method
     *  @param String $externalId
     *  @return String|null Company phone number or null
     */
    public static function getCompanyNumberFromExternalId($externalId)
    {
        $externalIdExploded = explode('-', $externalId);
        if (array_shift($externalIdExploded) == 'nexmo') {
            return $externalIdExploded[0];
        }
        return null;
    }


    /**
     * Build an external session Id using the following pattern:
     * provider_name-company_phone_number-user_phone_number
     * 
     * @return String|null
     */
    public static function buildExternalIdFromRequest()
    {
        $request = json_decode(file_get_contents('php://input'));
        if (isset($request->to->number) && isset($request->from->number)) {
            $companyNumber = $request->to->number;
            $userNumber = $request->from->number;
            return 'nexmo-' . $companyNumber . '-' . $userNumber;
        }
        return null;
    }

    /**
     * Sends a message to Nexmo. Needs a message formatted with the Nexmo notation
     *
     * @param  Array $message 
     * @return Psr\Http\Message\ResponseInterface $messageSend
     */
    public function sendMessage($message)
    {
        if (isset($message['text'])) {
            $message['text'] = trim(html_entity_decode(strip_tags($message['text']), ENT_COMPAT, "UTF-8"));
        }
        $messageSend = $this->send(
            [
                'from' => [
                    'type' => "whatsapp",
                    'number' => $this->companyPhoneNumber
                ],
                'to' => [
                    'type' => "whatsapp",
                    'number' => $this->userPhoneNumber
                ],
                'message' => [
                    'content' => $message
                ]
            ]
        );
        return $messageSend;
    }


    /**
     * Generates a text message from a string and sends it to Nexmo
     *
     * @param  String $text Text message
     * @return void
     */
    public function sendTextMessage($text)
    {
        $this->sendMessage(
            [
                'type' => 'text',
                'text' => trim(html_entity_decode(strip_tags($text), ENT_COMPAT, "UTF-8"))
            ]
        );
    }

    /**
    *   Method needed
    */
    public function showBotTyping($show = true)
    {
        return true;
    }

    /**
     * Generates a Nexmo attachment message from HyperChat message
     *
     * @param [type] $message
     * @return void
     */
    public function sendAttachmentMessageFromHyperChat($message)
    {
        $type = strpos($message['type'], 'image') !== false ? 'image' : 'file';
        $this->sendMessage(
            [
                'type' => $type,
                $type => [
                    'url' => $message['fullUrl']
                ]
            ]
        );
    }
}
