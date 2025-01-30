<?

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    exit;
}
IncludeModuleLangFile(__FILE__);

class vacation
{
    public function GetName()
    {
        return GetMessage('BPT1_TTITLE');
    }

    public function GetVariables()
    {
        return [
            'ParameterOpRead' => [
                'Name' => GetMessage('BPT1_BT_PARAM_OP_READ'),
                'Description' => '',
                'Type' => 'S:UserID',
                'Required' => true,
                'Multiple' => true,
                'Default' => 'author',
            ],
            'ParameterOpCreate' => [
                'Name' => GetMessage('BPT1_BT_PARAM_OP_CREATE'),
                'Description' => '',
                'Type' => 'S:UserID',
                'Required' => true,
                'Multiple' => true,
                'Default' => 'author',
            ],
            'ParameterOpAdmin' => [
                'Name' => GetMessage('BPT1_BT_PARAM_OP_ADMIN'),
                'Description' => '',
                'Type' => 'S:UserID',
                'Required' => true,
                'Multiple' => true,
                'Default' => '',
            ],
            'ParameterBoss' => [
                'Name' => GetMessage('BPT1_BT_PARAM_BOSS'),
                'Description' => '',
                'Type' => 'S:UserID',
                'Required' => true,
                'Multiple' => true,
                'Default' => '',
            ],
            'ParameterBookkeeper' => [
                'Name' => GetMessage('BPT1_BT_PARAM_BOOK'),
                'Description' => '',
                'Type' => 'S:UserID',
                'Required' => true,
                'Multiple' => true,
                'Default' => '',
            ],
        ];
    }

    public function GetParameters()
    {
        return [
            'TargetUser' => [
                'Name' => GetMessage('BPT1_BT_P_TARGET'),
                'Description' => '',
                'Type' => 'S:UserID',
                'Required' => false,
                'Multiple' => false,
                'Default' => '',
            ],
            'date_start' => [
                'Name' => GetMessage('BPT1_BT_T_DATE_START'),
                'Description' => '',
                'Type' => 'S:DateTime',
                'Required' => true,
                'Multiple' => false,
                'Default' => '',
            ],
            'date_end' => [
                'Name' => GetMessage('BPT1_BT_T_DATE_END'),
                'Description' => '',
                'Type' => 'S:DateTime',
                'Required' => true,
                'Multiple' => false,
                'Default' => '',
            ],
        ];
    }

    public function GetTemplate()
    {
        return [
            [
                'Type' => 'SequentialWorkflowActivity',
                'Name' => 'Template',
                'Properties' => [
                    'Title' => GetMessage('BPT1_BT_SWA'),
                    'Permission' => ['read' => ['Variable', 'ParameterOpRead'], 'create' => ['Variable', 'ParameterOpCreate'], 'admin' => ['Variable', 'ParameterOpAdmin']],
                ],
                'Children' => [
                    [
                        'Type' => 'SetFieldActivity',
                        'Name' => 'A54792_44873_81417_17348',
                        'Properties' => [
                            'FieldValue' => [
                                'ACTIVE_FROM' => '{=Template:date_start}',
                                'ACTIVE_TO' => '{=Template:date_end}',
                                'NAME' => '{=Template:TargetUser_printable}, {=Template:date_start} - {=Template:date_end}',
                                'PROPERTY_approving' => 'x',
                            ],
                            'Title' => GetMessage('BPT1_BT_SFA1_TITLE'),
                        ],
                    ],
                    [
                        'Type' => 'SetStateTitleActivity',
                        'Name' => 'A99154_51391_34111_46585',
                        'Properties' => [
                            'TargetStateTitle' => GetMessage('BPT1_BT_STA1_STATE_TITLE'),
                            'Title' => GetMessage('BPT1_BT_STA1_TITLE'),
                        ],
                    ],
                    [
                        'Type' => 'WhileActivity',
                        'Name' => 'A65993_8943_32801_73040',
                        'Properties' => [
                            'Title' => GetMessage('BPT1_BT_CYCLE'),
                            'fieldcondition' => [['PROPERTY_approving', '=', 'x']],
                        ],
                        'Children' => [
                            [
                                'Type' => 'SequenceActivity',
                                'Name' => 'A27555_16461_17196_39771',
                                'Properties' => ['Title' => GetMessage('BPT1_BT_SA1_TITLE_1')],
                                'Children' => [
                                    [
                                        'Type' => 'ApproveActivity',
                                        'Name' => 'A94751_67978_49922_99999',
                                        'Properties' => [
                                            'ApproveType' => 'any',
                                            'OverdueDate' => '',
                                            'ApproveMinPercent' => '50',
                                            'ApproveWaitForAll' => 'N',
                                            'Name' => GetMessage('BPT1_BT_AA11_NAME'),
                                            'Description' => GetMessage('BPT1_BT_AA11_DESCR'),
                                            'Parameters' => '',
                                            'StatusMessage' => GetMessage('BPT1_BT_AA11_STATUS_MESSAGE'),
                                            'SetStatusMessage' => 'Y',
                                            'Users' => ['Variable', 'ParameterBoss'],
                                            'Title' => GetMessage('BPT1_BT_AA11_TITLE'),
                                        ],
                                        'Children' => [
                                            [
                                                'Type' => 'SequenceActivity',
                                                'Name' => 'A85668_52803_44143_49694',
                                                'Properties' => ['Title' => GetMessage('BPT1_BT_SA1_TITLE_1')],
                                                'Children' => [
                                                    [
                                                        'Type' => 'RequestInformationActivity',
                                                        'Name' => 'A42698_12107_48239_41360',
                                                        'Properties' => [
                                                            'OverdueDate' => '',
                                                            'Name' => GetMessage('BPT1_BT_RIA11_NAME'),
                                                            'Description' => GetMessage('BPT1_BT_RIA11_DESCR'),
                                                            'Parameters' => '',
                                                            'RequestedInformation' => [
                                                                [
                                                                    'Name' => 'need_additional_approve',
                                                                    'Title' => GetMessage('BPT1_BT_RIA11_P1'),
                                                                    'Type' => 'B',
                                                                    'Default' => '',
                                                                    'Required' => '0',
                                                                    'Multiple' => '0',
                                                                ],
                                                                [
                                                                    'Name' => 'ParameterBoss',
                                                                    'Title' => GetMessage('BPT1_BT_RIA11_P2'),
                                                                    'Type' => 'S:UserID',
                                                                    'Default' => '',
                                                                    'Required' => '0',
                                                                    'Multiple' => '0',
                                                                ],
                                                            ],
                                                            'Users' => ['Variable', 'ParameterBoss'],
                                                            'Title' => GetMessage('BPT1_BT_RIA11_TITLE'),
                                                        ],
                                                    ],
                                                    [
                                                        'Type' => 'IfElseActivity',
                                                        'Name' => 'A16288_6973_71334_75760',
                                                        'Properties' => ['Title' => GetMessage('BPT1_BT_IF11_N')],
                                                        'Children' => [
                                                            [
                                                                'Type' => 'IfElseBranchActivity',
                                                                'Name' => 'A43136_44567_10680_30159',
                                                                'Properties' => [
                                                                    'Title' => GetMessage('BPT1_BT_IEBA1_V1'),
                                                                    'propertyvariablecondition' => [['need_additional_approve', '=', 'Y']],
                                                                ],
                                                            ],
                                                            [
                                                                'Type' => 'IfElseBranchActivity',
                                                                'Name' => 'A65726_71247_68427_60591',
                                                                'Properties' => ['Title' => GetMessage('BPT1_BT_IEBA2_V2')],
                                                                'Children' => [
                                                                    [
                                                                        'Type' => 'SetFieldActivity',
                                                                        'Name' => 'A43342_8811_95090_90018',
                                                                        'Properties' => [
                                                                            'FieldValue' => [
                                                                                'PROPERTY_approving' => 'y',
                                                                            ],
                                                                            'Title' => GetMessage('BPT1_BT_SFA12_TITLE'),
                                                                        ],
                                                                    ],
                                                                    [
                                                                        'Type' => 'SetStateTitleActivity',
                                                                        'Name' => 'A2560_50199_5564_95292',
                                                                        'Properties' => [
                                                                            'TargetStateTitle' => GetMessage('BPT1_BT_SFTA12_ST'),
                                                                            'Title' => GetMessage('BPT1_BT_SFTA12_T'),
                                                                        ],
                                                                    ],
                                                                ],
                                                            ],
                                                        ],
                                                    ],
                                                ],
                                            ],
                                            [
                                                'Type' => 'SequenceActivity',
                                                'Name' => 'A40542_41453_94895_70387',
                                                'Properties' => ['Title' => GetMessage('BPT1_BT_SA1_TITLE_1')],
                                                'Children' => [
                                                    [
                                                        'Type' => 'SetFieldActivity',
                                                        'Name' => 'A70022_19949_94473_76597',
                                                        'Properties' => [
                                                            'FieldValue' => [
                                                                'PROPERTY_approving' => 'n',
                                                            ],
                                                            'Title' => GetMessage('BPT1_BT_SFA12_TITLE'),
                                                        ],
                                                    ],
                                                    [
                                                        'Type' => 'SetStateTitleActivity',
                                                        'Name' => 'A80110_96659_73401_33711',
                                                        'Properties' => [
                                                            'TargetStateTitle' => GetMessage('BPT1_BT_SSTA14_ST'),
                                                            'Title' => GetMessage('BPT1_BT_SSTA14_T'),
                                                        ],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],

                    [
                        'Type' => 'IfElseActivity',
                        'Name' => 'A74964_46906_3754_79133',
                        'Properties' => ['Title' => GetMessage('BPT1_BT_IF11_N')],
                        'Children' => [
                            [
                                'Type' => 'IfElseBranchActivity',
                                'Name' => 'A92164_76962_83081_44454',
                                'Properties' => [
                                    'Title' => GetMessage('BPT1_BT_IEBA15_V1'),
                                    'fieldcondition' => [['PROPERTY_approving', '=', 'y']],
                                ],
                                'Children' => [
                                    [
                                        'Type' => 'SocNetMessageActivity',
                                        'Name' => 'A70194_97682_35832_41687',
                                        'Properties' => [
                                            'MessageText' => GetMessage('BPT1_BT_SNMA16_TEXT'),
                                            'MessageUserFrom' => ['A94751_67978_49922_99999', 'LastApprover'],
                                            'MessageUserTo' => ['Template', 'TargetUser'],
                                            'Title' => GetMessage('BPT1_BT_SNMA16_TITLE'),
                                        ],
                                    ],
                                    [
                                        'Type' => 'ReviewActivity',
                                        'Name' => 'A41318_52246_80265_83609',
                                        'Properties' => [
                                            'ApproveType' => 'any',
                                            'OverdueDate' => '',
                                            'Name' => GetMessage('BPT1_BT_RA17_NAME'),
                                            'Description' => GetMessage('BPT1_BT_RA17_DESCR'),
                                            'Parameters' => '',
                                            'StatusMessage' => GetMessage('BPT1_BT_RA17_STATUS_MESSAGE'),
                                            'SetStatusMessage' => 'Y',
                                            'TaskButtonMessage' => GetMessage('BPT1_BT_RA17_TBM'),
                                            'Users' => ['Variable', 'ParameterBookkeeper'],
                                            'Title' => GetMessage('BPT1_BT_RA17_TITLE'),
                                        ],
                                    ],
                                    [
                                        'Type' => 'AbsenceActivity',
                                        'Name' => 'A49292_56042_93493_74019',
                                        'Properties' => [
                                            'AbsenceName' => GetMessage('BPT_BT_AA7_NAME'),
                                            'AbsenceDesrc' => GetMessage('BPT_BT_AA7_DESCR'),
                                            'AbsenceFrom' => '{=Template:date_start}',
                                            'AbsenceTo' => '{=Template:date_end}',
                                            'AbsenceState' => GetMessage('BPT_BT_AA7_STATE'),
                                            'AbsenceFinishState' => GetMessage('BPT_BT_AA7_FSTATE'),
                                            'AbsenceType' => 'VACATION',
                                            'AbsenceUser' => ['Template', 'TargetUser'],
                                            'Title' => GetMessage('BPT_BT_AA7_TITLE'),
                                        ],
                                    ],
                                    [
                                        'Type' => 'SetStateTitleActivity',
                                        'Name' => 'A80110_96659_73401_98765',
                                        'Properties' => [
                                            'TargetStateTitle' => GetMessage('BPT1_BT_SSTA18_ST'),
                                            'Title' => GetMessage('BPT1_BT_SSTA18_T'),
                                        ],
                                    ],
                                ],
                            ],
                            [
                                'Type' => 'IfElseBranchActivity',
                                'Name' => 'A30959_26245_33197_97212',
                                'Properties' => ['Title' => GetMessage('BPT1_BT_IEBA15_V2')],
                                'Children' => [
                                    [
                                        'Type' => 'SocNetMessageActivity',
                                        'Name' => 'A61811_43013_42560_16921',
                                        'Properties' => [
                                            'MessageText' => GetMessage('BPT1_BT_SNMA18_TEXT'),
                                            'MessageUserFrom' => ['A94751_67978_49922_99999', 'LastApprover'],
                                            'MessageUserTo' => ['Template', 'TargetUser'],
                                            'Title' => GetMessage('BPT1_BT_SNMA18_TITLE'),
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function GetDocumentFields()
    {
        return [
            [
                'name' => GetMessage('BPT1_BTF_P_APP'),
                'code' => 'approving',
                'type' => 'L',
                'multiple' => 'N',
                'required' => 'N',
                'options' => GetMessage('BPT1_BTF_P_APPS'),
            ],
        ];
    }
}

$bpTemplateObject = new CBPTemplates_Vacation();
