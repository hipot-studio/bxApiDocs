<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\V3\Documentation\Sync;

use Bitrix\Disk\Infrastructure\Rest\V3\Dto\Sync\BoardIndexDto;
use Bitrix\Disk\Infrastructure\Rest\V3\Exception\BoardIndexNotFoundException;
use Bitrix\Disk\Infrastructure\Rest\V3\Exception\BoardIndexNotReadyException;
use Bitrix\Disk\Infrastructure\Rest\V3\Exception\FeatureNotSupportedException;
use Bitrix\Disk\Infrastructure\Rest\V3\Exception\SyncAccessDeniedException;
use Bitrix\Disk\Infrastructure\Rest\V3\Request\Sync\StrictRequest;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Exception\Internal\InternalException;

/**
 * Documentation of disk.sync.index.get.
 */
final class IndexGetMethodProvider extends SyncMethodProvider
{
	private const PARAMETER_OBJECT_ID = 'objectId';

	protected function getItemDto(): Dto
	{
		return BoardIndexDto::create();
	}

	protected function getRefusals(): array
	{
		return [
			new FeatureNotSupportedException(),
			new SyncAccessDeniedException(),
			new BoardIndexNotFoundException(),
			new BoardIndexNotReadyException(),
			StrictRequest::refusal(
				self::PARAMETER_OBJECT_ID,
				'Object ID must be a positive integer.',
			),
			new InternalException(new \RuntimeException()),
		];
	}

	protected function getRequestBody(): array
	{
		return [
			'content' => [
				'application/json' => [
					'schema' => [
						'type' => 'object',
						'required' => [self::PARAMETER_OBJECT_ID],
						'properties' => [
							self::PARAMETER_OBJECT_ID => [
								'type' => 'integer',
								'minimum' => 1,
								'description' => 'The identifier of the board file from its synchronisation card.',
							],
						],
					],
				],
			],
		];
	}

	protected function getTitle(): ?string
	{
		return 'Get the prepared index of a board';
	}

	protected function getDescription(): ?string
	{
		return <<<'TEXT'
			Returns the current prepared index of a board as UTF-8 text.

			The method checks the user and application pair, current access to the object, the file type
			and the state of the prepared index on every call. A card that says the index is available is
			not an access grant.

			BOARD_INDEX_NOT_FOUND is the single answer for an absent object, a non-file, a file that is
			not a board and an object the caller cannot currently read. These cases are deliberately
			indistinguishable, so the method cannot be used to probe objects outside the caller's access.

			BOARD_INDEX_NOT_READY means the board is readable but has no prepared index yet. The same
			answer is used while indexing is waiting and after an indexing attempt failed. Keep the
			previously downloaded index and wait for the next indexUpdated change.

			A ready empty board is successful and returns an empty `text`. The response contains no object
			identifier, storage fields or index version.
			TEXT;
	}
}
