<?php

namespace Bitrix\ImOpenLines\V2\Line;

use Bitrix\ImOpenLines\Config;
use Bitrix\ImOpenLines\Model\ConfigTable;

/**
 * Read model of the line settings needed by the operator console.
 * The write path stays in \Bitrix\ImOpenLines\Config.
 */
final class LineSettings
{
	private int $lineId;
	private bool $confirmClose;

	private function __construct(int $lineId, bool $confirmClose)
	{
		$this->lineId = $lineId;
		$this->confirmClose = $confirmClose;
	}

	public static function createByLineId(int $lineId): self
	{
		return new self($lineId, self::readConfirmClose($lineId));
	}

	public function toRestFormat(): array
	{
		return [
			'lineId' => $this->lineId,
			'confirmClose' => $this->confirmClose,
		];
	}

	private static function readConfirmClose(int $lineId): bool
	{
		if ($lineId <= 0)
		{
			return false;
		}

		$line = ConfigTable::getByPrimary($lineId, [
			'select' => ['CONFIRM_CLOSE'],
			'cache' => ['ttl' => Config::CONFIG_CACHE_TIME],
		])->fetch();

		// BooleanField is not cast by fetch(), the raw 'Y'/'N' value comes from the database
		return is_array($line) && $line['CONFIRM_CLOSE'] === 'Y';
	}
}
