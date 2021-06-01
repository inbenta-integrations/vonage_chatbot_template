<?php

/**
 * DON'T MODIFY THIS FILE!!! READ 'conf/README.md' BEFORE.
 */

// Inbenta Chatbot configuration
return [
    'default' => [
        'answers' => [
            'sideBubbleAttributes'  => [],
            'answerAttributes'      => [
                'ANSWER_TEXT',
            ],
            'maxOptions'            => 3,
            'maxRelatedContents'    => 2,
            'skipLastCheckQuestion' => true
        ],
        'forms' => [
            'allowUserToAbandonForm'    => true,
            'errorRetries'              => 2
        ],
        'lang'  => 'en'
    ],
    'user_type' => 0,
    'source' => 'vonage',
    'content_ratings' => [     // Remember that these ratings need to be created in your instance
        'enabled' => false,
        'ratings' => [
            [
                'id' => 1,
                'label' => 'yes',
                'comment' => false,
                'isNegative' => false
            ],
            [
                'id' => 2,
                'label' => 'no',
                'comment' => true,   // Whether clicking this option should ask for a comment
                'isNegative' => true
            ]
        ]
    ],
    'digester' => [
        'button_title' => '',           // Provide the attribute that contains the custom content-title to be displayed in multiple options
        'url_buttons' => [
            'attribute_name'    => '',  // Provide the setting that contains an url+title to be displayed as URL button
            'button_title_var'  => '',  // Provide the property name that contains the button title in the button object
            'button_url_var'    => '',  // Provide the property name that contains the button URL in the button object
        ],
    ],
];
