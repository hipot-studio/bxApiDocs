<?php

declare(strict_types=1);

namespace Bitrix\Anonymizer\Internal\Repository;

use Bitrix\Anonymizer\Internal\Entities\Quest;
use Bitrix\Anonymizer\Internal\Entities\Request;
use Bitrix\Anonymizer\Internal\Integration\AnonymizerService\Command\CommandInterface;

/**
 * Request lifecycle per quest and command code (cache, load from DB, create new).
 */
final class RequestRegistry
{
	/** @var array<string, array<string, Request>> */
	private static array $cacheByQuest = [];

	public static function getForCommand(Quest $quest, CommandInterface $command): Request
	{
		$cacheKey = self::cacheKey($quest);
		$code = $command::getCode();

		if (isset(self::$cacheByQuest[$cacheKey][$code]))
		{
			return self::$cacheByQuest[$cacheKey][$code];
		}

		$questId = $quest->getId();
		if ($questId !== null)
		{
			$loaded = Request::getByQuestAndCommand($questId, $code);
			if ($loaded !== null)
			{
				self::$cacheByQuest[$cacheKey][$code] = $loaded;

				return $loaded;
			}
		}

		$request = new Request($quest);
		self::$cacheByQuest[$cacheKey][$code] = $request;

		return $request;
	}

	private static function cacheKey(Quest $quest): string
	{
		$id = $quest->getId();

		return $id !== null ? 'q' . $id : 'o' . spl_object_id($quest);
	}
}
