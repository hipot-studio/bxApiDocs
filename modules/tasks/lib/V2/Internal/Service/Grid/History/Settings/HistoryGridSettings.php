<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Grid\History\Settings;

use Bitrix\Main\Grid\Settings;

class HistoryGridSettings extends Settings
{
	public function __construct(array $params = [])
	{
		parent::__construct(array_merge(['ID' => 'tasks-history-grid'], $params));
	}
}
