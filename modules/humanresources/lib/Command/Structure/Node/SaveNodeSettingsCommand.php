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

			// if the $key is of getCasesWithAuthorityTypeValue, check if each $settingType value is a valid NodeSettingsAuthorityType
			if (
				!in_array(NodeSettingsType::from($type), NodeSettingsType::getCasesWithAuthorityTypeValue(), true)
				|| ($this->node->type === NodeEntityType::TEAM && !isset($setting['values']))
				|| (isset($setting['values'])
					&& (!is_array($setting['values'])
						|| !array_reduce(
							$setting['values'],
							fn($carry, $item) => $carry && NodeSettingsAuthorityType::tryFrom($item) !== null,
							true
						)
					)
				)
			)
			{
				return false;
			}

			$typesToCheck = $nodeSettingType === NodeSettingsType::BusinessProcAuthority
				? $unavailableBpTypes
				: $unavailableReportsTypes
			;
			if (is_array($setting['values']) && !empty(array_intersect($setting['values'], $typesToCheck)))
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
		] : [];
		return array_merge([NodeSettingsAuthorityType::DepartmentEmployee->value],
			$unavailableDeputyTypes,
			$unavailableTeamTypes,
		);
	}
}
