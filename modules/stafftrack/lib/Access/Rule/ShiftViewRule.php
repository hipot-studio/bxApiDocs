<?php

namespace Bitrix\StaffTrack\Access\Rule;

use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Main\Access\Rule\AbstractRule;
use Bitrix\StaffTrack\Access\AccessibleUserProvider;
use Bitrix\StaffTrack\Access\Model\ShiftModel;

class ShiftViewRule extends AbstractRule
{
	public function execute(AccessibleItem $item = null, $params = null): bool
	{
		if (!$item instanceof ShiftModel)
		{
			return false;
		}

		if ($this->user->isAdmin())
		{
			return true;
		}

		$callerId = $this->user->getUserId();
		if ($callerId === $item->getUserId())
		{
			return true;
		}

		return (bool)$this->getAccessibleUserProvider()->filterAccessibleUserIds($callerId, [$item->getUserId()]);
	}

	protected function getAccessibleUserProvider(): AccessibleUserProvider
	{
		return new AccessibleUserProvider();
	}
}
