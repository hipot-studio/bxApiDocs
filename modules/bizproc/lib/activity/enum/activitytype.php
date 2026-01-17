<?php

namespace Bitrix\Bizproc\Activity\Enum;

enum ActivityType: string
{
	case ACTIVITY = 'activity';
	case ROBOT = 'robot_activity';
	case CONDITION = 'condition';
	case TRIGGER = 'trigger';
	case NODE = 'node';
	case NODE_ACTION = 'node_action';
}
