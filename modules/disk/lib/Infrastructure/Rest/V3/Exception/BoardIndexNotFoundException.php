<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\V3\Exception;

final class BoardIndexNotFoundException extends SyncRestException
{
	protected const STATUS = \CRestServer::STATUS_NOT_FOUND;

	protected function getErrorCode(): string
	{
		return 'BOARD_INDEX_NOT_FOUND';
	}

	protected function getMessagePhraseCode(): string
	{
		return 'Board index not found.';
	}
}
