<?php

namespace Bitrix\HumanResources\Compatibility\Event;

use Bitrix\HumanResources\Compatibility\Utils\DepartmentBackwardAccessCode;
use Bitrix\HumanResources\Config\Storage;
use Bitrix\HumanResources\Enum\EventName;
use Bitrix\HumanResources\Item\Node;
use Bitrix\HumanResources\Item\NodeMember;
use Bitrix\HumanResources\Item\Structure;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\MemberEntityType;
use Bitrix\HumanResources\Type\NodeEntityType;

use Bitrix\Main\Engine\CurrentUser;

class NodeEventHandler
{
	public static function onBeforeIBlockSectionUpdate($fields): void
	{
		if (!Storage::instance()->isCompanyStructureConverted(false))
		{
			return;
		}

		if (Container::getSemaphoreService()->isLocked('iblock-OnBeforeIBlockSectionUpdate'))
		{
			return;
		}

		if (!self::validateFields($fields))
		{
			return;
		}

		self::provideNode($fields);
	}

	/**
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\HumanResources\Exception\WrongStructureItemException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public static function onBeforeIBlockSectionDelete($sectionId): void
	{
		if (!Storage::instance()->isCompanyStructureConverted(false))
		{
			return;
		}

		if (Container::getSemaphoreService()->isLocked('iblock-OnBeforeIBlockSectionDelete'))
		{
			return;
		}

		$sectionId = (int) $sectionId;
		if ($sectionId < 1)
		{
			return;
		}

		$node = Container::getNodeRepository()->getByAccessCode(
			DepartmentBackwardAccessCode::makeById($sectionId)
		);

		if ($node)
		{
			Container::getNodeService()->removeNode($node);
		}
	}

	public static function onAfterIBlockSectionAdd($fields): void
	{
		if (!Storage::instance()->isCompanyStructureConverted(false))
		{
			return;
		}

		if (Container::getSemaphoreService()->isLocked('iblock-OnAfterIBlockSectionAdd'))
		{
			return;
		}

		if (!self::validateFields($fields))
		{
			return;
		}

		self::provideNode($fields);
	}

	private static function getStructureId(): ?int
	{
		$structure = Container::getStructureRepository()->getByXmlId(Structure::DEFAULT_STRUCTURE_XML_ID);

		return $structure?->id;
	}

	private static function getParentId(array $oldDepartment): ?int
	{
		$oldStructParentId = (int) $oldDepartment['IBLOCK_SECTION_ID'] ?? null;

		if ($oldStructParentId)
		{
			$found = null;
			try
			{
				$found = Container::getNodeRepository()->getByAccessCode(
					DepartmentBackwardAccessCode::makeById($oldStructParentId)
				);
			}
			catch (\Exception)
			{
			}

			return $found?->id;

		}

		return null;
	}

	private static function provideNode(array $fields): void
	{
		$structureId = self::getStructureId();
		$parentId = self::getParentId($fields);
		if (!$structureId)
		{
			Container::getStructureLogger()->write([
				'message' => 'Failed to provide Node. StructureId is null or 0',
				'userId' => CurrentUser::get()->getId(),
			]);

			return;
		}

		self::removeEventHandlersForNodeEvents();

		$node =
			Container::getNodeRepository()
				->getByAccessCode(
					DepartmentBackwardAccessCode::makeById((int)$fields['ID'])
				)
		;

		if ($node && $parentId !== null)
		{
			if ($node->parentId !== $parentId)
			{
				$parentNode = Container::getNodeRepository()->getById($parentId);
				Container::getNodeService()->moveNode($node, $parentNode);
			}

			$node->parentId = $parentId;
		}

		if ($node)
		{
			if (!empty($fields['NAME']))
			{
				$node->name = $fields['NAME'];
			}

			Container::getNodeRepository()->update($node);
		}

		if (!$node)
		{
			if (!$fields['NAME'])
			{
				$fields['NAME'] = \CIBlockSection::GetByID($fields['ID'])->Fetch()['NAME'] ?? '';
			}
			$node = Container::getNodeService()
				->insertAndMoveNode(
					new Node(
						name: $fields['NAME'],
						type: NodeEntityType::DEPARTMENT,
						structureId: self::getStructureId(),
						parentId: $parentId,
					),
				);
			Container::getStructureBackwardConverter()
				->createBackwardAccessCode(
					$node,
					$fields['ID']
				);
		}

		self::updateHead($node, $fields);
		self::restoreEventHandlersForNodeEvents();
	}

	private static function validateFields(array &$fields): bool
	{
		if (isset($fields['NAME']) && $fields['NAME'] === '')
		{
			return false;
		}

		if (isset($fields['RESULT']) && !$fields['RESULT'])
		{
			return false;
		}

		$requiredKeys = [
			'ID',
			'IBLOCK_ID',
		];

		$ibDept = \COption::GetOptionInt('intranet', 'iblock_structure', false);
		$currentIbId = (int)($fields['IBLOCK_ID'] ?? null);
		if ($ibDept !== $currentIbId || !$currentIbId)
		{
			return false;
		}
		$fields['UF_HEAD'] ??= [];

		foreach ($requiredKeys as $key)
		{
			if (!array_key_exists($key, $fields))
			{
				return false;
			}
		}

		return true;
	}

	private static function getRole(string $role): ?int
	{
		return Container::getRoleRepository()
			->findByXmlId(NodeMember::DEFAULT_ROLE_XML_ID[$role])
			?->id
		;
	}

	/**
	 * @return void
	 */
	private static function removeEventHandlersForNodeEvents(): void
	{
		Container::getEventSenderService()->removeEventHandlers('iblock', 'OnAfterIBlockSectionAdd');
		Container::getEventSenderService()->removeEventHandlers('iblock', 'OnBeforeIBlockSectionAdd');
		Container::getEventSenderService()->removeEventHandlers('humanresources', EventName::NODE_ADDED->name);
		Container::getEventSenderService()->removeEventHandlers('humanresources', EventName::NODE_UPDATED->name);
	}

	private static function restoreEventHandlersForNodeEvents(): void
	{
		Container::getSemaphoreService()->unlock('iblock-OnAfterIBlockSectionAdd');
		Container::getSemaphoreService()->unlock('iblock-OnBeforeIBlockSectionAdd');

	}

	/**
	 * @param \Bitrix\HumanResources\Item\Node|null $node
	 * @param array $fields
	 *
	 * @return void
	 */
	public static function updateHead(Node $node, array $fields): void
	{
		$headRole = self::getRole('HEAD');
		$employeeRole = self::getRole('EMPLOYEE');

		$heads = Container::getNodeMemberRepository()
			->findAllByRoleIdAndNodeId($headRole, $node->id)
		;
		$currentHead = $fields['UF_HEAD'] ?? null;
		$alreadyExisted = false;

		foreach ($heads as $head)
		{
			if ($head->entityId === (int)$currentHead)
			{
				$alreadyExisted = true;
				continue;
			}

			$head->role = $employeeRole;
			Container::getNodeMemberRepository()
				->update($head)
			;
		}

		if (!$alreadyExisted && $currentHead)
		{
			Container::getNodeMemberRepository()
				->create(
					new NodeMember(
						entityType: MemberEntityType::USER,
						entityId: (int)$currentHead,
						nodeId: $node->id,
						role: $headRole,
					)
				)
			;
		}
	}
}