<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

enum ChatAction: string
{
	case ChangeDeadline = 'changeDeadline';
	case CompleteTask = 'completeTask';
	case OpenResult = 'openResult';
	case ShowCheckList = 'showCheckList';
	case ShowCheckListItems = 'showCheckListItems';
}
