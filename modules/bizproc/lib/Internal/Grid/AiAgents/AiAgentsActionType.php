<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Grid\AiAgents;

enum AiAgentsActionType: string
{
	case GROUP_DELETE = 'group-delete';
	case DELETE = 'delete';
	case EDIT = 'edit';
	case RESTART = 'restart';
}
