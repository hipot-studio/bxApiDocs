<?php

namespace Bitrix\HumanResources\Compatibility\Event;

use Bitrix\HumanResources\Builder\Structure\Filter\Column\EntityIdFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\NodeMemberFilter;
use Bitrix\HumanResources\Builder\Structure\NodeMemberDataBuilder;
use Bitrix\HumanResources\Compatibility\Utils\DepartmentBackwardAccessCode;
use Bitrix\HumanResources\Config\Storage;
use Bitrix\HumanResources\Enum\EventName;
use Bitrix\HumanResources\Enum\LoggerEntityType;
use Bitrix\HumanResources\Exception\CreationFailedException;
use Bitrix\HumanResources\Internals\Service\Container as InternalContainer;
use Bitrix\HumanResources\Item;
use Bitrix\HumanResources\Item\Collection\NodeMemberCollection;
use Bitrix\HumanResources\Item\NodeMember;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Service\UserService;
use Bitrix\HumanResources\Type\MemberEntityType;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\UserTable;
use Bitrix\Main\Web\Json;

class UserEventHandler
{
	public static function onAfterUserUpdate($fields): void
	{
		if ($fields['UF_CONNECTOR_MD5'] ?? false)
		{
			return;
		}

		if (!Storage::instance()->isCompanyStructureConverted(false))
		{
			return;
		}

		if (Container::getSemaphoreService()
			->isLocked('main-OnAfterUserUpdate'))
		{
			return;
		}

		if (self::isDepartmentChanged($fields))
		{
			self::updateNodeMemberLink($fields);
		}

		self::updateNodeMemberActive($fields);

		NewToOldEventHandler::clearCacheInBackground();
	}

	public static function onAfterUserDelete($id): void
	{
		if (!Storage::instance()->isCompanyStructureConverted(false))
		{
			return;
		}

		if (Container::getSemaphoreService()
			->isLocked('main-OnAfterUserDelete'))
		{
			return;
		}

		if (!is_int($id))
		{
			return;
		}

		$fields = [
			'ID' => $id,
			'UF_DEPARTMENT' => [],
		];

		self::updateNodeMemberLink($fields);

		NewToOldEventHandler::clearCacheInBackground();
	}

	public static function onAfterUserAdd($fields): void
	{
		if (self::isUserExternal($fields))
		{
			return;
		}

		if (!Storage::instance()->isCompanyStructureConverted(false))
		{
			return;
		}

		if (Container::getSemaphoreService()
			->isLocked('main-OnAfterUserAdd'))
		{
			return;
		}

		if (self::isDepartmentChanged($fields))
		{
			self::updateNodeMemberLink($fields);
		}

		NewToOldEventHandler::clearCacheInBackground();
	}

	private static function updateNodeMemberLink(array $fields): void
	{
		$departments = $fields['UF_DEPARTMENT'];
		if (!is_array($departments))
		{
			return;
		}

		$userId = $fields['ID'];
		$isActive = ($fields['ACTIVE'] ?? 'N') === 'Y';

		$nodeMemberFilter = new NodeMemberFilter(
			entityIdFilter: EntityIdFilter::fromEntityId($userId),
			entityType: MemberEntityType::USER,
			active: null,
		);
		$currentLinks = (new NodeMemberDataBuilder())
			->setFilter($nodeMemberFilter)
			->getAll()
		;

		Container::getEventSenderService()->removeEventHandlers(
			'humanresources',
			EventName::OnMemberDeleted->name
		);

		Container::getEventSenderService()->removeEventHandlers(
			'humanresources',
			EventName::OnMemberAdded->name
		);

		array_walk(
			$departments, fn(&$department) => $department = DepartmentBackwardAccessCode::makeById((int)$department)
		);

		$removedMembers = [];
		$addedMembers = [];

		try
		{
			$nodes = Container::getNodeRepository()
				->findAllByAccessCodes($departments)
			;
			$nodeIds = array_map(
				fn($node) => $node->id,
				iterator_to_array($nodes)
			);

			foreach ($currentLinks as $link)
			{
				if (!in_array($link->nodeId, $nodeIds))
				{
					$node = Container::getNodeRepository()
						->getById($link->nodeId)
					;

					if (!$node)
					{
						Container::getNodeMemberRepository()
							->remove($link);
						$currentLinks->remove($link);
						// we don't send notifications for removed node

						continue;
					}

					if (!$node->accessCode)
					{
						continue;
					}

					if ($node->type !== NodeEntityType::DEPARTMENT)
					{
						continue;
					}

					Container::getNodeMemberRepository()
						->remove($link)
					;
					$currentLinks->remove($link);
					$removedMembers[] = ['member' => $link, 'node' => $node];
				}
			}

			$existingNodeIds = array_map(
				fn($link) => $link->nodeId,
				iterator_to_array($currentLinks)
			);
			$newNodeIds = array_diff($nodeIds, $existingNodeIds);

			if ($newNodeIds)
			{
				foreach ($newNodeIds as $nodeId)
				{
					$nodeMember = new NodeMember(
						entityType: MemberEntityType::USER,
						entityId: $userId,
						nodeId: $nodeId,
						active: $isActive,
					);

					try
					{
						Container::getNodeMemberRepository()
							->create($nodeMember)
						;
						$addedMembers[] = $nodeMember;
					}
					catch (CreationFailedException $exception)
					{
						$message = $exception->getErrors()->getValues()[0] ?? null;
						Container::getStructureLogger()
							->write(
								[
									'message' => 'Node member create failure: '
										. $message?->getMessage()
										. ' ' . Json::encode($nodeMember)
									,
									'entityType' => LoggerEntityType::MEMBER_USER->name,
									'entityId' => $userId,
									'userId' => \Bitrix\Main\Engine\CurrentUser::get()->getId(),
								],
							)
						;
					}
				}
			}
		}
		catch (ObjectPropertyException|ArgumentException|SystemException)
		{
		}

		self::sendNotifications($removedMembers, $addedMembers);

		NewToOldEventHandler::clearCacheInBackground(
			shouldClearHRCacheImmediately: true,
		);

		Container::getCacheManager()->clean(sprintf(UserService::USER_DEPARTMENT_EXISTS_KEY, $userId));
	}

	/**
	 * Sends add/remove notifications for members changed via the old user API,
	 * where native HR event handlers are intentionally disabled to avoid infinite loops.
	 *
	 * @param array<array{member: NodeMember, node: ?Item\Node}> $removedMembers
	 * @param NodeMember[] $addedMembers
	 */
	private static function sendNotifications(array $removedMembers, array $addedMembers): void
	{
		$notificationService = InternalContainer::getNodeMemberNotificationService();
		$nodeRepository = Container::getNodeRepository();

		$notificationService->preloadEmployeeNames(
			(new NodeMemberCollection(...array_column($removedMembers, 'member')))
				->merge(new NodeMemberCollection(...$addedMembers)),
		);

		foreach ($removedMembers as $entry)
		{
			$node = $entry['node'];
			if ($node === null)
			{
				continue;
			}

			$notificationService->sendAllRemoveMemberNotifications($entry['member'], $node);
		}

		$addedByNodeId = [];
		foreach ($addedMembers as $member)
		{
			$addedByNodeId[$member->nodeId][] = $member;
		}

		foreach ($addedByNodeId as $nodeId => $members)
		{
			$node = $nodeRepository->getById($nodeId);
			if ($node === null)
			{
				continue;
			}

			$collection = new NodeMemberCollection();
			foreach ($members as $member)
			{
				$collection->add($member);
			}

			$notificationService->sendAllAddMemberNotifications($collection, $node);
		}
	}

	private static function updateNodeMemberActive(array $fields): void
	{
		$user = UserTable::query()
			->setSelect(
				[
					'ID',
					'ACTIVE',
				],
			)
			->where('ID', $fields['ID'])
			->fetchObject()
		;

		if (!$user)
		{
			return;
		}

		$active = $user->getActive();

		$userId = (int)$fields['ID'];

		try
		{
			Container::getNodeMemberRepository()
				->setActiveByEntityTypeAndEntityId(MemberEntityType::USER, $userId, $active)
			;
		}
		catch (ObjectPropertyException|ArgumentException|SystemException $e)
		{
		}
	}

	private static function isDepartmentChanged(array $fields): bool
	{
		$requiredKeys = [
			'RESULT',
			'UF_DEPARTMENT',
		];

		if (!self::hasRequiredKeys($fields, $requiredKeys))
		{
			return false;
		}

		return !empty($fields['RESULT']);
	}

	protected static function isUserExternal(array $fields): bool
	{
		return ($fields['EXTERNAL_AUTH_ID'] ?? false) && in_array($fields['EXTERNAL_AUTH_ID'], UserTable::getExternalUserTypes());
	}

	private static function isActiveChanged(array $fields): bool
	{
		$requiredKeys = [
			'RESULT',
			'ACTIVE',
		];

		if (!self::hasRequiredKeys($fields, $requiredKeys))
		{
			return false;
		}

		return !empty($fields['RESULT']);
	}

	private static function hasRequiredKeys(array $fields, array $requiredKeys): bool
	{
		foreach ($requiredKeys as $key)
		{
			if (!array_key_exists($key, $fields))
			{
				return false;
			}
		}

		return true;
	}
}