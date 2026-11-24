<?php

declare(strict_types=1);

namespace Bitrix\Pull\Service\PushSchedule;

use Bitrix\Main\Loader;

final class ReadinessService
{
	private const CONTRACTS = [
		'im' => [\Bitrix\Im\Configuration\Notification::class, []],
		'timeman' => [
			\Bitrix\Timeman\V2\Public\Provider\RecordProvider::class,
			['getCurrentStates', 'getAutomaticModeAvailability', 'getFreshAutomaticModeAvailability'],
		],
		'intranet' => [
			\Bitrix\Intranet\Public\Provider\User\AbsenceProvider::class,
			['getUsersBlockingAbsences', 'getActiveVacation'],
		],
		'mobile' => [\Bitrix\Mobile\Push\ScheduleCompatibility::class, []],
	];

	private bool $checked = false;
	private ?string $unavailableReason = null;

	public function __construct(
		private readonly ?\Closure $moduleLoader = null,
		private readonly ?\Closure $classResolver = null,
		private readonly ?\Closure $schemaResolver = null,
	)
	{
	}

	public function isReady(): bool
	{
		return $this->getUnavailableReason() === null;
	}

	public function getUnavailableReason(): ?string
	{
		if (!$this->checked)
		{
			$this->unavailableReason = $this->checkContracts();
			$this->checked = true;
		}

		if ($this->unavailableReason !== null)
		{
			return $this->unavailableReason;
		}

		try
		{
			return $this->schemaResolver !== null
				? ($this->schemaResolver)()
				: (new SchemaReadiness())->getUnavailableReason();
		}
		catch (\Throwable)
		{
			return 'schema:probe_failed';
		}
	}

	private function checkContracts(): ?string
	{
		foreach (self::CONTRACTS as $moduleId => [$class, $methods])
		{
			try
			{
				$included = $this->moduleLoader !== null
					? ($this->moduleLoader)($moduleId)
					: Loader::includeModule($moduleId);
				if (!$included)
				{
					return 'module:' . $moduleId;
				}
				$class = $this->classResolver !== null ? ($this->classResolver)($class) : $class;
				$versionConstant = $class . '::PUSH_SCHEDULE_VERSION';
				if (
					!class_exists($class)
					|| !defined($versionConstant)
					|| !is_int(constant($versionConstant))
					|| constant($versionConstant) < 1
				)
				{
					return 'contract:' . $moduleId;
				}
				foreach ($methods as $method)
				{
					if (!method_exists($class, $method) || !(new \ReflectionMethod($class, $method))->isPublic())
					{
						return 'api:' . $moduleId . '.' . $method;
					}
				}
			}
			catch (\Throwable)
			{
				return 'integration:' . $moduleId;
			}
		}

		return null;
	}
}
