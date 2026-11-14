<?php

declare(strict_types=1);

namespace Bitrix\Disk\Integration\HumanResources;

use Bitrix\Disk\Internal\Interface\TeamDirectoryInterface;
use Bitrix\HumanResources\Item\Node;
use Bitrix\HumanResources\Public\Service\Container as HumanResourcesContainer;
use Bitrix\HumanResources\Public\Service\NodeService;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Throwable;

/**
 * The only place where Disk touches humanresources: answers about teams of the default structure with scalars.
 */
class TeamDirectory implements TeamDirectoryInterface
{
	private ?bool $moduleAvailable = null;
	private bool $defaultStructureLoaded = false;
	private ?int $defaultStructureId = null;

	public function isModuleAvailable(): bool
	{
		return $this->moduleAvailable ??= $this->detectModuleAvailability();
	}

	public function hasTeams(): bool
	{
		$structureId = $this->getDefaultStructureId();
		if ($structureId === null)
		{
			return false;
		}

		try
		{
			$teams = $this->getNodeService()->findAll($structureId, [NodeEntityType::TEAM], limit: 1);
		}
		catch (Throwable $exception)
		{
			$this->writeToLog($exception);

			return false;
		}

		foreach ($teams as $team)
		{
			if ($team->isTeam())
			{
				return true;
			}
		}

		return false;
	}

	public function isTeamOfDefaultStructure(int $nodeId): bool
	{
		if ($nodeId <= 0)
		{
			return false;
		}

		$structureId = $this->getDefaultStructureId();
		if ($structureId === null)
		{
			return false;
		}

		try
		{
			$node = $this->getNodeService()->getById($nodeId);
		}
		catch (Throwable $exception)
		{
			$this->writeToLog($exception);

			return false;
		}

		return $node !== null && $node->isTeam() && $node->structureId === $structureId;
	}

	/**
	 * @param int[] $ids
	 * @return array<int, string>
	 */
	public function findTeamNames(array $ids): array
	{
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id) => $id > 0)));
		if (empty($ids))
		{
			return [];
		}

		$structureId = $this->getDefaultStructureId();
		if ($structureId === null)
		{
			return [];
		}

		try
		{
			$nodes = $this->getNodeService()->findAllByIds($ids, $structureId, [NodeEntityType::TEAM]);
		}
		catch (Throwable $exception)
		{
			$this->writeToLog($exception);

			return [];
		}

		$names = [];
		foreach ($nodes as $node)
		{
			$id = (int)$node->id;
			if ($node->isTeam() && $node->structureId === $structureId && in_array($id, $ids, true))
			{
				$names[$id] = (string)$node->name;
			}
		}

		return $names;
	}

	/**
	 * Reads the id of a deleted team from the "node" parameter of the humanresources OnNodeDeleted event.
	 */
	public function readDeletedTeamId(mixed $node): ?int
	{
		if (!$this->isModuleAvailable() || !($node instanceof Node) || !$node->isTeam())
		{
			return null;
		}

		$id = (int)$node->id;

		return $id > 0 ? $id : null;
	}

	protected function detectModuleAvailability(): bool
	{
		return Loader::includeModule('humanresources');
	}

	protected function getNodeService(): NodeService
	{
		return HumanResourcesContainer::getNodeService();
	}

	private function getDefaultStructureId(): ?int
	{
		if (!$this->isModuleAvailable())
		{
			return null;
		}

		if ($this->defaultStructureLoaded)
		{
			return $this->defaultStructureId;
		}

		try
		{
			$structureId = $this->getNodeService()->getRootNode()?->structureId;
		}
		catch (Throwable $exception)
		{
			$this->writeToLog($exception);

			return null;
		}

		$this->defaultStructureLoaded = true;
		$this->defaultStructureId = $structureId > 0 ? $structureId : null;

		return $this->defaultStructureId;
	}

	private function writeToLog(Throwable $exception): void
	{
		try
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);
		}
		catch (Throwable)
		{
		}
	}
}
