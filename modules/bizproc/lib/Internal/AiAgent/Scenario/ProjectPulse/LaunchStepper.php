<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AiAgent\Scenario\ProjectPulse;

use Bitrix\Bizproc\Internal\AiAgent\Scenario\ProjectPulse\Command\LaunchProjectPulseCommand;
use Bitrix\Main;
use Bitrix\Main\Loader;
use Bitrix\Socialnetwork\WorkgroupTable;

final class LaunchStepper extends Main\Update\Stepper
{
	protected static $moduleId = 'bizproc';

	private const BATCH_SIZE = 50;

	public function execute(array &$result)
	{
		if (!Loader::includeModule('socialnetwork'))
		{
			return self::FINISH_EXECUTION;
		}

		$params = $this->getOuterParams();
		$systemTemplateId = (int)reset($params);
		if ($systemTemplateId <= 0)
		{
			return self::FINISH_EXECUTION;
		}

		if (!isset($result['maxWorkgroupId']))
		{
			$result['maxWorkgroupId'] = $this->resolveMaxWorkgroupId();
		}

		$maxWorkgroupId = (int)$result['maxWorkgroupId'];
		if ($maxWorkgroupId <= 0)
		{
			return self::FINISH_EXECUTION;
		}

		if (!isset($result['count']))
		{
			$result['count'] = $this->countProjects($maxWorkgroupId);
		}

		$lastWorkgroupId = (int)($result['lastWorkgroupId'] ?? 0);
		$processed = (int)($result['steps'] ?? 0);

		$filter = [
			'=PROJECT' => 'Y',
			'=CLOSED' => 'N',
			'=ACTIVE' => 'Y',
			'<=ID' => $lastWorkgroupId > 0 ? $lastWorkgroupId - 1 : $maxWorkgroupId,
		];

		$rows = WorkgroupTable::query()
			->setSelect(['ID', 'OWNER_ID'])
			->setFilter($filter)
			->setOrder(['ID' => 'DESC'])
			->setLimit(self::BATCH_SIZE)
			->fetchAll()
		;

		if (!$rows)
		{
			return self::FINISH_EXECUTION;
		}

		$eligibilityChecker = new ProjectEligibilityChecker();

		foreach ($rows as $row)
		{
			$workgroupId = (int)$row['ID'];
			$ownerId = (int)$row['OWNER_ID'];

			$result['lastWorkgroupId'] = $workgroupId;
			$result['steps'] = ++$processed;

			if ($ownerId <= 0)
			{
				continue;
			}

			if (!$eligibilityChecker->isEligible($workgroupId))
			{
				continue;
			}

			$launchResult = (new LaunchProjectPulseCommand($systemTemplateId, $workgroupId, $ownerId))->run();

			if (!$launchResult->isSuccess())
			{
				\AddMessage2Log(
					sprintf(
						'ProjectPulse launch stepper: failed for workgroup #%d (owner #%d): %s',
						$workgroupId,
						$ownerId,
						implode('; ', $launchResult->getErrorMessages()),
					),
					'bizproc',
				);
			}
		}

		return self::CONTINUE_EXECUTION;
	}

	public static function getTitle(): string
	{
		return 'AI Project Pulse rollout';
	}

	private function countProjects(int $maxWorkgroupId): int
	{
		return (int)WorkgroupTable::getCount([
			'=PROJECT' => 'Y',
			'=CLOSED' => 'N',
			'=ACTIVE' => 'Y',
			'<=ID' => $maxWorkgroupId,
		]);
	}

	private function resolveMaxWorkgroupId(): int
	{
		$row = WorkgroupTable::query()
			->setSelect(['ID'])
			->setFilter(['=PROJECT' => 'Y', '=CLOSED' => 'N', '=ACTIVE' => 'Y'])
			->setOrder(['ID' => 'DESC'])
			->setLimit(1)
			->fetch()
		;

		return $row ? (int)$row['ID'] : 0;
	}
}
