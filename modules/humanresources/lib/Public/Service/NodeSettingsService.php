<?php

namespace Bitrix\HumanResources\Public\Service;

use Bitrix\HumanResources\Repository\NodeSettingsRepository;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\NodeSettingsAuthorityType;
use Bitrix\HumanResources\Type\NodeSettingsAuthorityTypeCollection;
use Bitrix\HumanResources\Type\NodeSettingsType;

class NodeSettingsService
{
	private NodeSettingsRepository $nodeSettingsRepository;

	public function __construct(?NodeSettingsRepository $nodeSettingsRepository = null)
	{
		$this->nodeSettingsRepository = $nodeSettingsRepository ?? Container::getNodeSettingsRepository();
	}

	/**
	 * Get NodeSettingsType::BusinessProcTeamRule settings values
	 *
	 * @param int $nodeId
	 * @return NodeSettingsAuthorityTypeCollection
	 * @throws \Bitrix\HumanResources\Exception\WrongStructureItemException
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function getBusinessProcAuthoritySettings(int $nodeId): NodeSettingsAuthorityTypeCollection
	{
		$entityCollection = $this->nodeSettingsRepository->getByNodesAndTypes($nodeId, [NodeSettingsType::BusinessProcAuthority]);

		$authorityCollection = new NodeSettingsAuthorityTypeCollection();
		foreach ($entityCollection as $entity)
		{
			$authorityCollection->add(NodeSettingsAuthorityType::tryFrom($entity->settingsValue));
		}

		return $authorityCollection;
	}

	/**
	 * Same as getBusinessProcAuthoritySettings but for node array
	 *
	 * @param array $nodeIds
	 * @return array<NodeSettingsAuthorityTypeCollection> - associative array where key is nodeId and value is NodeSettingsAuthorityTypeCollection
	 * @throws \Bitrix\HumanResources\Exception\WrongStructureItemException
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function getBusinessProcAuthoritySettingsForNodes(array $nodeIds): array
	{
		$entityCollection = $this->nodeSettingsRepository->getByNodesAndTypes($nodeIds, [NodeSettingsType::BusinessProcAuthority]);

		$result = [];
		foreach ($entityCollection as $entity)
		{
			// Collect settingsValue per node_id
			$nodeId = $entity->nodeId;
			$result[$nodeId] ??= new NodeSettingsAuthorityTypeCollection();
			$result[$nodeId]->add(NodeSettingsAuthorityType::tryFrom($entity->settingsValue));
		}

		return $result;
	}

	/**
	 * Get NodeSettingsType::ReportsAuthority settings values
	 * that should represent who can get reports from users of the given node
	 *
	 * @param int $nodeId
	 * @return NodeSettingsAuthorityTypeCollection
	 * @throws \Bitrix\HumanResources\Exception\WrongStructureItemException
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function getReportsAuthoritySettings(int $nodeId): NodeSettingsAuthorityTypeCollection
	{
		$entityCollection = $this->nodeSettingsRepository->getByNodesAndTypes($nodeId, [NodeSettingsType::ReportsAuthority]);

		// workaround for teams and departments which was created before the new settings
		if ($entityCollection->count() === 0)
		{
			return new NodeSettingsAuthorityTypeCollection(NodeSettingsAuthorityType::DepartmentHead);
		}

		$authorityCollection = new NodeSettingsAuthorityTypeCollection();
		foreach ($entityCollection as $entity)
		{
			$authorityCollection->add(NodeSettingsAuthorityType::tryFrom($entity->settingsValue));
		}

		return $authorityCollection;
	}

	/**
	 * Same as getReportsAuthoritySettings but for node array
	 *
	 * @param array $nodeIds
	 * @return array<NodeSettingsAuthorityTypeCollection> - associative array where key is nodeId and value is NodeSettingsAuthorityTypeCollection
	 * @throws \Bitrix\HumanResources\Exception\WrongStructureItemException
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function getReportsAuthoritySettingsForNodes(array $nodeIds): array
	{
		$entityCollection = $this->nodeSettingsRepository->getByNodesAndTypes($nodeIds, [NodeSettingsType::ReportsAuthority]);

		$result = [];
		// Collect settings per node
		foreach ($entityCollection as $entity)
		{
			$nodeId = $entity->nodeId;
			$result[$nodeId] ??= new NodeSettingsAuthorityTypeCollection();
			$result[$nodeId]->add(NodeSettingsAuthorityType::tryFrom($entity->settingsValue));
		}

		// Apply default for nodes without values
		foreach ($nodeIds as $nodeId)
		{
			if (!array_key_exists($nodeId, $result) || empty($result[$nodeId]))
			{
				$result[$nodeId] = new NodeSettingsAuthorityTypeCollection(NodeSettingsAuthorityType::DepartmentHead);
			}
		}

		return $result;
	}

	/**
	 * @param array $nodeIds
	 * @return array
	 * @throws \Bitrix\HumanResources\Exception\WrongStructureItemException
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function getTeamReportExceptionsSettingsForNodes(array $nodeIds): array
	{
		$entityCollection = $this->nodeSettingsRepository->getByNodesAndTypes($nodeIds, [NodeSettingsType::TeamReportExceptions]);

		$result = [];
		foreach ($entityCollection as $entity)
		{
			$result[$entity->nodeId] ??= [];
			$result[$entity->nodeId][] = (int)$entity->settingsValue;
		}

		return $result;
	}
}
