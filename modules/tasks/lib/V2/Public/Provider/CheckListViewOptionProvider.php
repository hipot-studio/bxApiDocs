<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Tasks\V2\Internal\Entity\CheckList\Type;
use Bitrix\Tasks\V2\Internal\Service\CheckList\ViewOptionService;

class CheckListViewOptionProvider
{
	private readonly ViewOptionService $service;

	public function __construct()
	{
		$this->service = ServiceLocator::getInstance()->get(ViewOptionService::class);
	}

	private function isCompletedHidden(Type $entityType, int $userId): bool
	{
		return $this->service->isCompletedHidden($entityType, $userId);
	}

	/**
	 * Returns whether completed task checklist items are hidden for the user.
	 *
	 * @param int $userId
	 * @return bool
	 */
	public function isTaskCompletedHidden(int $userId): bool
	{
		return $this->isCompletedHidden(Type::Task, $userId);
	}

	/**
	 * Returns whether completed template checklist items are hidden for the user.
	 *
	 * @param int $userId
	 * @return bool
	 */
	public function isTemplateCompletedHidden(int $userId): bool
	{
		return $this->isCompletedHidden(Type::Template, $userId);
	}
}
