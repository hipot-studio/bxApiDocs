<?php

namespace Bitrix\Disk;

use Bitrix\Disk\Internal\Service\Sync\Access\GroupAccessPublisher;
use Bitrix\Disk\Internals\Error\ErrorCollection;
use Bitrix\Disk\Internals\RightTable;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\UserTable;

class SocialnetworkHandlers
{
	private static $lastGroupIdAddedOnHit;
	private static $lastGroupOwnerIdAddedOnHit;

	/**
	 * @param array $fields
	 * @return void
	 */
	public static function onAfterUserAdd($fields)
	{
		if(!Loader::includeModule('socialnetwork') || empty($fields['ID']))
		{
			return;
		}

		$user = User::buildFromArray($fields);

		if (!$user->isBotUser())
		{
			Driver::getInstance()->addUserStorageByObject($user);
		}
	}

	/**
	 * @param array $fields
	 * @return void
	 */
	public static function onAfterUserUpdate($fields)
	{
		if(empty($fields['ID']))
		{
			return;
		}

		if(empty($fields['NAME']) && empty($fields['LAST_NAME']) && empty($fields['SECOND_NAME']))
		{
			return;
		}

		if(self::isExternalAuthId($fields['EXTERNAL_AUTH_ID'] ?? null))
		{
			return;
		}

		if (!ModuleManager::isModuleInstalled('socialnetwork'))
		{
			return;
		}

		$user = User::loadById($fields['ID']);
		if (!($user instanceof User))
		{
			return;
		}

		if (self::isExternalAuthId($user->getExternalAuthId()))
		{
			return;
		}

		// keep the bootstrap below the checks above: open lines rewrite contact names on every incoming message
		if (!Loader::includeModule('socialnetwork'))
		{
			return;
		}

		$userName = $user->getFormattedName();
		if (empty($userName))
		{
			return;
		}

		$userStorage = Driver::getInstance()->getStorageByUserId($user->getId());
		if (!($userStorage instanceof Storage))
		{
			return;
		}

		if ($userName != $userStorage->getName())
		{
			$userStorage->rename($userName);
		}
	}

	public static function onUserDelete($userId)
	{
		$storage = Driver::getInstance()->getStorageByUserId($userId);
		if(!$storage)
		{
			return true;
		}

		try
		{
			$storage->delete(self::getActivityUserId(), bypassDeletionRestriction: true);
		}
		catch(\Exception $e)
		{
			global $APPLICATION;
			if(is_object($APPLICATION))
			{
				$APPLICATION->throwException($e->getMessage());
			}
			return false;
		}

		return true;
	}

	public static function onSocNetGroupAdd($id, $fields)
	{
		self::$lastGroupIdAddedOnHit = $id;
		self::$lastGroupOwnerIdAddedOnHit = !empty($fields['OWNER_ID'])? $fields['OWNER_ID'] : false;
	}

	public static function onSocNetGroupUpdate($groupId, $fields)
	{
		if(empty($fields['NAME']))
		{
			return;
		}

		$storage = Driver::getInstance()->getStorageByGroupId($groupId);
		if(!$storage)
		{
			return;
		}

		$previousName = $storage->getName();
		$correctedPreviousName = Ui\Text::correctFilename($previousName);
		if($previousName === $fields['NAME'])
		{
			return;
		}

		if($storage->rename($fields['NAME']))
		{
			foreach($storage->getRootObject()->getSharingsAsReal() as $sharing)
			{
				if(!$sharing->isApproved())
				{
					continue;
				}

				$linkObject = $sharing->getLinkObject();
				if(!$linkObject)
				{
					continue;
				}

				if($linkObject->getName() === $previousName || $linkObject->getName() === $correctedPreviousName)
				{
					$linkObject->rename($storage->getName());
				}
			}
			unset($sharing);
		}
	}

	public static function onBeforeSocNetGroupDelete($groupId)
	{
		$storage = Driver::getInstance()->getStorageByGroupId($groupId);
		if (!$storage)
		{
			return true;
		}

		if ($storage->getRootObject()->countSizeOfVersions() > 0)
		{
			global $APPLICATION;
			$APPLICATION->ThrowException(
				Loc::getMessage("DISK_SOCNET_HANDLERS_DECLINE_GROUP_DELETING_WITH_FILES"),
				'DISK_NOT_EMPTY'
			);

			return false;
		}

		return true;
	}

	public static function onSocNetGroupDelete($groupId)
	{
		$storage = Driver::getInstance()->getStorageByGroupId($groupId);
		if(!$storage)
		{
			return;
		}

		// before the storage, the relations of the group and the rights naming it: the first two are what
		// the records are addressed by, the third is what says which objects they are about
		(new GroupAccessPublisher())->publishGroupRemoved(
			(int)$storage->getRootObjectId(),
			(int)$groupId,
			(int)$storage->getId(),
		);

		$storage->delete(self::getActivityUserId());

		RightTable::deleteBatch(array('ACCESS_CODE' => "SG{$groupId}_" . SONET_ROLES_OWNER));
		RightTable::deleteBatch(array('ACCESS_CODE' => "SG{$groupId}_" . SONET_ROLES_MODERATOR));
		RightTable::deleteBatch(array('ACCESS_CODE' => "SG{$groupId}_" . SONET_ROLES_USER));
	}

	public static function onSocNetUserToGroupDelete($id, $fields)
	{
		if(
			isset($fields['ROLE']) &&
			(
				$fields['ROLE'] == SONET_ROLES_USER ||
				$fields['ROLE'] == SONET_ROLES_MODERATOR ||
				$fields['ROLE'] == SONET_ROLES_OWNER
			)

		)
		{
			$userId = $fields['USER_ID'];
			$groupId = $fields['GROUP_ID'];

			if(empty($userId) || empty($groupId))
			{
				return;
			}

			$storage = Driver::getInstance()->getStorageByGroupId($groupId);
			if(!$storage)
			{
				return;
			}

			// the storage of the group closes for the user whether they had connected it or not
			(new GroupAccessPublisher())->publishMembershipChanged((int)$storage->getRootObjectId(), (int)$userId);

			/** @var Sharing $sharing */
			$sharing = Sharing::load(array(
				'=TO_ENTITY' => Sharing::CODE_USER . $userId,
				'REAL_OBJECT_ID' => $storage->getRootObjectId(),
				'REAL_STORAGE_ID' => $storage->getId(),
			));
			if(!$sharing)
			{
				return;
			}
			$sharing->delete(self::getActivityUserId());
		}
	}

	public static function onSocNetUserToGroupUpdate($id, $fields, $fieldsOld = [])
	{
		if (!Loader::includeModule('socialnetwork'))
		{
			return;
		}

		if (!isset($fields['ROLE']))
		{
			return;
		}

		$fieldsOld = is_array($fieldsOld) ? $fieldsOld : [];

		$memberRolesList = \Bitrix\Socialnetwork\UserToGroupTable::getRolesMember();
		$becameMember = in_array($fields['ROLE'], $memberRolesList);

		// An update that does not cross the line of membership moves no boundary. An old role that was
		// not handed over is read as the opposite of the new one: the crossing is then assumed rather
		// than established, and a record too many costs one comparison while a lost one costs the
		// object itself.
		$wasMember = isset($fieldsOld['ROLE']) ? in_array($fieldsOld['ROLE'], $memberRolesList) : !$becameMember;
		if ($becameMember === $wasMember)
		{
			return;
		}

		$relation = self::findGroupRelation($id, $fields, $fieldsOld);
		if (!$relation)
		{
			return;
		}

		[$userId, $groupId] = $relation;

		$storage = Driver::getInstance()->getStorageByGroupId($groupId);
		if(!$storage)
		{
			return;
		}

		// A role that became a membership opens the storage of the group just as an addition does, and a
		// role that stopped being one closes it just as an exclusion does. The feature of files is not
		// asked about here: it takes no code off the root of the group storage, so the boundary moves
		// whether it stands or not.
		(new GroupAccessPublisher())->publishMembershipChanged((int)$storage->getRootObjectId(), (int)$userId);

		if (!$becameMember || !\CSocNetFeatures::isActiveFeature(SONET_ENTITY_GROUP, $groupId, 'files'))
		{
			return;
		}

		$rootObject = $storage->getRootObject();
		if(!$rootObject->canRead($storage->getSecurityContext($userId)))
		{
			return;
		}

		$errorCollection = new ErrorCollection();
		Sharing::connectStorageToUserStorage($userId, $userId, $storage, $errorCollection);
	}

	/**
	 * The user and the group of the relation that was updated.
	 *
	 * An update carries the fields it changes and nothing else, so the pair comes from whichever of the
	 * three sources holds it: the fields of the update, the row as it stood before it, and the table.
	 *
	 * @return array{0: int, 1: int}|null
	 */
	private static function findGroupRelation($id, array $fields, array $fieldsOld): ?array
	{
		$userId = (int)($fields['USER_ID'] ?? $fieldsOld['USER_ID'] ?? 0);
		$groupId = (int)($fields['GROUP_ID'] ?? $fieldsOld['GROUP_ID'] ?? 0);

		if ($userId <= 0 || $groupId <= 0)
		{
			$query = \CSocNetUserToGroup::getList(array(), array('ID' => $id), false, false, array('USER_ID', 'GROUP_ID'));
			$row = $query ? $query->fetch() : null;
			if ($row)
			{
				$userId = $userId > 0 ? $userId : (int)$row['USER_ID'];
				$groupId = $groupId > 0 ? $groupId : (int)$row['GROUP_ID'];
			}
		}

		return $userId > 0 && $groupId > 0 ? [$userId, $groupId] : null;
	}

	public static function onSocNetUserToGroupAdd($id, $fields)
	{
		if(
			isset($fields['ROLE']) && isset($fields['USER_ID']) &&
			(
				$fields['ROLE'] == SONET_ROLES_USER ||
				$fields['ROLE'] == SONET_ROLES_MODERATOR ||
				$fields['ROLE'] == SONET_ROLES_OWNER
			)

		)
		{
			if(!(isset($fields['GROUP_ID'])))
			{
				$query = \CSocNetUserToGroup::getList(array(), array('ID' => $id), false, false, array('GROUP_ID', 'INITIATED_BY_USER_ID'));
				if($query)
				{
					$row = $query->fetch();
					if($row)
					{
						$groupId = $row['GROUP_ID'];
					}
				}
			}
			else
			{
				$groupId = $fields['GROUP_ID'];
			}


			if(!empty($groupId) && \CSocNetFeatures::isActiveFeature(SONET_ENTITY_GROUP, $groupId, 'files'))
			{
				$storage = Driver::getInstance()->getStorageByGroupId($groupId);
				if(!$storage)
				{
					return;
				}

				// the membership itself opens the storage of the group, whether it is connected or not
				(new GroupAccessPublisher())->publishMembershipChanged(
					(int)$storage->getRootObjectId(),
					(int)$fields['USER_ID']
				);

				$rootObject = $storage->getRootObject();
				if(!$rootObject->canRead($storage->getSecurityContext($fields['USER_ID'])))
				{
					return;
				}

				$errorCollection = new ErrorCollection();
				$createdBy = empty($fields['INITIATED_BY_USER_ID']) ?
					$fields['USER_ID'] : $fields['INITIATED_BY_USER_ID'];

				Sharing::connectStorageToUserStorage($createdBy, $fields['USER_ID'], $storage, $errorCollection);
			}
		}
	}

	public static function onSocNetFeaturesAdd($id, $fields)
	{
		if($fields
		   && isset($fields['ACTIVE'])
		   && $fields['ACTIVE'] == 'Y'
		   && isset($fields['FEATURE'])
		   && $fields['FEATURE'] == 'files'
		   && $fields['ENTITY_TYPE'] == 'G'
		   && $fields['ENTITY_ID']
		)
		{
			$groupId = $fields['ENTITY_ID'];

			$storage = Driver::getInstance()->addGroupStorage($groupId);
			if($storage && self::$lastGroupIdAddedOnHit == $groupId && self::$lastGroupOwnerIdAddedOnHit)
			{
				$rootObject = $storage->getRootObject();
				if(!$rootObject->canRead($storage->getSecurityContext(self::$lastGroupOwnerIdAddedOnHit)))
				{
					return;
				}

				$errorCollection = new ErrorCollection();
				Sharing::connectStorageToUserStorage(
					self::$lastGroupOwnerIdAddedOnHit,
					self::$lastGroupOwnerIdAddedOnHit,
					$storage,
					$errorCollection
				);
			}
		}
	}

	public static function onSocNetFeaturesUpdate($id, $fields)
	{
		static $updateGroupFilesFeatures = false;

		if(!$updateGroupFilesFeatures && isset($fields['ACTIVE']) && $fields['ACTIVE'] == 'N')
		{

			$features = \CSocNetFeatures::getById($id);
			if($features
				&& isset($features['FEATURE'])
				&& $features['FEATURE'] == 'files'
				&& $features['ENTITY_TYPE'] == 'G'
				&& $features['ENTITY_ID']
			)
			{
				$updateGroupFilesFeatures = true;
				$groupId = $features['ENTITY_ID'];

				if(empty($groupId))
				{
					return;
				}

				$storage = Driver::getInstance()->getStorageByGroupId($groupId);
				if(!$storage)
				{
					return;
				}

				$userId = self::getActivityUserId();
				foreach(Sharing::getModelList(array('filter' => array(
						'REAL_OBJECT_ID' => (int)$storage->getRootObjectId(),
						'REAL_STORAGE_ID' => (int)$storage->getId(),
				))) as $sharing)
				{
					$sharing->delete($userId);
				}
				unset($sharing);
			}
		}
		elseif(isset($fields['ACTIVE']) && $fields['ACTIVE'] == 'Y')
		{

			$features = \CSocNetFeatures::getById($id);
			if($features
				&& isset($features['FEATURE'])
				&& $features['FEATURE'] == 'files'
				&& $features['ENTITY_TYPE'] == 'G'
				&& $features['ENTITY_ID']
			)
			{
				$groupId = $features['ENTITY_ID'];
				if(!empty($groupId))
				{
					Driver::getInstance()->addGroupStorage($groupId);
				}
			}
		}
	}

	public static function onAfterFetchDiskUfEntity(array $entities)
	{
		foreach($entities as $name => $ids)
		{
			if($name === 'BLOG_POST')
			{
				if(is_array($ids))
				{
					Driver::getInstance()->getUserFieldManager()->loadBatchAttachedObjectInBlogPost($ids);
				}
			}
		}
		unset($name);
	}

	private static function getActivityUserId()
	{
		global $USER;
		if($USER && $USER instanceof \CUser)
		{
			$userId = $USER->getId();
			if(is_numeric($userId) && ((int)$userId > 0))
			{
				return $userId;
			}
		}

		return SystemUser::SYSTEM_USER_ID;
	}

	private static function isExternalAuthId(?string $externalAuthId): bool
	{
		return in_array((string)$externalAuthId, UserTable::getExternalUserTypes(), true);
	}
}