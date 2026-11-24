<?php

namespace Bitrix\BIConnector\Public\Provider;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\Internal\Feature\SqlLabRestGate;
use Bitrix\BIConnector\Internal\Operation\Builder\ConnectorSkill\GetConnectorSkillOperation;
use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Closure;

final class ConnectorSkillProvider
{
	public function __construct(
		private readonly ?Closure $tariffReader = null,
		private readonly ?SqlLabRestGate $sqlLabGate = null,
	)
	{
	}

	/**
	 * Returns instruction text and adds the trusted actor readiness only to its table of contents.
	 *
	 * @param string|null $section Null selects the table of contents.
	 * @param string $language Instruction language.
	 * @param int $actorId User authenticated by the entry point.
	 * @param bool $appContext Whether the entry point has an actual application clientId.
	 * @return Result
	 */
	public function get(?string $section, string $language, int $actorId, bool $appContext): Result
	{
		$result = (new GetConnectorSkillOperation())->execute($section, $language);
		if ($section !== null || !$result->isSuccess())
		{
			return $result;
		}

		return $result->setData($result->getData() + [
			'readiness' => [
				'appContext' => $appContext,
				'biAccess' => $this->permission($actorId, ActionDictionary::ACTION_BIC_ACCESS),
				'externalConfig' => $this->permission($actorId, ActionDictionary::ACTION_BIC_EXTERNAL_DASHBOARD_CONFIG),
				'tariffAvailable' => $this->tariffAvailable(),
				'sqlLab' => $this->sqlLabAvailable($actorId),
			],
		]);
	}

	private function sqlLabAvailable(int $actorId): bool|string
	{
		try
		{
			return ($this->sqlLabGate ?? new SqlLabRestGate())->isEnabled($actorId);
		}
		catch (\Exception $exception)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);

			return 'unknown';
		}
	}

	private function permission(int $actorId, string $action): bool|string
	{
		try
		{
			return AccessController::getInstance($actorId)->check($action);
		}
		catch (\Exception $exception)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);

			return 'unknown';
		}
	}

	private function tariffAvailable(): bool|string
	{
		try
		{
			if ($this->tariffReader !== null)
			{
				$value = ($this->tariffReader)();
			}
			else
			{
				$value = Loader::includeModule('bitrix24') ? Feature::isExternalEntitiesEnabled() : 'not-applicable';
			}

			return in_array($value, [true, false, 'unknown', 'not-applicable'], true) ? $value : 'unknown';
		}
		catch (\Exception $exception)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);

			return 'unknown';
		}
	}
}
