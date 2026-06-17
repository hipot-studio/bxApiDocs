<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Entity\Record;

enum RecordStatus: string
{
	case Opened = 'opened';
	case Paused = 'paused';
	case Closed = 'closed';
	case Unknown = 'unknown';

	public static function fromRecord(object $record): self
	{
		if (method_exists($record, 'isOpened') && $record->isOpened())
		{
			return self::Opened;
		}

		if (method_exists($record, 'isPaused') && $record->isPaused())
		{
			return self::Paused;
		}

		if (method_exists($record, 'isClosed') && $record->isClosed())
		{
			return self::Closed;
		}

		return self::Unknown;
	}
}
