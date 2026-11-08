<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\V3\Controller\Sync;

use Bitrix\Disk\Infrastructure\Rest\V3\Dto\Sync\ContentLinkDto;
use Bitrix\Disk\Infrastructure\Rest\V3\Request\Sync\ContentGetRequest;
use Bitrix\Disk\Infrastructure\Rest\V3\Response\Sync\ContentGetResponse;
use Bitrix\Disk\Internal\Service\Sync\ContentLinkProvider;
use Bitrix\Rest\V3\Attribute\DtoType;

/**
 * disk.sync.content.get - where to fetch the bytes of a file the client already has a card for.
 *
 * The method belongs to this contour and not to the classic methods of Disk: fetching content there
 * would ask the application for the scope that also writes, and the "reading only" the whole contour
 * was chosen for would be spent on the first file downloaded - while a personal memory without content
 * is not worth building.
 *
 * It is the one method besides capabilities.get that neither refreshes the access codes of the caller
 * nor compares the snapshot of the pair; the base controller carries that decision and the reason for
 * it. What it does do, like every other method, is answer for the identifier it was handed.
 */
#[DtoType(ContentLinkDto::class)]
final class Content extends BaseSyncController
{
	public function getAction(ContentGetRequest $request): ContentGetResponse
	{
		return new ContentGetResponse(
			(new ContentLinkProvider())->issue(
				$this->resolveUserId(),
				$request->getDownloadId(),
				$this->getServer(),
			),
		);
	}
}
