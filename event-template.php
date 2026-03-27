<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function esn_custom_tribe_events_template( $template ) {
    return [
        [
            'core/group',
            [
                'align' => 'wide',
                'lock' => [
                    'move'   => true,
                    'remove' => true,
                ],
                'layout' => [
                    'type' => 'flex',
                    'orientation' => 'vertical',
                    'verticalAlignment' => 'top',
                    'justifyContent' => 'stretch',
                ],
            ],
            [
                [
                    'core/group',
                    [
                        'layout' => [
                            'type' => 'constrained',
                            'justifyContent' => 'left',
                        ],
                    ],
                    [
                        [ 
                            'tribe/event-datetime', 
                            [ 
                                'showTimeZone' => true,
                                'lock' => [ 'move' => true, 'remove' => true ] 
                            ] 
                        ],
                        [ 
                            'core/heading', 
                            [
                                'level' => 4, 
                                'placeholder' => 'Event Title....',
                                'lock' => [ 'move' => true, 'remove' => true ]
                            ]
                        ],
                        [ 
                            'core/paragraph', 
                            [ 'placeholder' => 'Event Info....' ] 
                        ],
                    ],
                ],
                [
                    'core/group',
                    [
                        'layout' => [
                            'type' => 'flex',
                            'flexWrap' => 'nowrap',
                            'justifyContent' => 'space-between',
                            'verticalAlignment' => 'center',
                        ],
                    ],
                    [
                        [ 'core/paragraph', [ 'placeholder' => 'Content goes here....' ] ],
                        [ 
                            'tribe/event-venue', 
                            [
                                'lock' => [ 'move' => true, 'remove' => true ]
                            ]
                        ],
                    ],
                ],
            ],
        ],
    ];
}
add_filter( 'tribe_events_editor_default_template', 'esn_custom_tribe_events_template' );
