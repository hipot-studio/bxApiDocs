<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Filter\Scrum;

use Bitrix\Socialnetwork\Component\WorkgroupList;
use Bitrix\Socialnetwork\V2\Infrastructure\Filter\AbstractPresetFilter;

class ScrumFilter extends AbstractPresetFilter
{
	public const FILTER_ID = 'SONET_GROUP_LIST_SCRUM';

	protected function getMode(): string
	{
		return WorkgroupList::MODE_TASKS_SCRUM;
	}
}
