<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync\Index;

use Bitrix\Disk\Configuration;
use Bitrix\Disk\File;
use Bitrix\Disk\Infrastructure\Rest\V3\Dto\Sync\BoardIndexDto;
use Bitrix\Disk\Infrastructure\Rest\V3\Exception\BoardIndexNotFoundException;
use Bitrix\Disk\Infrastructure\Rest\V3\Exception\BoardIndexNotReadyException;
use Bitrix\Disk\Infrastructure\Rest\V3\Exception\FeatureNotSupportedException;
use Bitrix\Disk\TypeFile;

final class BoardIndexProvider
{
	public function __construct(
		private readonly BoardIndexAccessResolver $accessResolver = new BoardIndexAccessResolver(),
		private readonly BoardIndexReader $reader = new BoardIndexReader(),
	)
	{
	}

	/**
	 * @throws BoardIndexNotFoundException
	 * @throws BoardIndexNotReadyException
	 */
	public function get(int $userId, int $objectId): BoardIndexDto
	{
		if (!Configuration::isBoardIndexEnabled())
		{
			throw new FeatureNotSupportedException();
		}

		$file = File::loadById($objectId);
		if (
			!$file instanceof File
			|| (int)$file->getTypeFile() !== TypeFile::BOARD
			|| !$this->accessResolver->canRead($userId, $objectId)
		)
		{
			throw new BoardIndexNotFoundException();
		}

		$text = $this->reader->readReadyText($objectId);
		if ($text === null)
		{
			throw new BoardIndexNotReadyException();
		}

		$index = new BoardIndexDto();
		$index->text = $text;

		return $index;
	}
}
