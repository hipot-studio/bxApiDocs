<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Filter\Workgroup;

use Bitrix\Socialnetwork\Component\WorkgroupList;
use Bitrix\Socialnetwork\V2\Infrastructure\Filter\AbstractPresetFilter;

class WorkgroupFilter extends AbstractPresetFilter
{
	public const FILTER_ID = 'SONET_GROUP_LIST';

	protected function getMode(): string
	{
		return WorkgroupList::MODE_COMMON;
	}
}
