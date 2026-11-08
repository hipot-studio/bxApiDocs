<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\V3\Documentation\Sync;

use Bitrix\Disk\Infrastructure\Rest\V3\Dto\Sync\ContentLinkDto;
use Bitrix\Disk\Infrastructure\Rest\V3\Exception\FeatureNotSupportedException;
use Bitrix\Disk\Infrastructure\Rest\V3\Exception\SyncAccessDeniedException;
use Bitrix\Disk\Infrastructure\Rest\V3\Exception\SyncObjectNotFoundException;
use Bitrix\Disk\Infrastructure\Rest\V3\Request\Sync\StrictRequest;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * Documentation of disk.sync.content.get.
 */
final class ContentGetMethodProvider extends SyncMethodProvider
{
	private const PARAMETER_DOWNLOAD_ID = 'downloadId';

	protected function getItemDto(): Dto
	{
		return ContentLinkDto::create();
	}

	protected function getRefusals(): array
	{
		return [
			new FeatureNotSupportedException(),
			new SyncAccessDeniedException(),
			new SyncObjectNotFoundException(),
			StrictRequest::refusal(self::PARAMETER_DOWNLOAD_ID, 'DISK_SYNC_REQUEST_DOWNLOAD_ID_REQUIRED'),
		];
	}

	protected function getRequestBody(): array
	{
		return [
			'content' => [
				'application/json' => [
					'schema' => [
						'type' => 'object',
						'required' => [self::PARAMETER_DOWNLOAD_ID],
						'properties' => [
							self::PARAMETER_DOWNLOAD_ID => [
								'type' => 'string',
								'description' =>
									'The identifier the card of the object carries in its own downloadId, '
									. 'passed back unchanged. It is opaque: it is neither built by the client '
									. 'nor read by it, and a card that offers no content carries no such field '
									. 'to pass back.'
								,
							],
						],
					],
				],
			],
		];
	}

	protected function getTitle(): ?string
	{
		return 'Where to fetch the bytes of a file';
	}

	protected function getDescription(): ?string
	{
		return <<<'TEXT'
			Turns the download identifier of a card into a link to the content of that file.

			The method answers with a link and never with bytes: `url` is fetched with an ordinary GET
			and needs no header, no session and no scope beyond the one this call was already made
			with. `expiresAt` says when the link stops being accepted; ask for a new one rather than
			storing this one. A link is cheap to ask for again, and asking again is what re-checks the
			rights - so a client planning a long queue of downloads should ask shortly before it
			fetches rather than collect links in advance.

			The rights of the caller are checked twice: once here, and once more when the link is
			presented. A right taken away in between is a refused download, not an open one.

			ACCESS_DENIED is the single answer to everything an identifier can be wrong about - a file
			the caller does not see, a file whose content this portal calls unavailable, an identifier
			of a kind the portal does not serve, an identifier that was never issued at all. They are
			one answer on purpose: the identifier is opaque, and telling those cases apart would let
			the objects of a portal be probed through the refusals.

			OBJECT_NOT_FOUND says something narrower and is never a way to learn whether an object
			exists: it is answered only for an object the caller does see and whose content is gone
			from the portal.
			TEXT;
	}
}
