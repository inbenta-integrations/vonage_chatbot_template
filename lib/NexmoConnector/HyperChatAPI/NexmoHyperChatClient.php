<?php

namespace Inbenta\NexmoConnector\HyperChatAPI;

use Inbenta\ChatbotConnector\HyperChatAPI\HyperChatClient;
use Inbenta\NexmoConnector\ExternalAPI\NexmoAPIClient;

class NexmoHyperChatClient extends HyperChatClient
{
	private $eventHandlers = array();
	private $session;

	function __construct($config, $lang, $session, $appConf, $externalClient)
	{
		// CUSTOM added session attribute to clear it
		$this->session = $session;
		parent::__construct($config, $lang, $session, $appConf, $externalClient);
	}
	//Instances an external client
	protected function instanceExternalClient($externalId, $appConf)
	{
		$userNumber = NexmoAPIClient::getUserNumberFromExternalId($externalId);
		if (is_null($userNumber)) {
			return null;
		}
		$companyNumber = NexmoAPIClient::getCompanyNumberFromExternalId($externalId);
		if (is_null($companyNumber)) {
			return null;
		}
		$externalClient = new NexmoAPIClient($appConf->get('nexmo.auth'));
		if ($appConf->get('sandbox.force_sandbox_mode')) {
			$externalClient->setEndpoint($appConf->get('sandbox.endpoint'));
		}
		$externalClient->setSenderFromId($companyNumber, $userNumber);
		return $externalClient;
	}

	public static function buildExternalIdFromRequest($config)
	{
		$request = json_decode(file_get_contents('php://input'), true);

		$externalId = null;
		if (isset($request['trigger'])) {
			//Obtain user external id from the chat event
			$externalId = self::getExternalIdFromEvent($config, $request);
		}
		return $externalId;
	}

	/**
	 * Overwirtten method to add system:info case
	 * Handle an incoming event and perform the required logic
	 */
	public function handleEvent()
	{
		// listen for a webhook handshake call
		if ($this->webhookHandshake() === true) {
			return;
		}

		// get event data
		$event = json_decode(file_get_contents('php://input'), true);
		if (
			!empty($event) &&
			isset($event['trigger']) &&
			!empty($event['data'])
		) {
			$eventData = $event['data'];

			// if the event trigger has a custom handler defined, execute this one
			if (in_array($event['trigger'], array_keys($this->eventHandlers))) {
				$handler = $this->eventHandlers[$event['trigger']];
				return $handler($event);
			}
			// or respond with the default logic depending on the event type
			switch ($event['trigger']) {
				case 'messages:new':
					if (empty($eventData['message'])) {
						return;
					}
					$messageData = $eventData['message'];

					$chat = $this->getChatInfo($messageData['chat']);
					if (!$chat || $chat->source !== $this->config->get('source')) {
						return;
					}
					$sender = $this->getUserInfo($messageData['sender']);
					if (!empty($sender->providerId)) {
						$targetUser = $this->getUserInfo($chat->creator);

						if ($messageData['type'] === 'media') {
							$fullUrl = $this->getContentUrl($messageData['message']['url']);
							$messageData['message']['fullUrl'] = $fullUrl;
							$messageData['message']['contentBase64'] =
								'data:' . $messageData['message']['type'] . ';base64,' .
								base64_encode(file_get_contents($fullUrl));
						}

						// send message
						$this->extService->sendMessageFromAgent(
							$chat,
							$targetUser,
							$sender,
							$messageData,
							$event['created_at']
						);
					}

					break;

				case 'chats:close':
					$chat = $this->getChatInfo($eventData['chatId']);

					if (!$chat || $chat->source !== $this->config->get('source')) {
						return;
					}

					$userId = $eventData['userId'];
					$isSystem = ($userId === 'system') ? true : false;
					$user = !$isSystem ? $this->getUserById($eventData['userId']) : null;

					if (($user && !empty($user->providerId)) || $isSystem) {
						$targetUser = $this->getUserInfo($chat->creator);
						$extChatId = $chat->externalId;
						// notify chat close
						$attended = true;
						$this->extService->notifyChatClose(
							$chat,
							$targetUser,
							$isSystem,
							$attended,
							!$isSystem ? $user : null
						);
					}

					break;
				case 'invitations:new':

					break;
				case 'invitations:accept':

					$chat = $this->getChatInfo($eventData['chatId']);

					if (!$chat || $chat->source !== $this->config->get('source')) {
						return;
					}

					$agent = $this->getUserById($eventData['userId']);
					$targetUser = $this->getUserInfo($chat->creator);
					$this->extService->notifyChatStart($chat, $targetUser, $agent);
					$this->session->set('chatInvitationAccepted', true);
					break;

				case 'users:activity':
					$chat = $this->getChatInfo($eventData['chatId']);

					if (!$chat || $chat->source !== $this->config->get('source')) {
						return;
					}

					$targetUser = $this->getUserInfo($chat->creator);

					switch ($eventData['type']) {
						case 'not-writing':
							$this->extService->sendTypingPaused($chat, $targetUser);
							break;
						case 'writing':
							$this->extService->sendTypingActive($chat, $targetUser);
							break;
						default:
							$this->extService->sendTypingPaused($chat, $targetUser);
							break;
					}

					break;

				case 'forever:alone':
					$chat = $this->getChatInfo($event['data']['chatId']);

					if (!$chat || $chat->source !== $this->config->get('source')) {
						return;
					}

					$targetUser = $this->getUserInfo($chat->creator);

					// close chat on server
					$this->api->chats->close($chat->id, array('secret' => $this->config->get('secret')));

					$system = true;
					$attended = false;
					$this->extService->notifyChatClose($chat, $targetUser, $system, $attended);

					break;
				case 'system:info': // CUSTOM case
					$this->attachSurveyToTicket($event);
					break;

				case 'queues:update':
					$chat = $this->getChatInfo($eventData['chatId']);
					if (!$chat || $chat->source !== $this->config->get('source')) {
						return;
					}
					$user = $this->getUserInfo($eventData['userId']);
					$data = $eventData['data'];
					$this->extService->notifyQueueUpdate($chat, $user, $data);
			}
		}
	}

	/**
	 * Overwritten method to allow use it
	 * Perform webhook handshake (only executed on the webhook setup request)
	 * @return void
	 */
	private function webhookHandshake()
	{
		if (isset($_SERVER['HTTP_X_HOOK_SECRET'])) {
			// get the webhook secret
			$xHookSecret = $_SERVER['HTTP_X_HOOK_SECRET'];
			// set response header
			header('X-Hook-Secret: ' . $xHookSecret);
			// set response status code
			http_response_code(200);
			return true;
		}
		return false;
	}

	/**
	 * Overwritten method to add email and extra info data
	 * Signup a new user or update his/her data if it already exists.
	 * @param  array    $userData
	 * @return object
	 */
	protected function signupOrUpdateUser($userData)
	{
		$user = null;

		$requestBody = array(
			'name' => $userData['name'],
		);

		if (!empty($userData['externalId'])) {
			$requestBody['externalId'] = $userData['externalId'];
		}
		if (!empty($userData['extraInfo'])) {
			$requestBody['extraInfo'] = (object) $userData['extraInfo'];
		}
		/*********** CUSTOM ***********/
		if (!empty($userData['contact'])) {
			$requestBody['contact'] = $userData['contact'];
		}
		/*********** CUSTOM ***********/
		$response = $this->api->users->signup($requestBody);
		// if a user with the same externalId already existed, just update its data
		if (isset($response->error)) {
			if ($response->error->code === self::USER_ALREADY_EXISTS) {
				$user = $this->getUserByExternalId($requestBody['externalId']);
				/*********** CUSTOM ***********/
				$result = $this->updateUser($user->id, $requestBody);
				/*********** CUSTOM ***********/
				$user = $result ? $result : $user;
			} else {
				return false;
			}
		} else {
			$user = $response->user;
		}

		return $user;
	}

	/**
	 * Overwritten function to update all user data
	 * Update a user's data
	 * @param  string $userId
	 * @param  array  $data   Data to update
	 * @return object         User's new data
	 */
	protected function updateUser($userId, $data = null)
	{
		$payload = ['secret' => $this->config->get('secret')];
		$requestTrigger = false;
		if (isset($data['extraInfo'])) {
			$payload['extraInfo'] = $data['extraInfo'];
			$requestTrigger = true;
		}
		if (isset($data['name'])) {
			$payload['name'] = $data['name'];
			$requestTrigger = true;
		}
		if (isset($data['contact'])) {
			$payload['contact'] = $data['contact'];
			$requestTrigger = true;
		}
		if (!$requestTrigger) {
			return false;
		}
		$response = $this->api->users->update($userId, $payload);
		return (isset($response->user)) ? $response->user : false;
	}
	/**
	 * Attach a survey to the ticket
	 *
	 * @param Array $event HyperChat system:info event
	 *
	 * @return void
	 */
	protected function attachSurveyToTicket($event)
	{
		$ticketId = $event['data']['data']['ticketId'];
		$surveyId = $this->config->get('surveyId');

		// Only send the survey if it's properly configured
		if ($surveyId !== '' && $surveyId !== null) {
			$response = $this->api->get(
				'surveys/' . $surveyId,
				[
					'secret' => $this->config->get('secret'),
				],
				[
					'sourceType' => 'ticket',
					'sourceId' => $ticketId
				]
			);
			// Send the survey URL to the user
			$this->extService->sendMessageFromSystem(null, null, $response->survey->url, null);
		}
		// Clear chatbot session when chat is closed
		$this->session->clear();
	}

	/**
	 * Debugging
	 *
	 */
	public function sendMessage($data)
	{
		// Get the userId for later usage
		$user = $this->getUserByExternalId($data['user']['externalId']);
		if (is_null($user)) {
			return (object) ['error' => 'User does not exist'];
		}

		$chat = $this->getActiveChat($user);
		if (is_null($chat)) {
			return (object) ['error' => 'Chat does not exist, `openChat` first.'];
		}
		// Send a message to the chat
		$response = $this->api->chats->sendMessage($chat->id, [
			'secret' => $this->config->get('secret'),
			'chatId' => $chat->id,
			'sender' => $user->id,
			'message' => $data['message']
		]);

		if (isset($response->error)) {
			return (object) ['error' => 'Sending message failed. ' . $response->error->message];
		}
		return $response;
	}
}
