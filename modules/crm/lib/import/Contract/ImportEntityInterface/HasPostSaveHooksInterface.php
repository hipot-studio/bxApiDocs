<?php

namespace Bitrix\Crm\Import\Contract\ImportEntityInterface;

use Bitrix\Crm\Import\Contract\PostSaveHookInterface;

interface HasPostSaveHooksInterface
{
	/**
	 * @return PostSaveHookInterface[]
	 */
	public function getPostSaveHooks(): array;
}
