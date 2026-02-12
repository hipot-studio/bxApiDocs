<?php

namespace Bitrix\Im\V2\Permission;

/**
 * @see \Bitrix\Im\V2\Controller\Filter\CheckActionAccess
 */
interface ChatActionAccessCheckable
{
	public function canDo(Action $action, mixed $target = null): bool;
}