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
		$entityCollection = $this->nodeSettingsRepository->getByNodeAndTypes($nodeId, [NodeSettingsType::BusinessProcAuthority]);

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
}
