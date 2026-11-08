<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Api\Request\TemplateNoteService;

/**
 * What a note is pinned to. The values are the ones the editor sends and reads; the store keeps a
 * single char (SQL-01), and the pair of conversions below is the only place that knows which.
 */
enum AnchorType: string
{
	case Node = 'node';
	case Connection = 'connection';
	case Free = 'free';

	public function toStorage(): string
	{
		return match ($this)
		{
			self::Node => 'N',
			self::Connection => 'C',
			self::Free => 'F',
		};
	}

	public static function fromStorage(string $storedType): ?self
	{
		return match ($storedType)
		{
			'N' => self::Node,
			'C' => self::Connection,
			'F' => self::Free,
			default => null,
		};
	}
}
