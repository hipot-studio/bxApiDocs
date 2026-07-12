<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\Project;

use Bitrix\Main\Event;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Bitrix\Socialnetwork\V2\Internal\Integration\Extranet\Service\ExtranetUserService;

class HasCollabersService
{
	public const OPTION_NAME = 'HAS_COLLABERS';

	public function __construct(private readonly ExtranetUserService $extranetUserService)
	{
	}

	/**
	 * Recalculates HAS_COLLABERS for a group from current membership and persists it.
	 *
	 * Returns the resulting flag so callers can avoid a second read.
	 */
	public function updateOption(int $groupId, int $excludeUserId = 0): bool
	{
		$hasCollabers = $this->recalculate($groupId, $excludeUserId);

		$this->storeFlag($groupId, $hasCollabers);

		return $hasCollabers;
	}

	private function recalculate(int $groupId, int $excludeUserId = 0): bool
	{
		$memberIds = Container::getInstance()
			->getProjectMemberRepository()
			->getMemberUserIds($groupId)
		;

		if ($excludeUserId > 0)
		{
			$memberIds = array_values(array_diff($memberIds, [$excludeUserId]));
		}

		if (empty($memberIds))
		{
			return false;
		}

		$collaberIds = $this->extranetUserService->getCollaberIds();

		if (empty($collaberIds))
		{
			return false;
		}

		return !empty(array_intersect($memberIds, $collaberIds));
	}

	public function storeFlag(int $collabId, bool $hasCollabers): void
	{
		$value = $hasCollabers ? 'Y' : 'N';

		Container::getInstance()->getCollabOptionRepository()->setOption(
			collabId: $collabId,
			optionName: self::OPTION_NAME,
			value: $value,
		);
	}
}
