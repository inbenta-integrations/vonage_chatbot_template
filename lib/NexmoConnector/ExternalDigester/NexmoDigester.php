<?php

namespace Inbenta\NexmoConnector\ExternalDigester;

use \Exception;
use Inbenta\ChatbotConnector\ExternalDigester\Channels\DigesterInterface;


class NexmoDigester extends DigesterInterface
{

    protected $conf;
    protected $channel;
    protected $session;
    protected $langManager;
    protected $externalMessageTypes = array(
        'text',
        'attachment',
        'location'
    );

    public function __construct($langManager, $conf, $session)
    {
        $this->langManager = $langManager;
        $this->channel = 'Nexmo';
        $this->conf = $conf;
        $this->session = $session;
    }

    /**
     *	Returns the name of the channel
     */
    public function getChannel()
    {
        return $this->channel;
    }

    /**
     **	Checks if a request belongs to the digester channel
     **/
    public static function checkRequest($request)
    {
        // TODO check Request
        // It is no being used
        $request = json_decode($request);
        $isPage    = isset($request->object) && $request->object == 'page';
        $isMessaging = isset($request->entry) && isset($request->entry[0]) && isset($request->entry[0]->messaging);
        if ($isPage && $isMessaging && count((array) $request->entry[0]->messaging)) {
            return true;
        }
        return false;
    }

    /**
     **	Formats a channel request into an Inbenta Chatbot API request
     **/
    public function digestToApi($request)
    {
        $request = json_decode($request);
        if (is_null($request) || !isset($request->message)) {
            return [];
        }

        $messages[] = $request;
        $output = [];

        foreach ($messages as $msg) {
            $msgType = $this->checkExternalMessageType($msg);
            $digester = 'digestFromNexmo' . ucfirst($msgType);

            //Check if there are more than one responses from one incoming message
            $digestedMessage = $this->$digester($msg);
            if (isset($digestedMessage['multiple_output'])) {
                foreach ($digestedMessage['multiple_output'] as $message) {
                    $output[] = $message;
                }
            } else {
                $output[] = $digestedMessage;
            }
        }
        return $output;
    }

    /**
     **	Formats an Inbenta Chatbot API response into a channel request
     **/
    public function digestFromApi($request, $lastUserQuestion = '')
    {
        //Parse request messages
        if (isset($request->answers) && is_array($request->answers)) {
            $messages = $request->answers;
        } elseif ($this->checkApiMessageType($request) !== null) {
            $messages = array('answers' => $request);
        } else {
            throw new Exception("Unknown ChatbotAPI response: " . json_encode($request, true));
        }

        $output = [];
        foreach ($messages as $msg) {
            if (!isset($msg->message) || $msg->message === "") continue;
            $msgType = $this->checkApiMessageType($msg);
            $digester = 'digestFromApi' . ucfirst($msgType);
            $digestedMessage = $this->$digester($msg, $lastUserQuestion);

            //Check if there are more than one responses from one incoming message
            if (isset($digestedMessage['multiple_output'])) {
                foreach ($digestedMessage['multiple_output'] as $message) {
                    $output[] = $message;
                }
            } else {
                $output[] = $digestedMessage;
            }
        }
        return $output;
    }

    /**
     **	Classifies the external message into one of the defined $externalMessageTypes
     **/
    protected function checkExternalMessageType($message)
    {
        foreach ($this->externalMessageTypes as $type) {
            $checker = 'isNexmo' . ucfirst($type);

            if ($this->$checker($message)) {
                return $type;
            }
        }
        throw new Exception('Unknown Nexmo message type');
    }

    /**
     **	Classifies the API message into one of the defined $apiMessageTypes
     **/
    protected function checkApiMessageType($message)
    {
        foreach ($this->apiMessageTypes as $type) {
            $checker = 'isApi' . ucfirst($type);

            if ($this->$checker($message)) {
                return $type;
            }
        }
        return null;
    }

    /********************** EXTERNAL MESSAGE TYPE CHECKERS **********************/
    // TODO Type chek
    protected function isNexmoText($message)
    {
        $isText = isset($message->message)
            && isset($message->message->content)
            && isset($message->message->content->type)
            && $message->message->content->type == 'text'
            ? true : false;
        return $isText;
    }

    protected function isNexmoAttachment($message)
    {
        $isAttachment = false;
        if (
            isset($message->message)
            && isset($message->message->content)
            && isset($message->message->content->type)
        ) {
            switch ($message->message->content->type) {
                case 'image':
                case 'audio':
                case 'video':
                case 'file':
                    $isAttachment = true;
                    break;
            }
        }
        return $isAttachment;
    }

    protected function isNexmoLocation($message)
    {
        $isLocation = isset($message->message)
            && isset($message->message->content)
            && isset($message->message->content->type)
            && $message->message->content->type == 'location'
            ? true : false;
        return $isLocation;
    }

    /********************** API MESSAGE TYPE CHECKERS **********************/

    protected function isApiAnswer($message)
    {
        return isset($message->type) && $message->type == 'answer';
    }

    protected function isApiPolarQuestion($message)
    {
        return isset($message->type) && $message->type == 'polarQuestion';
    }

    protected function isApiMultipleChoiceQuestion($message)
    {
        return isset($message->type) && $message->type == 'multipleChoiceQuestion';
    }

    protected function isApiExtendedContentsAnswer($message)
    {
        return isset($message->type) && $message->type == 'extendedContentsAnswer';
    }

    protected function hasTextMessage($message)
    {
        return isset($message->message) && is_string($message->message);
    }


    /********************** NEXMO MESSAGE DIGESTERS **********************/

    protected function digestFromNexmoText($message)
    {
        return array(
            'message' => $message->message->content->text
        );
    }

    protected function digestFromNexmoAttachment($message)
    {
        $type =  $message->message->content->type;
        return array(
            'message' => $message->message->content->$type->url
        );
    }
    protected function digestFromNexmoLocation($message)
    {
        $data = $message->message->content->location;
        if ($data->name) {
            $message = $data->name . ', ' . $data->address . ' ' . $data->url;
        } else {
            $message = 'https://www.google.com/maps/search/?api=1&query='.$data->lat.','.$data->long;
        }
        return array(
            'message' => $message
        );
    }

    /********************** CHATBOT API MESSAGE DIGESTERS **********************/

    protected function digestFromApiAnswer($message)
    {
        $output = array();
        $urlButtonSetting = isset($this->conf['url_buttons']['attribute_name'])
            ? $this->conf['url_buttons']['attribute_name']
            : '';

        if (strpos($message->message, '<img') !== false) {
            // Handle a message that contains an image (<img> tag)
            $output['multiple_output'] = $this->handleMessageWithImages($message);
        } elseif (isset($message->attributes->$urlButtonSetting) && !empty($message->attributes->$urlButtonSetting)) {
            // Send a button that opens an URL
            $output = $this->buildUrlButtonMessage($message, $message->attributes->$urlButtonSetting);
        } else {
            // Add simple text-answer
            $output = [
                'type' => 'text',
                'text' => trim(html_entity_decode(strip_tags($message->message), ENT_COMPAT, "UTF-8"))
            ];
        }
        return $output;
    }

    protected function digestFromApiPolarQuestion($message, $lastUserQuestion)
    {
        $response = ['type' => 'text', 'text' => $message->message];
        return $response;
    }

    protected function digestFromApiExtendedContentsAnswer($message)
    {
        $response['multiple_output'][] = ['type' => 'text', 'text' => $message->message];
        $this->session->set('federatedSubanswers', $message->subAnswers);
        foreach ($message->subAnswers as $index => $option) {
            $response['multiple_output'][] = ['type' => 'text', 'text' => $option->attributes->title];
        }
        return $response;
    }

    protected function digestFromApiMultipleChoiceQuestion($message, $lastUserQuestion)
    {
        $response['multiple_output'][] = ['type' => 'text', 'text' => $message->message];
        foreach ($message->options as $option) {
            $response['multiple_output'][] = ['type' => 'text', 'text' => $option->attributes->title];
        }


        return $response;
    }

    /********************** MISC **********************/

    public function buildContentRatingsMessage($ratingOptions, $rateCode)
    {

        $message = $this->langManager->translate('rate_content_intro');
        $response['multiple_output'][] = ['type' => 'text', 'text' => $message];

        foreach ($ratingOptions as $option) {
            $response['multiple_output'][] = ['type' => 'text', 'text' =>  $this->langManager->translate($option['label'])];
        }
        return $response;
    }

    /**
     *	Splits a message that contains an <img> tag into text/image/text and displays them in Nexmo
     */
    protected function handleMessageWithImages($message)
    {
        //Remove \t \n \r and HTML tags (keeping <img> tags)
        $text = str_replace(["\r\n", "\r", "\n", "\t"], '', strip_tags($message->message, "<img>"));
        //Capture all IMG tags
        preg_match_all('/<\s*img.*?src\s*=\s*"(.*?)".*?\s*\/?>/', $text, $matches, PREG_SET_ORDER, 0);
        $output = array();
        foreach ($matches as $imgData) {
            //Get the position of the img answer to split the message
            $imgPosition = strpos($text, $imgData[0]);

            // Append first text-part of the message to the answer
            $firstText = substr($text, 0, $imgPosition);
            if (strlen($firstText)) {
                $output[] = array('type' => 'text', 'text' => $firstText);
            }

            //Append the image to the answer
            $output[] = array(
                'type' => 'image',
                'image' => array(
                    'url' => $imgData[1]
                )
            );

            //Remove the <img> part from the input string
            $position = $imgPosition + strlen($imgData[0]);
            $text = substr($text, $position);
        }

        //Check if there is missing text inside message
        if (strlen($text)) {
            $output[] = array(
                'text' => $text
            );
        }
        return $output;
    }

    /**
     *	Sends the text answer and displays an URL button
     */
    protected function buildUrlButtonMessage($message, $urlButton)
    {
        $buttonTitleProp = $this->conf['url_buttons']['button_title_var'];
        $buttonURLProp = $this->conf['url_buttons']['button_url_var'];

        if (!is_array($urlButton)) {
            $urlButton = [$urlButton];
        }

        $buttons = array();
        foreach ($urlButton as $button) {
            // If any of the urlButtons has any invalid/missing url or title, abort and send a simple text message
            if (!isset($button->$buttonURLProp) || !isset($button->$buttonTitleProp) || empty($button->$buttonURLProp) || empty($button->$buttonTitleProp)) {
                return ['text' => trim(html_entity_decode(strip_tags($message->message), ENT_COMPAT, "UTF-8"))];
            }
            $buttons[] = [
                'type' => 'web_url',
                'url' => $button->$buttonURLProp,
                'title' => $button->$buttonTitleProp,
                'webview_height_ratio' => 'full'
            ];
        }

        return [
            'attachment' => [
                'type' => 'template',
                'payload' => [
                    'template_type' => 'button',
                    'text' => substr(trim(html_entity_decode(strip_tags($message->message), ENT_COMPAT, "UTF-8")), 0, 640),
                    'buttons' => $buttons
                ]
            ]
        ];
    }

    public function buildEscalationMessage()
    {
        $message = $this->langManager->translate('ask_to_escalate');
        return array(
            'type' => 'text',
            'text' => $message
        );
    }
}
