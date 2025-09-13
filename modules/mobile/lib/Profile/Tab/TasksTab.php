<?php

namespace Bitrix\Mobile\Profile\Tab;

use Bitrix\Main\Localization\Loc;
use Bitrix\Mobile\Profile\Enum\TabContextType;
use Bitrix\Mobile\Profile\Enum\TabType;

class TasksTab extends BaseProfileTab
{
	/**
	 * @return TabType
	 */
	public function getType(): TabType
	{
		return TabType::TASKS;
	}

	/**
	 * @return TabContextType
	 */
	public function getContextType(): TabContextType
	{
		return TabContextType::COMPONENT;
	}

	/**
	 * @return bool
	 */
	public function isAvailable(): bool
	{
		return false;
	}

	/**
	 * @return string
	 */
	public function getTitle(): string
	{
		return Loc::getMessage('PROFILE_TAB_TASKS_TITLE');
	}
}
