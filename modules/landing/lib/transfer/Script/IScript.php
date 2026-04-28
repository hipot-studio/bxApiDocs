<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Script;

use Bitrix\Landing\Transfer\Script\Action\ActionConfig;

interface IScript
{
	/**
	 * @return ActionConfig[]
	 */
	public function getMap(): array;
}
