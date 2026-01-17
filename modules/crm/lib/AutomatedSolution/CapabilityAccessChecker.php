<?php

namespace Bitrix\Crm\AutomatedSolution;

use Bitrix\Crm\AutomatedSolution\Entity\AutomatedSolutionTable;
use Bitrix\Crm\Integration\Bitrix24\License;
use Bitrix\Crm\Integration\Rest\Marketplace\Client;
use Bitrix\Crm\Restriction\AutomatedSolutionImportedLimit;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Traits\Singleton;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use CCrmOwnerType;

final class CapabilityAccessChecker
{
	use Singleton;

	private array $entityTypes = [];
	private array $automatedSolutions = [];
	private ?bool $isLocked = null;

	public function isLockedEntityType(int $entityTypeId): bool
	{
		if (isset($this->entityTypes[$entityTypeId]))
		{
			return $this->entityTypes[$entityTypeId];
		}

		if (!CCrmOwnerType::isPossibleDynamicTypeId($entityTypeId))
		{
			$this->entityTypes[$entityTypeId] = false;

			return $this->entityTypes[$entityTypeId];
		}

		if (!Loader::includeModule('bitrix24'))
		{
			$this->entityTypes[$entityTypeId] = false;

			return $this->entityTypes[$entityTypeId];
		}

		if (!$this->isEntityTypeIdBoundToImportedAutomatedSolution($entityTypeId))
		{
			$this->entityTypes[$entityTypeId] = false;

			return false;
		}

		$this->entityTypes[$entityTypeId] = $this->isLocked();

		return $this->entityTypes[$entityTypeId];
	}

	public function isLockedAutomatedSolution(int $automatedSolutionId, ?int $sourceId = null): bool
	{
		if (isset($this->automatedSolutions[$automatedSolutionId]))
		{
			return $this->automatedSolutions[$automatedSolutionId];
		}

		if (!Loader::includeModule('bitrix24'))
		{
			$this->automatedSolutions[$automatedSolutionId] = false;

			return $this->automatedSolutions[$automatedSolutionId];
		}

		$isImported = $sourceId === null ? $this->isImportedAutomatedSolution($automatedSolutionId) : $this->isImported($sourceId);
		$this->automatedSolutions[$automatedSolutionId] = $isImported && $this->isLocked();

		return $this->automatedSolutions[$automatedSolutionId];
	}

	private function isImportedAutomatedSolution(int $automatedSolutionId): bool
	{
		$automatedSolution = Container::getInstance()->getAutomatedSolutionManager()->getAutomatedSolution($automatedSolutionId);
		if (!$automatedSolution)
		{
			return false;
		}

		return $this->isImported((int)($automatedSolution['SOURCE_ID'] ?? 0));
	}

	private function isEntityTypeIdBoundToImportedAutomatedSolution(int $entityTypeId): bool
	{
		$manager = new AutomatedSolutionManager();
		$type = Container::getInstance()->getTypeByEntityTypeId($entityTypeId);
		if (!$type || !$manager->isTypeBoundToAnyAutomatedSolution($type))
		{
			return false;
		}

		$clone = clone($type); // @todo
		$clone->fill(['AUTOMATED_SOLUTION']);

		return $this->isImported((int)$clone->getAutomatedSolution()?->getSourceId());
	}

	private function isImported(int $sourceId): bool
	{
		return AutomatedSolutionTable::isImportedFromMarketplace($sourceId);
	}

	private function isLocked(): bool
	{
		if ($this->isLocked === null)
		{
			$this->isLocked = $this->isLimitRestriction() || $this->isLicenseOverdue() || $this->isMarketOverdue();
		}

		return $this->isLocked;
	}

	private function isLimitRestriction(): bool
	{
		return (new AutomatedSolutionImportedLimit())->isRestricted();
	}

	private function isLicenseOverdue(): bool
	{
		return (new License())->isOverdue();
	}

	private function isMarketOverdue(): bool
	{
		return (new Client())->getSubscriptionFinalDate()?->getTimestamp() < (new DateTime())->getTimestamp();
	}
}
