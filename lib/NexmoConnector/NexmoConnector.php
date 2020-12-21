<?php

namespace Inbenta\NexmoConnector;

use Exception;
use Inbenta\ChatbotConnector\ChatbotConnector;
use Inbenta\ChatbotConnector\Utils\SessionManager;
use Inbenta\ChatbotConnector\ChatbotAPI\ChatbotAPIClient;
use Inbenta\NexmoConnector\ExternalAPI\NexmoAPIClient;
use Inbenta\NexmoConnector\ExternalDigester\NexmoDigester;
use Inbenta\NexmoConnector\HyperChatAPI\NexmoHyperChatClient;
use \Firebase\JWT\JWT; // https://github.com/firebase/php-jwt
use Spatie\OpeningHours\OpeningHours;
use Inbenta\NexmoConnector\Helpers\Helper;


class NexmoConnector extends ChatbotConnector
{

    public function __construct($appPath)
    {
        // Initialize and configure specific components for Nexmo
        try {
            parent::__construct($appPath);
            // Initialize base components
            $request = file_get_contents('php://input');
            $conversationConf = array('configuration' => $this->conf->get('conversation.default'), 'userType' => $this->conf->get('conversation.user_type'), 'environment' => $this->environment);
            $this->session = new SessionManager($this->getExternalIdFromRequest());
            $this->botClient     = new ChatbotAPIClient($this->conf->get('api.key'), $this->conf->get('api.secret'), $this->session, $conversationConf);

            $requestDecode = json_decode($request);
            if (isset($requestDecode->message_uuid)) {
                //Prevent double request from Vonage
                if ($this->session->get('last_message_uuid', "") !== "" && $this->session->get('last_message_uuid', "") === $requestDecode->message_uuid) {
                    die;
                }
                $this->session->set('last_message_uuid', $requestDecode->message_uuid);
            }

            // Retrieve Nexmo tokens from ExtraInfo and update configuration
            $this->getTokensFromExtraInfo();
            // Try to get the translations from ExtraInfo and update the language manager
            $this->getTranslationsFromExtraInfo('nexmo', 'translations');
            // Initialize Hyperchat events handler
            if ($this->conf->get('chat.chat.enabled') && ($this->session->get('chatOnGoing', false) || isset($_SERVER['HTTP_X_HOOK_SECRET']))) {
                $chatEventsHandler = new NexmoHyperChatClient($this->conf->get('chat.chat'), $this->lang, $this->session, $this->conf, $this->externalClient);
                $chatEventsHandler->handleChatEvent();
            }
            // Instance application components
            $externalClient         = new NexmoAPIClient($this->conf->get('nexmo.auth'), $request); // Instance Nexmo client
            $chatClient             = new NexmoHyperChatClient($this->conf->get('chat.chat'), $this->lang, $this->session, $this->conf, $externalClient);    // Instance HyperchatClient for Nexmo
            $externalDigester       = new NexmoDigester($this->lang, $this->conf->get('conversation.digester'), $this->session);                                                // Instance Nexmo digester
            // Change the Nexmo API endpoint if it is a Sandbox Env

            if ($this->isSandboxEnv()) {
                $externalClient->setEndpoint($this->conf->get('sandbox.endpoint'));
            }

            $this->initComponents($externalClient, $chatClient, $externalDigester);
        } catch (Exception $e) {
            echo json_encode(["error" => $e->getMessage()]);
            die();
        }
    }

    /**
     * Retrieve Nexmo tokens from ExtraInfo
     */
    protected function getTokensFromExtraInfo()
    {
        $tokens = [];
        $extraInfoData = $this->botClient->getExtraInfo('nexmo');

        foreach ($extraInfoData->results as $element) {
            $value = isset($element->value->value) ? $element->value->value : $element->value;
            $tokens[$element->name] = $value;
        }
        // Store tokens in conf
        $environment = $this->environment;
        if ($this->isSandboxEnv()) {
            $jwt = $tokens['app_tokens']->$environment[0]->JWT;
            $this->conf->set('nexmo.auth', 'Bearer ' . $jwt);
        } else {
            $key = $tokens['app_tokens']->$environment[0]->apiKey;
            $secret = $tokens['app_tokens']->$environment[0]->secretKey;
            $basic = base64_encode($key . ':' . $secret);
            $this->conf->set('nexmo.auth', 'Basic ' . $basic);
        }
    }

    /**
     * Return external id from request (Hyperchat of Nexmo)
     */
    protected function getExternalIdFromRequest()
    {
        // Try to get user_id from a Nexmo message request
        $externalId = NexmoAPIClient::buildExternalIdFromRequest();
        if (is_null($externalId)) {
            // Try to get user_id from a Hyperchat event request
            $externalId = NexmoHyperChatClient::buildExternalIdFromRequest($this->conf->get('chat.chat'));
        }
        if (empty($externalId)) {
            $api_key = $this->conf->get('api.key');
            if (isset($_SERVER['HTTP_X_HOOK_SECRET'])) {
                // Create a temporary session_id from a HyperChat webhook linking request
                $externalId = "hc-challenge-" . preg_replace("/[^A-Za-z0-9 ]/", '', $api_key);
            } else {
                throw new Exception("Invalid request");
                die();
            }
        }
        return $externalId;
    }
    /**
     * Return if the environment has been set as Sandbox
     *
     * @return boolean
     */
    protected function isSandboxEnv()
    {
        return $this->conf->get('sandbox.force_sandbox_mode');
    }

    /**
     * Return if only chat mode is active
     *
     * @return boolean
     */
    protected function isOnlyChat()
    {
        $onlyChatMode = false;
        $extraInfoData = $this->botClient->getExtraInfo('nexmo');
        // Get the settings data form extra info
        foreach ($extraInfoData->results as $element) {
            if ($element->name == 'settings') {
                $onlyChatMode = isset($element->value->only_chat_mode) && $element->value->only_chat_mode === 'true' ? true : false;
                break;
            }
        }
        return $onlyChatMode;
    }

    /**
     *	override useless facebook function from parent
     */
    protected function returnOkResponse()
    {
        return true;
    }

    /**
     * 	Display content rating message and its options
     */
    protected function displayContentRatings($rateCode)
    {
        $ratingOptions = $this->conf->get('conversation.content_ratings.ratings');
        $ratingMessage = $this->digester->buildContentRatingsMessage($ratingOptions, $rateCode);
        $this->session->set('askingRating', true);
        $this->session->set('rateCode', $rateCode);
        if (array_key_exists('multiple_output', $ratingMessage)) {
            foreach ($ratingMessage['multiple_output'] as $response) {
                $this->externalClient->sendMessage($response);
            }
        } else {
            $this->externalClient->sendMessage($ratingMessage);
        }
    }

    /**
     *	Check if it's needed to perform any action other than a standard user-bot interaction
     */
    protected function handleNonBotActions($digestedRequest)
    {
        $this->dieIfInvitationHasSent();
        // If there is a active chat, send messages to the agent
        if ($this->chatOnGoing()) {
            if ($this->isCloseChatCommand($digestedRequest)) {
                $chatData = array(
                    'roomId' => $this->conf->get('chat.chat.roomId'),
                    'user' => array(
                        'name' => $this->externalClient->getFullName(),
                        'contact' => $this->externalClient->getEmail(),
                        'externalId' => $this->externalClient->getExternalId(),
                        'extraInfo' => $this->externalClient->getExtraInfo()
                    )
                );
                define('APP_SECRET', $this->conf->get('chat.chat.secret'));
                $this->chatClient->closeChat($chatData);
                $this->externalClient->sendTextMessage($this->lang->translate('chat_closed'));
                $this->session->set('chatOnGoing', false);
            } else {
                $this->sendMessagesToChat($digestedRequest);
            }
            die();
        }
        // If user answered to an ask-to-escalate question, handle it
        if ($this->session->get('askingForEscalation', false)) {
            $this->handleEscalation($digestedRequest);
        }

        // CUSTOM If user answered to a rating question, handle it
        if ($this->session->get('askingRating', false)) {
            $this->handleRating($digestedRequest);
        }

        // If the bot offered Federated Bot options, handle its request
        if ($this->session->get('federatedSubanswers') && count($digestedRequest) && isset($digestedRequest[0]['message'])) {
            $selectedAnswer = $digestedRequest[0]['message'];
            $federatedSubanswers = $this->session->get('federatedSubanswers');
            $this->session->delete('federatedSubanswers');
            foreach ($federatedSubanswers as $answer) {
                if ($selectedAnswer === $answer->attributes->title) {
                    $this->displayFederatedBotAnswer($answer);
                    die();
                }
            }
        }
    }


    /**
     * Avoid send user requests to the API if an invitation has been sent but not accepted
     *
     * @return void
     */
    protected function dieIfInvitationHasSent()
    {
        // return true or false if it has been set or null if not
        $chatInvitationAccepted = $this->session->get('chatInvitationAccepted', null);
        // check if the variable has been set and if it's true
        if ($this->isOnlyChat() && !is_null($chatInvitationAccepted) && !$chatInvitationAccepted) {
            $this->externalClient->sendTextMessage($this->lang->translate('queue_warning'));
            die();
        }
    }

    /**
     * 	Ask the user if wants to talk with a human and handle the answer
     */
    protected function handleRating($userAnswer = null)
    {
        // Ask the user if wants to escalate

        // Handle user response to an rating question
        $this->session->set('askingRating', false);
        $ratingOptions = $this->conf->get('conversation.content_ratings.ratings');
        $ratingCode = $this->session->get('rateCode', false);
        $event = null;

        if (count($userAnswer) && isset($userAnswer[0]['message']) && $ratingCode) {
            foreach ($ratingOptions as $option) {
                if (Helper::removeAccentsToLower($userAnswer[0]['message']) === Helper::removeAccentsToLower($this->lang->translate($option['label']))) {
                    $event = $this->formatRatingEvent($ratingCode, $option['id']);
                }
            }
            // Rate if the answer was correct
            if ($event) {
                $this->sendEventToBot($event);
                $this->sendMessagesToExternal($this->buildTextMessage($this->lang->translate('thanks')));
                die;
            }
        }
    }

    /**
     * Return formated rate event
     *
     * @param String $ratingCode
     * @param Integer $ratingValue
     * @return Array
     */
    private function formatRatingEvent($ratingCode, $ratingValue, $comment = '')
    {
        return [
            'type' => 'rate',
            'data' => [
                'code' => $ratingCode,
                'value' => $ratingValue,
                'comment' => $comment
            ]
        ];
    }

    /**
     * 	Ask the user if wants to talk with a human and handle the answer
     */
    protected function handleEscalation($userAnswer = null)
    {
        // escalate if it has the form done
        $this->escalateIfFormHasBeenDone();

        // Ask the user if wants to escalate
        if (!$this->session->get('askingForEscalation', false)) {
            if ($this->checkAgents()) {
                // Ask the user if wants to escalate
                $this->session->set('askingForEscalation', true);
                $escalationMessage = $this->digester->buildEscalationMessage();
                $this->externalClient->sendMessage($escalationMessage);
            } else {
                // Send no-agents-available message if the escalation trigger is an API flag (user asked for having a chat explicitly)
                if ($this->session->get('escalationType') == static::ESCALATION_API_FLAG) {
                    $this->sendMessagesToExternal($this->buildTextMessage($this->lang->translate('no_agents')));
                }
                // Because no agents available, reduce the current escalation counter to escalate on next counter update
                $this->reduceCurrentEscalationCounter();
                $this->trackContactEvent("CONTACT_UNATTENDED");
            }
        } else {
            // Handle user response to an escalation question
            $this->session->set('askingForEscalation', false);
            // Reset escalation counters
            $this->session->set('noResultsCount', 0);
            $this->session->set('negativeRatingCount', 0);

            if ((count($userAnswer) && isset($userAnswer[0]['message']))) {

                if (Helper::removeAccentsToLower($userAnswer[0]['message']) === Helper::removeAccentsToLower($this->lang->translate('yes'))) {
                    $this->escalateToAgent();
                } else {
                    $this->sendMessagesToExternal($this->buildTextMessage($this->lang->translate('escalation_rejected')));
                    $this->trackContactEvent("CONTACT_REJECTED");
                }
                die();
            }
        }
    }
    /**
     * Escalate to an agent if the escalation form has been done
     *
     * @return void
     */
    public function escalateIfFormHasBeenDone()
    {
        $escalationFormData = $this->session->get('escalationForm', false);
        if ($escalationFormData) {
            if ($escalationFormData) {
                $this->externalClient->setFullName($escalationFormData->FIRST_NAME . ' ' . $escalationFormData->LAST_NAME);
                $this->externalClient->setEmail($escalationFormData->EMAIL_ADDRESS);
                $this->externalClient->setExtraInfo((array) $escalationFormData);
            }
            $this->escalateToAgent();
            die;
        }
    }

    private function isCloseChatCommand($userMessage)
    {
        return $userMessage[0]['message'] === $this->lang->translate('close_chat_key_word') ? true : false;
    }
    /**
     * Direct call to sys-welcome message to force escalation
     *
     * @param [type] $externalRequest
     * @return void
     */
    public function handleBotActions($externalRequest)
    {
        $needEscalation = false;
        $needContentRating = false;

        foreach ($externalRequest as $message) {
            // if the session just started throw sys-welcome message
            if ($this->isOnlyChat()) {
                if (!$this->session->get('lastUserQuestion')) {
                    // check the agents timetable
                    if ($this->checkServiceHours()) {
                        if ($this->checkAgents()) {
                            $this->escalateToAgent();
                        } else {
                            // throw no agents message
                            $this->sendMessagesToExternal($this->buildTextMessage($this->lang->translate('no_agents')));
                            $this->session->clear();
                            return false;
                        }
                    } else {
                        // throw out of time message
                        $this->sendMessagesToExternal($this->buildTextMessage($this->lang->translate('out_of_time')));
                        $this->session->clear();
                        return false;
                    }
                }
            }
            // Check if is needed to execute any preset 'command'
            $this->handleCommands($message);
            // Store the last user text message to session
            $this->saveLastTextMessage($message);
            // Check if is needed to ask for a rating comment
            $message = $this->checkContentRatingsComment($message);
            // Send the messages received from the external service to the ChatbotAPI
            $botResponse = $this->sendMessageToBot($message);
            // Check if escalation to agent is needed
            $needEscalation = $this->checkEscalation($botResponse) ? true : $needEscalation;
            // ONLY CHAT CUSTOM Clear session after escalate if the chatbot message returns a no-subset-match
            if ($this->isOnlyChat()) {
                $botResponse = $this->checkNoSubsetMatchAndResetSession($botResponse);
            }

            // Check if is needed to display content ratings
            $hasRating = $this->checkContentRatings($botResponse);
            $needContentRating = $hasRating ? $hasRating : $needContentRating;
            // Check if it has attached an escalation form
            $hasFormData = $this->checkEscalationForm($botResponse);
            // ONLY CHAT CUSTOM Clear session after escalate
            if ($this->isOnlyChat()) {
                $this->resetSessionAfterEscalation($botResponse);
            }
            // Send the messages received from ChatbotApi back to the external service
            $this->sendMessagesToExternal($botResponse);
        }
        if ($needEscalation || $hasFormData) {
            $this->handleEscalation();
        }
        // Display content rating if needed and not in chat nor asking to escalate
        if ($needContentRating && !$this->chatOnGoing() && !$this->session->get('askingForEscalation', false)) {
            $this->displayContentRatings($needContentRating);
        }
    }

    public function checkNoSubsetMatchAndResetSession($botResponse)
    {
        // Parse bot messages
        if (isset($botResponse->answers) && is_array($botResponse->answers)) {
            $messages = $botResponse->answers;
        } else {
            $messages = array($botResponse);
        }
        $response = $botResponse;
        // Check if BotApi returned 'escalate' flag on message or triesBeforeEscalation has been reached
        foreach ($messages as $msg) {
            if (isset($msg->flags) && in_array('no-subset-match', $msg->flags)) {
                $response->answers = array();
                $msg->flags = array();
                $response->answers[] = $msg;
                $this->session->clear();
            }
        }
        return $response;
    }

    public function resetSessionAfterEscalation($botResponse)
    {
        // Parse bot messages
        if (isset($botResponse->answers) && is_array($botResponse->answers)) {
            $messages = $botResponse->answers;
        } else {
            $messages = array($botResponse);
        }
        // Check if BotApi returned 'escalate' flag on message or triesBeforeEscalation has been reached
        foreach ($messages as $msg) {
            $this->updateNoResultsCount($msg);
            $resetSession  = isset($msg->attributes) &&  isset($msg->attributes->RESET_SESSION);
            if ($resetSession) {
                $this->session->clear();
            }
        }
        return false;
    }

    public function checkEscalationForm($botResponse)
    {
        // Parse bot messages
        if (isset($botResponse->answers) && is_array($botResponse->answers)) {
            $messages = $botResponse->answers;
        } else {
            $messages = array($botResponse);
        }
        // Check if BotApi returned 'escalate' flag on message or triesBeforeEscalation has been reached
        foreach ($messages as $msg) {
            $this->updateNoResultsCount($msg);
            $resetSession  = isset($msg->actions) && isset($msg->actions);
            if ($resetSession && $msg->actions[0]->parameters->callback == "escalateToAgent") {
                $data = $msg->actions[0]->parameters->data;
                $this->session->set('escalationForm', $data);
                return true;
            }
        }
        return false;
    }

    /**
     * Overwritten to add custom user data
     * 	Tries to start a chat with an agent
     */
    protected function escalateToAgent()
    {
        $agentsAvailable = $this->checkAgents();

        if ($agentsAvailable) {
            // Start chat
            $this->sendMessagesToExternal($this->buildTextMessage($this->lang->translate('creating_chat')));
            // Build user data for HyperChat API

            // CUSTOM
            $chatData = array(
                'roomId' => $this->conf->get('chat.chat.roomId'),
                'user' => array(
                    'name'             => $this->externalClient->getFullName(),
                    'contact'         => $this->externalClient->getEmail(),
                    'externalId'     => $this->externalClient->getExternalId(),
                    'extraInfo'     => $this->externalClient->getExtraInfo(),
                )
            );
            $response =  $this->chatClient->openChat($chatData);
            if (!isset($response->error) && isset($response->chat)) {
                $this->session->set('chatOnGoing', $response->chat->id);
                $this->session->set('chatInvitationAccepted', false);
                $this->trackContactEvent("CONTACT_ATTENDED");
            } else {
                $this->sendMessagesToExternal($this->buildTextMessage($this->lang->translate('error_creating_chat')));
            }
        } else {
            // Send no-agents-available message if the escalation trigger is an API flag (user asked for having a chat explicitly)
            if ($this->session->get('escalationType') == static::ESCALATION_API_FLAG) {
                $this->sendMessagesToExternal($this->buildTextMessage($this->lang->translate('no_agents')));
            }
            $this->trackContactEvent("CONTACT_UNATTENDED");
            $this->session->clear();
        }
    }

    /**
     * Check the Agents timetable
     *
     * @return boolean
     */
    public function checkServiceHours()
    {
        date_default_timezone_set('Europe/Madrid');
        $openingHours = OpeningHours::create($this->getServiceTimetable());
        return $openingHours->isOpen();
    }

    /**
     * Get the agents timetable from extra info or config file
     *
     * @return Array
     */
    public function getServiceTimetable()
    {
        $timetable = [];
        $extraInfoData = $this->botClient->getExtraInfo('nexmo');
        // Get the timetable data form extra info
        foreach ($extraInfoData->results as $element) {
            if ($element->name == 'timetable') {
                $timetable = json_decode(json_encode($element->value), true);
                foreach ($timetable as $key => $day) {
                    $timetable[$key] = array_values($day[0]);
                }
                break;
            }
        }
        // Use default settings if extra info data has not been set
        if (!count($timetable)) {
            $timetable = $this->conf->get('chat.chat.timetable');
        }
        return $timetable;
    }
}
