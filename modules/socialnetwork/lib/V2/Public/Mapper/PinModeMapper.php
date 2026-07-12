<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Mapper;

use Bitrix\Socialnetwork\V2\Internal\Entity\Workgroup\WorkgroupPinMode;
use Bitrix\Socialnetwork\V2\Public\Grid\PinMode;

class PinModeMapper
{
	public function mapToInternal(PinMode $mode): WorkgroupPinMode
	{
		return match ($mode)
		{
			PinMode::Common => WorkgroupPinMode::Common,
			PinMode::UserGroups => WorkgroupPinMode::UserGroups,
			PinMode::TasksProject => WorkgroupPinMode::TasksProject,
			PinMode::TasksScrum => WorkgroupPinMode::TasksScrum,
		};
	}
}
