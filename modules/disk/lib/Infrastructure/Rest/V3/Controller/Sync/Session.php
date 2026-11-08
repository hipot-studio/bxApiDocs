<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\V3\Controller\Sync;

use Bitrix\Disk\Infrastructure\Rest\V3\Dto\Sync\SessionDto;
use Bitrix\Disk\Infrastructure\Rest\V3\Request\Sync\CreateSessionRequest;
use Bitrix\Disk\Infrastructure\Rest\V3\Response\Sync\SessionResponse;
use Bitrix\Disk\Internal\Service\Sync\SessionService;
use Bitrix\Rest\V3\Attribute\DtoType;

/**
 * disk.sync.session.create - opening a cycle of synchronisation.
 *
 * An ordinary method of the contour in every respect: the switch of the contour stands in front of
 * it like everywhere else, and the guard that refreshes access codes and compares the snapshot of
 * the pair reaches it through the base controller, not through anything written here.
 */
#[DtoType(SessionDto::class)]
final class Session extends BaseSyncController
{
	public function createAction(CreateSessionRequest $request): SessionResponse
	{
		return new SessionResponse(
			(new SessionService())->create(
				$this->resolveUserId(),
				$this->resolveAppId(),
				$request->getHistoryDepthDays(),
				$request->getPlannedPollIntervalSeconds(),
			),
		);
	}
}
