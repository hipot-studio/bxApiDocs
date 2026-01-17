<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Grid\SystemHistory\Settings;

use Bitrix\Main\Grid\Settings;

class SystemHistoryGridSettings extends Settings
{
	public function __construct(array $params = [])
	{
		parent::__construct(array_merge(['ID' => 'tasks-system-history-grid'], $params));
	}
}
