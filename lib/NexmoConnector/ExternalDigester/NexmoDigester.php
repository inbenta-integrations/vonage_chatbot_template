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
    protected $attachableFormats = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'xls', 'xlsx', 'mp4', 'avi', 'mp3', 'aac'];

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
        if ($this->session->has('options')) {
            $lastUserQuestion = $this->session->get('lastUserQuestion');
            $options = $this->session->get('options');

            $this->session->delete('options');
            $this->session->delete('lastUserQuestion');
            $this->session->delete('hasRelatedContent');

            if (isset($request->message->content) && isset($request->message->content->type) && $request->message->content->type === "text") {
                $userMessage = $request->message->content->text;

                $selectedOption = false;
                $selectedOptionText = "";
                $isRelatedContent = false;
                $isListValues = false;
                $isPolar = false;
                $optionSelected = false;

                foreach ($options as $option) {
                    if (isset($option->list_values)) {
                        $isListValues = true;
                    } else if (isset($option->related_content)) {
                        $isRelatedContent = true;
                    } else if (isset($option->is_polar)) {
                        $isPolar = true;
                    }
                    if ($userMessage == $option->opt_key || strtolower($userMessage) == strtolower($option->label)) {
                        if ($isListValues || $isRelatedContent) {
                            $selectedOptionText = $option->label;
                        } else {
                            $selectedOption = $option;
                        }
                        $optionSelected = true;
                        break;
                    }
                }

                if (!$optionSelected) {
                    if ($isListValues) { //Set again options for variable
                        $this->session->set('options', $options);
                        $this->session->set('lastUserQuestion', $lastUserQuestion);
                    } else if ($isPolar) { //For polar, on wrong answer, goes for NO
                        $request->message->content->text = "No";
                    }
                }

                if ($selectedOption) {
                    $output[] = ['option' => $selectedOption->value];
                } else if ($selectedOptionText !== "") {
                    $output[] = ['message' => $selectedOptionText];
                } else {
                    $output[] = ['message' => $request->message->content->text];
                }
            }
        } else {
            foreach ($messages as $msg) {
                $msgType = $this->checkExternalMessageType($msg);
                $digestedMessage = [];
                switch ($msgType) {
                    case 'text':
                        $digestedMessage = $this->digestFromNexmoText($msg);
                        break;
                    case 'attachment':
                        $digestedMessage = $this->digestFromNexmoAttachment($msg);
                        break;
                    case 'location':
                        $digestedMessage = $this->digestFromNexmoLocation($msg);
                        break;
                }
                //Check if there are more than one responses from one incoming message
                if (isset($digestedMessage['multiple_output'])) {
                    foreach ($digestedMessage['multiple_output'] as $message) {
                        $output[] = $message;
                    }
                } else {
                    $output[] = $digestedMessage;
                }
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
        } elseif (!is_null($this->checkApiMessageType($request))) {
            $messages = array('answers' => $request);
        } else {
            throw new Exception("Unknown ChatbotAPI response: " . json_encode($request, true));
        }

        $output = [];
        foreach ($messages as $msg) {
            $msgType = $this->checkApiMessageType($msg);
            $digestedMessage = [];
            switch ($msgType) {
                case 'answer':
                    $digestedMessage = $this->digestFromApiAnswer($msg, $lastUserQuestion);
                    break;
                case 'polarQuestion':
                    $digestedMessage = $this->digestFromApiPolarQuestion($msg, $lastUserQuestion);
                    break;
                case 'multipleChoiceQuestion':
                    $digestedMessage = $this->digestFromApiMultipleChoiceQuestion($msg, $lastUserQuestion);
                    break;
                case 'extendedContentsAnswer':
                    $digestedMessage = $this->digestFromApiExtendedContentsAnswer($msg);
                    break;
            }
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
        $responseType = "";
        foreach ($this->externalMessageTypes as $type) {
            switch ($type) {
                case 'text':
                    $responseType = $this->isNexmoText($message) ? $type : "";
                    break;
                case 'attachment':
                    $responseType = $this->isNexmoAttachment($message) ? $type : "";
                    break;
                case 'location':
                    $responseType = $this->isNexmoLocation($message) ? $type : "";
                    break;
            }
            if ($responseType !== "") {
                return $responseType;
            }
        }
        throw new Exception('Unknown Nexmo message type');
    }

    /**
     **	Classifies the API message into one of the defined $apiMessageTypes
     **/
    protected function checkApiMessageType($message)
    {
        $responseType = null;
        foreach ($this->apiMessageTypes as $type) {
            switch ($type) {
                case 'answer':
                    $responseType = $this->isApiAnswer($message) ? $type : null;
                    break;
                case 'polarQuestion':
                    $responseType = $this->isApiPolarQuestion($message) ? $type : null;
                    break;
                case 'multipleChoiceQuestion':
                    $responseType = $this->isApiMultipleChoiceQuestion($message) ? $type : null;
                    break;
                case 'extendedContentsAnswer':
                    $responseType = $this->isApiExtendedContentsAnswer($message) ? $type : null;
                    break;
            }
            if (!is_null($responseType)) {
                return $responseType;
            }
        }
        throw new Exception("Unknown ChatbotAPI response");
    }

    /********************** EXTERNAL MESSAGE TYPE CHECKERS **********************/
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
            $message = 'https://www.google.com/maps/search/?api=1&query=' . $data->lat . ',' . $data->long;
        }
        return array(
            'message' => $message
        );
    }

    /********************** CHATBOT API MESSAGE DIGESTERS **********************/

    protected function digestFromApiAnswer($message, $lastUserQuestion)
    {
        $output = [];
        $urlButtonSetting = isset($this->conf['url_buttons']['attribute_name'])
            ? $this->conf['url_buttons']['attribute_name']
            : '';

        if (isset($message->attributes->$urlButtonSetting) && !empty($message->attributes->$urlButtonSetting)) {
            // Send a button that opens an URL
            $output = $this->buildUrlButtonMessage($message, $message->attributes->$urlButtonSetting);
        } else {
            $message_txt = $message->message;
            if (isset($message->attributes->SIDEBUBBLE_TEXT) && !empty($message->attributes->SIDEBUBBLE_TEXT)) {
                $message_txt .= "\n" . $message->attributes->SIDEBUBBLE_TEXT;
            }

            $output_tmp = [
                "multiple_output" => [
                    [
                        'type' => 'text',
                        'text' => $message_txt
                    ]
                ]
            ];

            $multiple = false;
            if (strpos($message_txt, '<img') !== false || strpos($message_txt, '<iframe') !== false) {
                $output_tmp = $this->handleMessageWithImgOrIframe($message_txt);
                $multiple = true;
            }

            $last_text_message = 0;
            foreach ($output_tmp["multiple_output"] as $key => $value) {
                if (isset($value['type']) && $value['type'] == 'text') {
                    $last_text_message = $key;
                }
            }
            foreach ($output_tmp["multiple_output"] as $key => $value) {
                if (isset($value['type']) && $value['type'] == 'text') {
                    if ($key == $last_text_message) { //Inserts at the end: related content or action field)
                        $this->handleMessageWithActionField($message, $value['text'], $lastUserQuestion);
                        $this->handleMessageWithRelatedContent($message, $value['text'], $lastUserQuestion);
                    }
                    $this->handleMessageWithLinks($value['text']);
                    $this->handleMessageWithTextFormat($value['text']);
                    $output_tmp["multiple_output"][$key]["text"] = $this->formatFinalMessage($value['text']);
                }
            }
            $output = $multiple ? $output_tmp : $output_tmp["multiple_output"][0];
        }
        return $output;
    }

    protected function digestFromApiPolarQuestion($message, $lastUserQuestion)
    {
        return $this->digestFromApiMultipleChoiceQuestion($message, $lastUserQuestion, true);
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

    protected function digestFromApiMultipleChoiceQuestion($message, $lastUserQuestion, $isPolar = false)
    {
        $output = [
            "type" => "text",
            "text" => $this->formatFinalMessage($message->message)
        ];

        $options = $message->options;
        foreach ($options as $i => &$option) {
            $option->opt_key = $i + 1;
            if (isset($option->attributes->title) && !$isPolar) {
                $option->title = $option->attributes->title;
            } else if ($isPolar) {
                $option->is_polar = true;
            }
            $output['text'] .= "\n" . $option->opt_key . ') ' . $option->label;
        }
        $this->session->set('options', $options);
        $this->session->set('lastUserQuestion', $lastUserQuestion);

        return $output;
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
     * Validate if the message has images or iframes
     */
    public function handleMessageWithImgOrIframe($message_txt)
    {
        $output = [];
        $images = [];
        $iframes = [];
        $output["multiple_output"] = [];
        if (strpos($message_txt, '<img') !== false) {
            $images = $this->handleMessageWithImages($message_txt);
            $message_txt = $images["text_carried"];
            unset($images["text_carried"]);
        }
        if (strpos($message_txt, '<iframe') !== false) {
            $iframes = $this->handleMessageWithIframe($message_txt);
        }
        foreach ($images as $image) {
            $output["multiple_output"][] = $image;
        }
        foreach ($iframes as $iframe) {
            $output["multiple_output"][] = $iframe;
        }
        return $output;
    }

    /**
     *	Splits a message that contains an <img> tag into text/image/text and displays them in Nexmo
     */
    protected function handleMessageWithImages($text)
    {
        //Capture all IMG tags
        preg_match_all('/<\s*img.*?src\s*=\s*"(.*?)".*?\s*\/?>/', $text, $matches, PREG_SET_ORDER, 0);
        $output = array();
        foreach ($matches as $key => $imgData) {
            //Get the position of the img answer to split the message
            $imgPosition = strpos($text, $imgData[0]);
            $firstTextPosition = 0;

            if ($imgPosition == 0) {
                $imgPosition = isset($matches[$key + 1]) ? strpos($text, $matches[$key + 1][0]) : strlen($text);
                $firstTextPosition = strlen($imgData[0]);
            }

            // Append first text-part of the message to the answer
            $firstText = substr($text, $firstTextPosition, $imgPosition);
            if (strlen($firstText)) {
                $output[] = [
                    'type' => 'text',
                    'text' => $firstText
                ];
            }

            //Append the image to the answer
            $output[] = [
                'type' => 'image',
                'image' => [
                    'url' => $imgData[1]
                ]
            ];

            //Remove the <img> part from the input string
            $position = $imgPosition + strlen($imgData[0]);
            $text = substr($text, $position);
        }

        //Check if there is missing text inside message
        if (strlen($text)) {
            $output[] = [
                'type' => 'text',
                'text' => $text
            ];
        }
        $output["text_carried"] = $text;
        return $output;
    }

    /**
     * Extracts the url from the iframe
     */
    private function handleMessageWithIframe($text)
    {
        //Capture all IMG tags and return an array with [text,imageURL,text,...]
        $parts = preg_split('/<\s*iframe.*?src\s*=\s*"(.+?)".*?\s*\/?>/', $text, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);
        $output = [];
        foreach ($parts as $part) {

            if (substr($part, 0, 4) == 'http') {
                $url_elements = explode(".", $part);
                $file_format = $url_elements[count($url_elements) - 1];
                if (in_array($file_format, $this->attachableFormats)) {
                    $type = 'file';
                    if (in_array($file_format, ['jpg', 'jpeg', 'png', 'gif'])) {
                        $type = 'image';
                    } else if (in_array($file_format, ['mp4', 'avi'])) {
                        $type = 'video';
                    } else if (in_array($file_format, ['mp3', 'aac'])) {
                        $type = 'audio';
                    }

                    $output[] = [
                        'type' => $type,
                        $type => [
                            'url' => $part
                        ]
                    ];
                } else {
                    $pos1 = strpos($text, "<iframe");
                    $pos2 = strpos($text, "</iframe>", $pos1);
                    $iframe = substr($text, $pos1, $pos2 - $pos1 + 9);
                    $output[] = [
                        'type' => 'text',
                        'text' => str_replace($iframe, "<a href='" . $part . "'></a>", $text)
                    ];
                }
            }
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

    /**
     * Validate if the message has action fields
     */
    private function handleMessageWithActionField($message, &$message_txt, $lastUserQuestion)
    {
        if (isset($message->actionField) && !empty($message->actionField)) {
            if ($message->actionField->fieldType === 'list') {
                $options = $this->handleMessageWithListValues($message->actionField->listValues, $lastUserQuestion);
                if ($options !== "") {
                    $message_txt .= " (type a number)";
                    $message_txt .= $options;
                }
            } else if ($message->actionField->fieldType === 'datePicker') {
                $message_txt .= " (date format: mm/dd/YYYY)";
            }
        }
    }

    /**
     * Validate if the message has related content and put like an option list
     */
    private function handleMessageWithRelatedContent($message, &$message_txt, $lastUserQuestion)
    {
        if (isset($message->parameters->contents->related->relatedContents) && !empty($message->parameters->contents->related->relatedContents)) {
            $message_txt .= "\r\n \r\n" . $message->parameters->contents->related->relatedTitle . " (type a number)";

            $options = [];
            $optionList = "";
            foreach ($message->parameters->contents->related->relatedContents as $key => $relatedContent) {
                $options[$key] = (object) [];
                $options[$key]->opt_key = $key + 1;
                $options[$key]->related_content = true;
                $options[$key]->label = $relatedContent->title;
                $optionList .= "\n\n" . ($key + 1) . ') ' . $relatedContent->title;
            }
            if ($optionList !== "") {
                $message_txt .= $optionList;
                $this->session->set('hasRelatedContent', true);
                $this->session->set('options', (object) $options);
                $this->session->set('lastUserQuestion', $lastUserQuestion);
            }
        }
    }

    /**
     * Remove the common html tags from the message and set the final message
     */
    public function formatFinalMessage($message)
    {
        $message = str_replace('&nbsp;', ' ', $message);
        $message = str_replace(["\t"], '', $message);

        $breaks = array("<br />", "<br>", "<br/>", "<p>");
        $message = str_ireplace($breaks, "\n", $message);

        $message = strip_tags($message);

        $rows = explode("\n", $message);
        $message_processed = "";
        $previous_jump = 0;
        foreach ($rows as $row) {
            if ($row == "" && $previous_jump == 0) {
                $previous_jump++;
            } else if ($row == "" && $previous_jump == 1) {
                $previous_jump++;
                $message_processed .= "\r\n";
            }
            if ($row !== "") {
                $message_processed .= $row . "\r\n";
                $previous_jump = 0;
            }
        }
        $message_processed = str_replace("  ", " ", $message_processed);
        return $message_processed;
    }

    /**
     * Set the options for message with list values
     */
    protected function handleMessageWithListValues($listValues, $lastUserQuestion)
    {
        $optionList = "";
        $options = $listValues->values;
        foreach ($options as $i => &$option) {
            $option->opt_key = $i + 1;
            $option->list_values = true;
            $option->label = $option->option;
            $optionList .= "\n" . $option->opt_key . ') ' . $option->label;
        }
        if ($optionList !== "") {
            $this->session->set('options', $options);
            $this->session->set('lastUserQuestion', $lastUserQuestion);
        }
        return $optionList;
    }

    /**
     * Format the link as part of the message
     */
    public function handleMessageWithLinks(&$message_txt)
    {
        if ($message_txt !== "") {
            $dom = new \DOMDocument();
            @$dom->loadHTML($message_txt);
            $nodes = $dom->getElementsByTagName('a');

            $urls = [];
            $value = [];
            foreach ($nodes as $node) {
                $urls[] = $node->getAttribute('href');
                $value[] = trim($node->nodeValue);
            }

            if (strpos($message_txt, '<a ') !== false && count($urls) > 0) {
                $count_links = substr_count($message_txt, "<a ");
                $last_position = 0;
                for ($i = 0; $i < $count_links; $i++) {
                    $first_position = strpos($message_txt, "<a ", $last_position);
                    $last_position = strpos($message_txt, "</a>", $first_position);

                    if (isset($urls[$i]) && $last_position > 0) {
                        $a_tag = substr($message_txt, $first_position, $last_position - $first_position + 4);
                        $text_to_replace = $value[$i] !== "" ? $value[$i] . " (" . $urls[$i] . ")" : $urls[$i];
                        $message_txt = str_replace($a_tag, $text_to_replace, $message_txt);
                    }
                }
            }
        }
    }

    /**
     * Format the text if is bold, italic or strikethrough
     */
    public function handleMessageWithTextFormat(&$message_txt)
    {
        $tagsAccepted = ['strong', 'b', 'em', 's'];
        foreach ($tagsAccepted as $tag) {
            if (strpos($message_txt, '<' . $tag . '>') !== false) {

                $replace_char = "*"; //*bold*
                if ($tag === "em") $replace_char = "_"; //_italic_
                else if ($tag === "s") $replace_char = "~"; //~strikethrough~

                $count_tags = substr_count($message_txt, "<" . $tag . ">");

                $last_position = 0;
                $tag_array = [];
                for ($i = 0; $i < $count_tags; $i++) {
                    $first_position = strpos($message_txt, "<" . $tag . ">", $last_position);
                    $last_position = strpos($message_txt, "</" . $tag . ">", $first_position);
                    if ($last_position > 0) {
                        $tag_length = strlen($tag) + 3;
                        $tag_array[] = substr($message_txt, $first_position, $last_position - $first_position + $tag_length);
                    }
                }
                foreach ($tag_array as $old_tag) {
                    $new_tag = str_replace("<" . $tag . ">", "", $old_tag);
                    $new_tag = str_replace("</" . $tag . ">", "", $new_tag);
                    $new_tag = $replace_char . trim($new_tag) . $replace_char . " ";
                    $message_txt = str_replace($old_tag, $new_tag, $message_txt);
                }
            }
        }
    }
}
