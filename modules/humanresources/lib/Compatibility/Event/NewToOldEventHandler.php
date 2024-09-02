<?php

namespace Bitrix\HumanResources\Compatibility\Event;

use Bitrix\HumanResources\Compatibility\Utils\DepartmentBackwardAccessCode;
use Bitrix\HumanResources\Enum\EventName;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\MemberEntityType;
use Bitrix\HumanResources\Enum\LoggerEntityType;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Event;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;

class NewToOldEventHandler
{
	private const MODULE_NAME = 'humanresources-';
	/**
	 * @param Event $event
	 *
	 * @return void
	 */
	public static function onNodeAdded(Event $event): void
	{
		if (Container::getSemaphoreService()->isLocked(self::MODULE_NAME . EventName::NODE_ADDED->name))
		{
			return;
		}

		/** @var \Bitrix\HumanResources\Item\Node $node */
		$node = $event->getParameter('node');
		if (!isset($node))
		{
			return;
		}

		$companyStructureConverter = Container::getStructureBackwardConverter();
		\CIntranetRestService::setCheckAccess(false);

		Container::getEventSenderService()->removeEventHandlers('iblock', 'OnBeforeIBlockSectionDelete');
		Container::getEventSenderService()->removeEventHandlers('iblock', 'OnAfterIBlockSectionAdd');
		try
		{
			if ($companyStructureConverter->getCompanyStructureId() !== $node->structureId)
			{
				return;
			}
			$parent =
				$node->parentId
				? Container::getNodeRepository()
				->getById($node->parentId) : null
			;

			$parentId = DepartmentBackwardAccessCode::extractIdFromCode($parent?->accessCode);

			$newDepartmentId = \CIntranetRestService::departmentAdd([
				'NAME' => $node->name,
				'PARENT' => $parentId,
			]);

			$companyStructureConverter->createBackwardAccessCode($node, $newDepartmentId);

			Container::getNodeRepository()
				->update($node);
		}
		catch (\Exception)
		{
			Container::getStructureLogger()->write([
				'entityType' => LoggerEntityType::NODE->name,
				'entityId' => $node->id,
				'message' => 'onNodeAdded: Failed to update Node',
				'userId' => CurrentUser::get()->getId(),
			]);
		}
		finally
		{
			\CIntranetRestService::setCheckAccess(true);
		}
	}

	/**
	 * @param \Bitrix\Main\Event $event
	 *
	 * @return void
	 */
	public static function onNodeDeleted(Event $event): void
	{
		if (Container::getSemaphoreService()->isLocked(self::MODULE_NAME . EventName::NODE_DELETED->name))
		{
			return;
		}

		/** @var \Bitrix\HumanResources\Item\Node $node */
		$node = $event->getParameter('node');
		if (!isset($node))
		{
			return;
		}

		\CIntranetRestService::setCheckAccess(false);
		try
		{
			$companyStructureConverter = Container::getStructureBackwardConverter();

			if ($companyStructureConverter->getCompanyStructureId() !== $node->structureId || !$node->xmlId)
			{
				return;
			}

			Container::getEventSenderService()
				->removeEventHandlers('iblock', 'OnBeforeIBlockSectionDelete')
			;

			\CIntranetRestService::departmentDelete([
				'ID' => str_replace('D', '', $node->accessCode),
			]);
		}
		catch (\Exception)
		{
			Container::getStructureLogger()->write([
				'entityType' => LoggerEntityType::NODE->name,
				'entityId' => $node->id,
				'message' => 'onNodeDeleted: Failed to delete Node',
				'userId' => CurrentUser::get()->getId(),
			]);
		}
		finally
		{
			\CIntranetRestService::setCheckAccess(true);
		}
	}

	/**
	 * @param \Bitrix\Main\Event $event
	 *
	 * @return void
	 * @throws \Bitrix\HumanResources\Exception\ElementNotFoundException
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public static function onNodeUpdated(Event $event): void
	{
		if (Container::getSemaphoreService()->isLocked(self::MODULE_NAME . EventName::NODE_UPDATED->name))
		{
			return;
		}

		/** @var \Bitrix\HumanResources\Item\Node $node */
		$node = $event->getParameter('node');
		$fields = $event->getParameter('fields');
		if (!isset($node) || !isset($fields))
		{
			return;
		}

		if (!array_intersect(['name', 'parentId',], array_keys($fields)))
		{
			return;
		}

		$companyStructureConverter = Container::getStructureBackwardConverter();

		if ($companyStructureConverter->getCompanyStructureId() !== $node->structureId)
		{
			return;
		}

		$parent = $node->parentId ? Container::getNodeRepository()
			->getById($node->parentId) : null;

		$nodeId = DepartmentBackwardAccessCode::extractIdFromCode($node->accessCode);
		$parentId = DepartmentBackwardAccessCode::extractIdFromCode($parent?->accessCode);

		if (!$nodeId)
		{
			return;
		}

		Container::getEventSenderService()
			->removeEventHandlers('iblock', 'OnBeforeIBlockSectionUpdate')
		;

		\CIntranetRestService::setCheckAccess(false);
		try
		{
			\CIntranetRestService::departmentUpdate([
				'ID' => $nodeId,
				'NAME' => $node->name,
				'PARENT' => $parentId,
			]);
		}
		catch (\Exception)
		{
		}
		\CIntranetRestService::setCheckAccess(true);
	}

	/**
	 * @param Event $event
	 *
	 * @return void
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public static function onMemberAdded(Event $event): void
	{
		if (Container::getSemaphoreService()->isLocked(self::MODULE_NAME . EventName::MEMBER_ADDED->name))
		{
			return;
		}

		/** @var \Bitrix\HumanResources\Item\NodeMember $member */
		$member = $event->getParameter('member');

		if (!isset($member))
		{
			return;
		}

		if ($member->entityType !== MemberEntityType::USER)
		{
			return;
		}

		$nodes = Container::getNodeRepository()
			->findAllByUserId($member->entityId);

		$departments = [];

		foreach ($nodes as $node)
		{
			$accessCode = DepartmentBackwardAccessCode::extractIdFromCode($node->accessCode);
			if ($accessCode !== null)
			{
				$departments[] = $accessCode;
			}
		}

		$onAfterUserUpdateEvent = 'main-OnAfterUserUpdate';
		Container::getSemaphoreService()->lock($onAfterUserUpdateEvent);

		$user = new \CUser();
		$user->Update(
			$member->entityId,
			[
				'UF_DEPARTMENT' => $departments,
			]
		);

		if ($member->role === Container::getRoleHelperService()->getHeadRoleId())
		{
			$node = Container::getNodeRepository()->getById($member->nodeId);
			\CIntranetRestService::setCheckAccess(false);
			try
			{
				\CIntranetRestService::departmentUpdate(
					[
						'ID' => DepartmentBackwardAccessCode::extractIdFromCode($node->accessCode),
						'UF_HEAD' => $member->entityId,
					]
				);
			}
			catch (\Exception)
			{
			}
			\CIntranetRestService::setCheckAccess(true);
		}

		Container::getSemaphoreService()->unlock($onAfterUserUpdateEvent);
	}

	public static function onMemberDeleted(Event $event): void
	{
		if (
			Container::getSemaphoreService()
					 ->isLocked(self::MODULE_NAME . EventName::MEMBER_DELETED->name)
		)
		{
			return;
		}

		/** @var \Bitrix\HumanResources\Item\NodeMember $member */
		$member = $event->getParameter('member');

		if (!isset($member))
		{
			return;
		}

		if ($member->entityType !== MemberEntityType::USER)
		{
			return;
		}

		$onAfterUserDeleteEvent = 'main-OnAfterUserDelete';
		Container::getSemaphoreService()->lock($onAfterUserDeleteEvent);

		try
		{
			self::onMemberAdded($event);

			$node = Container::getNodeRepository()->getById($member->nodeId);

		}
		catch (ObjectPropertyException|ArgumentException|SystemException)
		{
			Container::getSemaphoreService()->unlock($onAfterUserDeleteEvent);

			return;
		}

		if (!str_starts_with($node->accessCode, 'D'))
		{
			Container::getSemaphoreService()->unlock($onAfterUserDeleteEvent);

			return;
		}

		\CIntranetRestService::setCheckAccess(false);
		$departmentId = DepartmentBackwardAccessCode::extractIdFromCode($node->accessCode);
		$memberDepartment = \CIntranetRestService::departmentGet(
			[
				'ID' => $departmentId,
			],
		)[0] ?? null;

		if (!$memberDepartment || $memberDepartment['UF_HEAD'] !== $member->entityId)
		{
			Container::getSemaphoreService()->unlock($onAfterUserDeleteEvent);

			return;
		}

		try
		{
			\CIntranetRestService::departmentUpdate(
				[
					'ID' => $departmentId,
					'UF_HEAD' => null,
				],
			);
		}
		catch (\Exception)
		{
		}

		\CIntranetRestService::setCheckAccess(true);
		Container::getSemaphoreService()->unlock($onAfterUserDeleteEvent);
	}
}