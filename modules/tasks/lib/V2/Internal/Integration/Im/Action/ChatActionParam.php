<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

enum ChatActionParam: string
{
	case ChatAction = 'chatAction';
	case EntityId = 'entityId';
	case ChildrenIds = 'childrenIds';
}
