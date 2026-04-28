<?php
declare(strict_types=1);

namespace Bitrix\Disk\Public\Event;

use Bitrix\Main\Event;

abstract class AbstractEvent extends Event
{
	public function __construct(string $event, $parameters = [], $filter = null)
	{
		parent::__construct(
			moduleId: 'disk',
			type: $event,
			parameters: $parameters,
			filter: $filter,
		);
	}
}
