<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\V3\Exception;

final class BoardIndexNotReadyException extends SyncRestException
{
	protected const STATUS = \CRestServer::STATUS_CONFLICT;

	protected function getErrorCode(): string
	{
		return 'BOARD_INDEX_NOT_READY';
	}

	protected function getMessagePhraseCode(): string
	{
		return 'Board index is not ready.';
	}
}
