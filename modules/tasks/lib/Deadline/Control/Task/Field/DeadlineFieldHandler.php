<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Deadline\Control\Task\Field;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Socialnetwork\Item\Workgroup;
use Bitrix\Tasks\Deadline\Service\DefaultDeadlineStateProvider;
use Bitrix\Tasks\Integration\Socialnetwork\Internals\Registry\GroupRegistry;
use Bitrix\Tasks\Util\Type;
use Psr\Container\NotFoundExceptionInterface;

class DeadlineFieldHandler
{
	private DefaultDeadlineStateProvider $defaultDeadlineStateProvider;
	private ?\Bitrix\Socialnetwork\Internals\Registry\GroupRegistry $groupRegistry;

	/**
	 * @throws ObjectNotFoundException
	 * @throws NotFoundExceptionInterface
	 */
	public function __construct(
		private readonly int $userId,
		?DefaultDeadlineStateProvider $defaultDeadlineStateProvider = null,
	)
	{
		$serviceLocator = ServiceLocator::getInstance();

		$this->defaultDeadlineStateProvider =
			$defaultDeadlineStateProvider
			?? $serviceLocator->get(DefaultDeadlineStateProvider::class)
		;

		$this->groupRegistry = GroupRegistry::getInstance();
	}

	/**
	 * @param array $fields
	 *
	 * @return array
	 *
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function modify(array &$fields): array
	{
		if (isset($fields['DEADLINE']))
		{
			return $fields;
		}

		$groupId = (int)($fields['GROUP_ID'] ?? 0);

		$group = $this->groupRegistry->get($groupId);
		if ($group?->isScrumProject())
		{
			return $fields;
		}

		$deadline = $this->defaultDeadlineStateProvider->getDefaultDeadline(
			userId: $this->userId,
			now: new DateTime(),
		);
		if (!$this->isDeadlineInProjectRange($deadline, $group))
		{
			return $fields;
		}

		$fields['DEADLINE'] = $deadline?->toString();

		return $fields;
	}

	private function isDeadlineInProjectRange(?DateTime $deadline, ?Workgroup $group): bool
	{
		if ($deadline === null || !$group?->isProject())
		{
			return true;
		}

		$groupFields = $group->getFields();
		$projectStartDate = Type\DateTime::createFrom($groupFields['PROJECT_DATE_START'] ?? null);
		$projectFinishDate = Type\DateTime::createFrom($groupFields['PROJECT_DATE_FINISH'] ?? null);
		if ($projectFinishDate)
		{
			$projectFinishDate->addSecond(86399);
		}

		$projectDeadline = Type\DateTime::createFrom($deadline);

		return $projectDeadline?->checkInRange($projectStartDate, $projectFinishDate) ?? true;
	}
}
