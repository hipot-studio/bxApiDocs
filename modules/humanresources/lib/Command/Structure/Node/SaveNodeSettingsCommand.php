<?php

namespace Bitrix\HumanResources\Command\Structure\Node;

use Bitrix\HumanResources\Command\AbstractCommand;
use Bitrix\HumanResources\Command\Structure\Node\Handler\SaveNodeSettingsHandler;
use Bitrix\HumanResources\Config\Feature;
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
		$isDeputyCheckRequired = !Feature::instance()->isDeputyApprovesBPAvailable();

		$deputyTypes = $isDeputyCheckRequired ? [
			NodeSettingsAuthorityType::DepartmentDeputy->value,
			NodeSettingsAuthorityType::TeamDeputy->value,
		] : [];

		foreach ($this->settings as $type => $setting)
		{
			if (!NodeSettingsType::tryFrom($type) || !is_array($setting))
			{
				return false;
			}

			// if the $key is of getCasesWithAuthorityTypeValue, check if each $settingType value is a valid NodeSettingsAuthorityType
			if (
				!in_array(NodeSettingsType::from($type), NodeSettingsType::getCasesWithAuthorityTypeValue(), true)
				|| !isset($setting['values'])
				|| !is_array($setting['values'])
				|| !array_reduce(
					$setting['values'],
					fn($carry, $item) => $carry && NodeSettingsAuthorityType::tryFrom($item) !== null,
					true
				)
			)
			{
				return false;
			}

			if (
				$isDeputyCheckRequired
				&& !empty(array_intersect($setting['values'], $deputyTypes))
			) {
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
}
