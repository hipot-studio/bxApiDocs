<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\V3\Controller\Sync;

use Bitrix\Disk\Infrastructure\Rest\V3\Dto\Sync\BoardIndexDto;
use Bitrix\Disk\Infrastructure\Rest\V3\Request\Sync\IndexGetRequest;
use Bitrix\Disk\Infrastructure\Rest\V3\Response\Sync\IndexGetResponse;
use Bitrix\Disk\Internal\Service\Sync\Index\BoardIndexProvider;
use Bitrix\Rest\V3\Attribute\DtoType;

#[DtoType(BoardIndexDto::class)]
final class Index extends BaseSyncController
{
	public function getAction(IndexGetRequest $request): IndexGetResponse
	{
		$userId = $this->resolveUserId();
		$this->resolveAppId();

		return new IndexGetResponse(
			(new BoardIndexProvider())->get($userId, $request->getObjectId()),
		);
	}
}
