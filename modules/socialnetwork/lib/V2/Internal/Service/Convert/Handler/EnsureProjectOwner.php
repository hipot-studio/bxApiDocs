<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\Convert\Handler;

use Bitrix\Socialnetwork\Item\Workgroup;
use Bitrix\Socialnetwork\V2\Internal\Exceptions\ProjectEnsureOwnerException;
use Bitrix\Socialnetwork\V2\Internal\Service\Project\Owner\OwnerReplacementService;

class EnsureProjectOwner implements HandlerInterface
{
	public function __construct(
		private readonly OwnerReplacementService $ownerReplacementService,
	)
	{
	}

	/**
	 * @throws ProjectEnsureOwnerException
	 */
	public function __invoke(Workgroup $group): void
	{
		$groupId = $group->getId();
		if ($groupId <= 0)
		{
			throw new ProjectEnsureOwnerException(
				sprintf('Group id is invalid: [%s]', $groupId)
			);
		}

		$result = $this->ownerReplacementService->ensureOwnerForConvert($groupId);
		if ($result->isSuccess())
		{
			return;
		}

		throw new ProjectEnsureOwnerException(
			implode(', ', $result->getErrorMessages()) ?: 'Unable to ensure project owner'
		);
	}
}
