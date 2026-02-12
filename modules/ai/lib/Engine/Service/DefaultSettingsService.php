<?php declare(strict_types=1);

namespace Bitrix\AI\Engine\Service;

use Bitrix\AI\Engine\Cloud\CloudEngine;
use Bitrix\AI\Engine\Enum\TuningKey;
use Bitrix\AI\Engine\Service\Dto\ActionsByRegionResultDto;
use Bitrix\AI\Integration\Intranet\Settings\AISetting;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Web\Json;

class DefaultSettingsService
{
	protected const MODULE_ID = 'ai';
	protected const OPTION_NAME = 'version_engine_rule_actions';

	protected array $actionsNameList;

	public function updateDefaultSettings(array $actions): void
	{
		if (empty($actions))
		{
			return;
		}

		$this->getAISettings($actions)->save();
	}

	public function getActions(array $rulesData, string $region): array
	{
		$actionsNameList = $this->getActionsNameListInOptions();
		if (!empty($rulesData['regionList']) && is_array($rulesData['regionList']) && !empty($region))
		{
			$actionsByRegionResultDto = $this->getActionsByRegion(
				$rulesData['regionList'],
				$region,
				$actionsNameList
			);

			if ($actionsByRegionResultDto->hasRegion)
			{
				$this->addNewActionsToOption($actionsByRegionResultDto->namesForAddToOption);

				return $actionsByRegionResultDto->actionsForUpdate;
			}
		}

		if (empty($rulesData['default']['actions']))
		{
			return [];
		}

		[$actionsForUpdate, $namesForAddToOption] = $this->getActionsData(
			$rulesData['default']['actions'], $actionsNameList
		);

		$this->addNewActionsToOption($namesForAddToOption);

		return $actionsForUpdate;
	}

	protected function getActionsByRegion(
		array $regionList,
		string $region,
		array $actionsNameList
	): ActionsByRegionResultDto
	{
		if (empty($region))
		{
			return new ActionsByRegionResultDto([], [], false);
		}

		foreach ($regionList as $regionData)
		{
			if (
				empty($regionData['regions'])
				|| !is_array($regionData['regions'])
				|| empty($regionData['actions'])
				|| !is_array($regionData['actions'])
			)
			{
				continue;
			}

			if (!in_array($region, $regionData['regions'], true))
			{
				continue;
			}

			[$actionsForUpdate, $namesForAddToOption] = $this->getActionsData(
				$regionData['actions'], $actionsNameList
			);

			return new ActionsByRegionResultDto($actionsForUpdate, $namesForAddToOption, true);
		}

		return new ActionsByRegionResultDto([], [], false);
	}

	protected function getActionsData(array $actions, array $actionsNameList): array
	{
		$actionsForUpdate = [];
		$namesForAddToOption = [];

		foreach ($actions as $action)
		{
			if ($this->isInvalidDataInAction($action))
			{
				continue;
			}

			if (in_array($action['actionName'], $actionsNameList, true))
			{
				continue;
			}

			if ($action['className']::getEngineCodeProvider() !== $action['engineCode'])
			{
				continue;
			}

			$modelInConfig = TuningKey::tryFrom($action['codeForActivate']);
			if ($modelInConfig === null)
			{
				continue;
			}

			$actionsForUpdate[$modelInConfig->value] = $action['engineCode'];
			$namesForAddToOption[] = $action['actionName'];
		}

		return [$actionsForUpdate, $namesForAddToOption];
	}

	protected function isInvalidDataInAction(mixed $action): bool
	{
		return !is_array($action)
			|| empty($action['actionType'])
			|| $action['actionType'] !== 'setDefault'
			|| empty($action['codeForActivate'])
			|| empty($action['engineCode'])
			|| empty($action['className'])
			|| empty($action['actionName'])
			|| !is_subclass_of($action['className'], CloudEngine::class);
	}

	protected function getAISettings(array $actions): AISetting
	{
		return new AISetting($actions);
	}

	protected function addNewActionsToOption(array $newActions): void
	{
		if (empty($newActions))
		{
			return;
		}

		$this->actionsNameList = array_merge($this->getActionsNameListInOptions(), $newActions);

		try
		{
			$actionsNameList = Json::encode($this->actionsNameList);
		}
		catch (ArgumentException $exception)
		{
			$this->logMsg('Error in addNewActionsToOption ' . $exception->getMessage());

			return;
		}

		Option::set(static::MODULE_ID, static::OPTION_NAME, $actionsNameList);
	}

	protected function getActionsNameListInOptions(): array
	{
		if (isset($this->actionsNameList))
		{
			return $this->actionsNameList;
		}

		$this->actionsNameList = [];
		$versionsInOption = Option::get(static::MODULE_ID, static::OPTION_NAME, []);
		if (empty($versionsInOption))
		{
			return $this->actionsNameList;
		}

		try
		{
			$versionsInOptionArray = Json::decode($versionsInOption);
		}
		catch (ArgumentException $exception)
		{
			$this->logMsg('Error in getActionsNameListInOptions ' . $exception->getMessage());

			return $this->actionsNameList;
		}

		if (is_array($versionsInOptionArray))
		{
			$this->actionsNameList = $versionsInOptionArray;
		}

		return $this->actionsNameList;
	}

	protected function logMsg(string $msg): void
	{
		AddMessage2Log($msg);
	}
}
