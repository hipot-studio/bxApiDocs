<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Tasks\V2\Internal\Entity\CheckList\Type;
use CUserOptions;

class CheckListViewOptionRepository implements CheckListViewOptionRepositoryInterface
{
	private const CATEGORY = 'tasks';
	private const OPTION_TASK = 'task_options_checklist_show_completed';
	private const OPTION_TEMPLATE = 'template_options_checklist_show_completed';

	public function getShowCompleted(Type $entityType, int $userId): bool
	{
		$name = match ($entityType)
		{
			Type::Task => self::OPTION_TASK,
			Type::Template => self::OPTION_TEMPLATE,
		};

		return (bool)CUserOptions::getOption(
			category: self::CATEGORY,
			name: $name,
			default_value: true,
			user_id: $userId,
		);
	}

	public function setShowCompleted(Type $entityType, int $userId, bool $show): void
	{
		$name = match ($entityType)
		{
			Type::Task => self::OPTION_TASK,
			Type::Template => self::OPTION_TEMPLATE,
		};

		CUserOptions::setOption(
			category: self::CATEGORY,
			name: $name,
			value: $show,
			user_id: $userId,
		);
	}
}
