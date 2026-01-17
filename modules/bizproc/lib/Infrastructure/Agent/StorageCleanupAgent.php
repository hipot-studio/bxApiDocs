<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Infrastructure\Agent;

use Bitrix\Main\Update\Stepper;
use Bitrix\Bizproc\Public\Service\StorageItem\StorageCleanupService;

/**
 * Stepper deletes old storage items.
 */
class StorageCleanupAgent extends Stepper
{
	private const RECORD_LIMIT = 100;

	protected static $moduleId = 'bizproc';

	/**
	 * Run stepper as agent, for periodic runs.
	 *
	 * @return string
	 */
	public static function runAgent(): string
	{
		self::bind(0);

		return __METHOD__ . '();';
	}

	/**
	 * @inheritDoc
	 */
	public function execute(array &$option)
	{
		$service = new StorageCleanupService();
		$foundCount = $service->cleanupOldStorageItems(self::RECORD_LIMIT);
		if ($foundCount < self::RECORD_LIMIT)
		{
			return self::FINISH_EXECUTION;
		}

		return self::CONTINUE_EXECUTION;
	}
}
