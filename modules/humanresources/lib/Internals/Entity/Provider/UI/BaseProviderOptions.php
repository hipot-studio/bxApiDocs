<?php

namespace Bitrix\HumanResources\Internals\Entity\Provider\UI;

use Bitrix\HumanResources\Builder\Structure\Filter\SelectionCondition\Node\NodeAccessFilter;
use Bitrix\HumanResources\Type\StructureAction;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\HumanResources\Config\Feature;

class BaseProviderOptions
{
	public readonly array $includedNodeEntityTypes;
	public readonly ?StructureAction $structureAction;
	public readonly ?NodeAccessFilter $accessFilter;
	public readonly bool $isProviderActive;
	public readonly bool $restricted;
	public readonly ?array $allowedPermissionLevels;

	public function __construct(array $rawOptions = [])
	{
		$this->initIncludedNodeEntityTypes($rawOptions);
		$this->initAllowedPermissionLevels($rawOptions);
		$this->initStructureActionAndAccessFilter($rawOptions);
		$this->initRestricted($rawOptions);
		$this->initProviderActive();
	}

	private function initStructureActionAndAccessFilter(array $options): void
	{
		$this->structureAction = StructureAction::tryFrom($options['restricted']);

		$this->accessFilter = $this->structureAction
			? new NodeAccessFilter($this->structureAction, allowedLevels: $this->allowedPermissionLevels)
			: null
		;
	}

	private function initRestricted(array $options): void
	{
		$this->restricted = isset($options['restricted']) && $options['restricted'];
	}

	private function initIncludedNodeEntityTypes(array $options = []): void
	{
		$values = [];
		if (empty($options['includedNodeEntityTypes']) || !is_array($options['includedNodeEntityTypes']))
		{
			$this->includedNodeEntityTypes = [NodeEntityType::DEPARTMENT];

			return;
		}

		foreach ($options['includedNodeEntityTypes'] as $value)
		{
			if (!is_string($value))
			{
				continue;
			}

			$enum = NodeEntityType::tryFrom(strtoupper($value));
			if ($enum)
			{
				if ($enum === NodeEntityType::TEAM && !Feature::instance()->isCrossFunctionalTeamsAvailable())
				{
					continue;
				}

				$values[] = $enum;
			}
		}

		$this->includedNodeEntityTypes = $values;
	}

	private function initAllowedPermissionLevels(array $options): void
	{
		if (isset($options['allowedPermissionLevels']) && is_array($options['allowedPermissionLevels']))
		{
			$this->allowedPermissionLevels = array_map('intval', $options['allowedPermissionLevels']);
		}
		else
		{
			$this->allowedPermissionLevels = null;
		}
	}

	private function initProviderActive(): void
	{
		if ($this->restricted && !$this->structureAction)
		{
			$this->isProviderActive = false;
		}
		else
		{
			$this->isProviderActive = true;
		}
	}
}
