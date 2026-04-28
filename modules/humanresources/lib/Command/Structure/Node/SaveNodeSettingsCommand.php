<?php

namespace Bitrix\HumanResources\Command\Structure\Node;

use Bitrix\HumanResources\Command\AbstractCommand;
use Bitrix\HumanResources\Command\Structure\Node\Handler\SaveNodeSettingsHandler;
use Bitrix\HumanResources\Config\Feature;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\HumanResources\Type\NodeSettingsAuthorityType;
use Bitrix\HumanResources\Type\NodeSettingsType;
use Bitrix\Main\Result;
use Bitrix\Main\Error;
use Bitrix\HumanResources\Item;

class SaveNodeSettingsCommand extends AbstractCommand
{
	public function __construct(public readonly Item\Node $node, public readonly array $settings) {}

	protected function validate(): bool
	{
		$unavailableBpTypes = $this->getUnavailableTypes(NodeSettingsType::BusinessProcAuthority);
		$unavailableReportsTypes = $this->getUnavailableTypes(NodeSettingsType::ReportsAuthority);

		foreach ($this->settings as $type => $setting)
		{
			$nodeSettingType = NodeSettingsType::tryFrom($type);
			if (!$nodeSettingType || !is_array($setting))
			{
				return false;
			}

			if ($this->checkIfAuthorityTypeInvalid($nodeSettingType, $setting, $unavailableBpTypes, $unavailableReportsTypes)
				|| ($this->checkIfUserIdsTypeInvalid($nodeSettingType, $setting))
				|| ($this->checkIfBooleanTypeInvalid($nodeSettingType, $setting))
			)
			{
				return false;
			}
		}

		return true;
	}

	protected function execute(): Result
	{
		try
		{
			(new SaveNodeSettingsHandler())($this);
		}
		catch (\Exception $e)
		{
			return (new Result())->addError(new Error(
				$e->getMessage(),
				$e->getCode(),
			));
		}

		return new Result();
	}

	private function checkIfAuthorityTypeInvalid(
		NodeSettingsType $nodeSettingType,
		array $setting,
		array $unavailableBpTypes,
		array $unavailableReportsTypes
	): bool
	{
		if (!$nodeSettingType->isAuthorityType())
		{
			return false;
		}

		if (
			(isset($setting['values']))
			&& (!is_array($setting['values'])
				|| !array_reduce(
					$setting['values'],
					fn($carry, $item) => $carry && NodeSettingsAuthorityType::tryFrom($item) !== null,
					true
				)
			)
		)
		{
			return true;
		}

		$typesToCheck = $nodeSettingType === NodeSettingsType::BusinessProcAuthority
			? $unavailableBpTypes
			: $unavailableReportsTypes
		;
		if (!empty(array_intersect($setting['values'] ?? [], $typesToCheck)))
		{
			return true;
		}

		return false;
	}

	private function checkIfUserIdsTypeInvalid(NodeSettingsType $nodeSettingType, array $setting): bool
	{
		return ($nodeSettingType->isUserIdsType())
			&& isset($setting['values'])
			&& !is_array($setting['values'])
		;
	}

	private function checkIfBooleanTypeInvalid(NodeSettingsType $nodeSettingType, array $setting): bool
	{
		return $nodeSettingType->isBooleanType()
			&& (!isset($setting['value'])
				|| !in_array($setting['value'], ['Y', 'N'])
			)
		;
	}

	/**
	 * @return array<string>
	 */
	private function getUnavailableTypes(NodeSettingsType $type): array
	{
		$featureFlag = $type === NodeSettingsType::BusinessProcAuthority
			? Feature::instance()->isDeputyApprovesBPAvailable()
			: Feature::instance()->isDeputyGetReportsAvailable()
		;

		$isDeputyCheckRequired = !$featureFlag && $this->node->type === NodeEntityType::TEAM;
		$unavailableDeputyTypes = $isDeputyCheckRequired ? [
			NodeSettingsAuthorityType::DepartmentDeputy->value,
			NodeSettingsAuthorityType::TeamDeputy->value,
		] : [];
		$unavailableTeamTypes = $this->node->type !== NodeEntityType::TEAM ? [
			NodeSettingsAuthorityType::TeamHead->value,
			NodeSettingsAuthorityType::TeamDeputy->value,
			NodeSettingsAuthorityType::TeamEmployee->value,
			NodeSettingsAuthorityType::AllDepartmentHeads->value,
		] : [];
		$unavailableSettingsTypes = $type !== NodeSettingsType::ReportsAuthority
			? [NodeSettingsAuthorityType::AllDepartmentHeads->value]
			: []
		;

		return array_merge([NodeSettingsAuthorityType::DepartmentEmployee->value],
			$unavailableDeputyTypes,
			$unavailableTeamTypes,
			$unavailableSettingsTypes,
		);
	}
}

