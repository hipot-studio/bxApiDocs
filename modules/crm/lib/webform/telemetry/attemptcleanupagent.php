<?php

declare(strict_types=1);

namespace Bitrix\Crm\WebForm\Telemetry;

use Bitrix\Crm\WebForm\Internals\FormTelemetryAttemptTable;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Entity;
use Bitrix\Main\Type\DateTime;

final class AttemptCleanupAgent
{
	use DeleteByFilterTrait {
		deleteByFilter as private;
	}

	public const AGENT_NAME = self::class . '::run();';

	private const BATCH_SIZE = 500;
	private const RETRY_INTERVAL_SECONDS = 60;

	public static function run(): string
	{
		$rows = FormTelemetryAttemptTable::query()
			->setSelect(['ATTEMPT_ID'])
			->where('DATE_EXPIRE', '<=', new DateTime())
			->setOrder([
				'DATE_EXPIRE' => 'ASC',
				'ATTEMPT_ID' => 'ASC',
			])
			->setLimit(self::BATCH_SIZE)
			->fetchAll()
		;
		$attemptIds = array_column($rows, 'ATTEMPT_ID');
		if ($attemptIds === [])
		{
			return self::AGENT_NAME;
		}

		self::deleteByFilter(['@ATTEMPT_ID' => $attemptIds]);

		if (count($attemptIds) === self::BATCH_SIZE)
		{
			global $pPERIOD;

			$pPERIOD = self::RETRY_INTERVAL_SECONDS;
		}

		return self::AGENT_NAME;
	}

	private static function getEntity(): Entity
	{
		return FormTelemetryAttemptTable::getEntity();
	}

	private static function getTableName(): string
	{
		return FormTelemetryAttemptTable::getTableName();
	}

	private static function cleanCache(): void
	{
		FormTelemetryAttemptTable::cleanCache();
	}
}
