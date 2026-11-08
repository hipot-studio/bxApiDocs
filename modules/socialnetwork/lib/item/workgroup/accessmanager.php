<?php

/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage socialnetwork
 * @copyright 2001-2022 Bitrix
 */
namespace Bitrix\Socialnetwork\Item\Workgroup;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Loader;
use Bitrix\Socialnetwork\V2\Feature;
use Bitrix\Socialnetwork\EO_UserToGroup;
use Bitrix\Socialnetwork\EO_Workgroup;
use Bitrix\Socialnetwork\EO_WorkgroupFavorites;
use Bitrix\Socialnetwork\Permission\Trait\AccessErrorTrait;
use Bitrix\Socialnetwork\Permission\User\UserModel;
use Bitrix\Socialnetwork\Permission\Verdict\GroupViewVerdict;
use Bitrix\Socialnetwork\Provider\GroupProvider;
use Bitrix\Socialnetwork\UserToGroupTable;

class AccessManager
{
	use AccessErrorTrait;

	public const ERROR_EXCLUDE_FROM_STRUCTURE = 'EXCLUDE_DENIED_FROM_STRUCTURE';
	public const ERROR_LEAVE_FROM_STRUCTURE = 'LEAVE_DENIED_FROM_STRUCTURE';

	protected EO_Workgroup $group;
	protected ?EO_UserToGroup $targetUserRelation = null;
	protected ?EO_UserToGroup $currentUserRelation = null;
	protected ?EO_WorkgroupFavorites $currentUserFavorites = null;
	protected bool $isCurrentUserModuleAdmin = false;
	protected int $currentUserId = 0;
	private UserModel $user;

	public function __construct(
		EO_Workgroup $group,
		?EO_UserToGroup $targetUserRelation,
		?EO_UserToGroup $currentUserRelation,
		?array $additionalEntityList = [],
		?array $additionalParams = []
	)
	{
		$this->group = $group;
		$this->targetUserRelation = $targetUserRelation;
		$this->currentUserRelation = $currentUserRelation;

		if (isset($additionalParams['userId']))
		{
			$this->currentUserId = (int)$additionalParams['userId'];
			$this->isCurrentUserModuleAdmin = self::isModuleAdmin($this->currentUserId);
		}
		else
		{
			$this->isCurrentUserModuleAdmin = self::isCurrentUserModuleAdmin((bool)($additionalParams['checkAdminSession'] ?? true));
			$this->currentUserId = \Bitrix\Socialnetwork\Helper\User::getCurrentUserId();
		}

		$this->user = UserModel::createFromId($this->currentUserId);

		if (is_array($additionalEntityList) && !empty($additionalEntityList))
		{
			if (
				$additionalEntityList['currentUserFavorites']
				&& get_class($additionalEntityList['currentUserFavorites']) === EO_WorkgroupFavorites::class
			)
			{
				$this->currentUserFavorites = $additionalEntityList['currentUserFavorites'];
			}
		}
	}

	// Session admin mode applies only to the active user; foreign users require a module-admin check.
	public static function isModuleAdmin(int $userId): bool
	{
		if (
			$userId > 0
			&& $userId === \Bitrix\Socialnetwork\Helper\User::getCurrentUserId()
			&& self::isRealSession()
		)
		{
			return self::isCurrentUserModuleAdmin(true);
		}

		return \CSocNetUser::IsUserModuleAdmin($userId);
	}

	private static function isCurrentUserModuleAdmin(bool $checkSession = false): bool
	{
		static $result = [
			'Y' => null,
			'N' => null
		];
		$cacheKey = ($checkSession ? 'Y' : 'N');

		if ($result[$cacheKey] === null)
		{
			$result[$cacheKey] = \CSocNetUser::isCurrentUserModuleAdmin(SITE_ID, $checkSession);
		}

		return $result[$cacheKey];
	}

	// Phase 0 (task 718250): a real (stateful) session vs a stateless one (REST/webhook/OAuth/CLI).
	// The reliable signal is the session handler type, not PHP_SAPI or isStarted()/isActive():
	// a stateless request uses NullSessionHandler (see Bitrix\Main\Session\Session).
	private static function isRealSession(): bool
	{
		$handler = \Bitrix\Main\Application::getInstance()->getSession()->getSessionHandler();

		return !($handler instanceof \Bitrix\Main\Session\Handlers\NullSessionHandler);
	}

	public function canView(): bool
	{
		$this->checkGroupEntityFields([
			'ID',
			'VISIBLE',
		]);

		$this->checkRelationEntityFields($this->currentUserRelation, [
			'GROUP_ID',
			'ROLE',
		]);

		$currentUserRole = $this->currentUserRelation?->get('ROLE');

		return GroupViewVerdict::canView(
			isVisibleFlagOn: (bool)$this->group->get('VISIBLE'),
			isExtranetGroup: $this->isExtranetGroup($this->group->getId()),
			isExtranetUser: $this->user->isExtranet(),
			currentUserRole: $currentUserRole === null ? null : (string)$currentUserRole,
			isModuleAdmin: $this->isCurrentUserModuleAdmin,
		);
	}

	public function canModify(): bool
	{
		$this->checkGroupEntityFields([
			'ID',
			'CLOSED',
			'PROJECT',
			'SCRUM_MASTER_ID',
		]);

		$this->checkRelationEntityFields($this->currentUserRelation, [
			'GROUP_ID',
			'USER_ID',
			'ROLE',
		]);

		if (
			!$this->isCurrentUserModuleAdmin
			&& !$this->checkRelationGroupId($this->currentUserRelation)
		)
		{
			return false;
		}

		if (!$this->canCurrentUserModify())
		{
			return false;
		}

		return true;
	}

	public function canEdit(): bool
	{
		return $this->canModify();
	}

	public function canDelete(): bool
	{
		return $this->canModify();
	}

	public function canAddToArchive(): bool
	{
		if (!$this->canModify())
		{
			return false;
		}

		return !$this->group->get('CLOSED');
	}

	public function canRemoveFromArchive(): bool
	{
		if (!$this->canModify())
		{
			return false;
		}

		return $this->group->get('CLOSED');
	}

	public function canAddToFavorites(): bool
	{
		if (!$this->canView())
		{
			return false;
		}

		return ($this->currentUserFavorites === null);
	}

	public function canRemoveFromFavorites(): bool
	{
		if (!$this->canView())
		{
			return false;
		}

		return ($this->currentUserFavorites !== null);
	}

	public function canSetOwner(): bool
	{
		if (!$this->canModify())
		{
			return false;
		}

		$this->checkRelationEntityFields($this->targetUserRelation, [
			'GROUP_ID',
			'ROLE',
		]);

		if (
			$this->targetUserRelation
			&& (
				!$this->checkRelationGroupId($this->targetUserRelation)
				|| !in_array($this->targetUserRelation->get('ROLE'), [
					UserToGroupTable::ROLE_USER,
					UserToGroupTable::ROLE_MODERATOR
				], true)
			)
		)
		{
			return false;
		}

		if (
			!$this->isCurrentUserModuleAdmin
			&& !$this->checkClosedGroup()
		)
		{
			return false;
		}

		return true;
	}

	public function canSetScrumMaster(): bool
	{
		if (!$this->canModify())
		{
			return false;
		}

		$this->checkRelationEntityFields($this->targetUserRelation, [
			'GROUP_ID',
			'ROLE',
		]);

		if (!$this->checkScrum())
		{
			return false;
		}

		if ($this->checkScrumMaster($this->targetUserRelation))
		{
			return false;
		}

		if (
			$this->targetUserRelation
			&& (
				!$this->checkRelationGroupId($this->targetUserRelation)
				|| !in_array($this->targetUserRelation->get('ROLE'), UserToGroupTable::getRolesMember(), true)
			)
		)
		{
			return false;
		}

		if (
			!$this->isCurrentUserModuleAdmin
			&& !$this->checkClosedGroup()
		)
		{
			return false;
		}

		return true;
	}

	public function canSetModerator(): bool
	{
		if (!$this->canModify())
		{
			return false;
		}

		$this->checkRelationEntityFields($this->targetUserRelation, [
			'GROUP_ID',
			'ROLE',
		]);

		if (
			!$this->targetUserRelation
			|| !$this->checkRelationGroupId($this->targetUserRelation)
			|| $this->targetUserRelation->get('ROLE') !== UserToGroupTable::ROLE_USER
		)
		{
			return false;
		}

		if (
			!$this->isCurrentUserModuleAdmin
			&& !$this->checkClosedGroup()
		)
		{
			return false;
		}

		return true;
	}

	public function canRemoveModerator(): bool
	{
		if (!$this->canModify())
		{
			return false;
		}

		$this->checkRelationEntityFields($this->targetUserRelation, [
			'GROUP_ID',
			'USER_ID',
			'ROLE',
		]);

		if (
			!$this->targetUserRelation
			|| !$this->checkRelationGroupId($this->targetUserRelation)
			|| $this->targetUserRelation->get('ROLE') !== UserToGroupTable::ROLE_MODERATOR
			|| $this->targetUserRelation->get('USER_ID') === $this->currentUserId
		)
		{
			return false;
		}

		if ($this->checkScrumMaster($this->targetUserRelation))
		{
			return false;
		}

		if (
			!$this->isCurrentUserModuleAdmin
			&& !$this->checkClosedGroup()
		)
		{
			return false;
		}

		return true;
	}

	public function canJoin(): bool
	{
		$this->checkGroupEntityFields([
			'ID',
			'CLOSED',
			'VISIBLE',
		]);
		$this->checkRelationEntityFields($this->currentUserRelation, [
			'GROUP_ID',
			'INITIATED_BY_TYPE',
			'ROLE',
		]);

		if (
			!$this->isCurrentUserModuleAdmin
			&& !$this->group->get('VISIBLE')
		)
		{
			return false;
		}

		if (
			$this->currentUserRelation
			&& (
				$this->currentUserRelation->get('ROLE') !== UserToGroupTable::ROLE_REQUEST
				|| $this->currentUserRelation->get('INITIATED_BY_TYPE') !== UserToGroupTable::INITIATED_BY_GROUP
			)
		)
		{
			return false;
		}

		if (
			!$this->isCurrentUserModuleAdmin
			&& !$this->checkClosedGroup()
		)
		{
			return false;
		}

		return true;
	}

	public function canLeave(): bool
	{
		$this->checkGroupEntityFields([
			'ID',
			'PROJECT',
			'SCRUM_MASTER_ID',
		]);
		$this->checkRelationEntityFields($this->currentUserRelation, [
			'GROUP_ID',
			'USER_ID',
			'ROLE',
			'AUTO_MEMBER',
			'INITIATED_BY_TYPE',
		]);

		if (
			!$this->currentUserRelation
			|| !$this->checkRelationGroupId($this->currentUserRelation)
		)
		{
			return false;
		}

		if (!in_array($this->currentUserRelation->get('ROLE'), [
			UserToGroupTable::ROLE_USER ,
			UserToGroupTable::ROLE_MODERATOR
		], true))
		{
			return false;
		}

		if ($this->isCurrentUserAddedFromStructure())
		{
			$this->addError(self::ERROR_LEAVE_FROM_STRUCTURE);

			return false;
		}

		if ($this->checkScrumMaster($this->currentUserRelation))
		{
			return false;
		}

		return true;
	}

	public function canDeleteOutgoingRequest(): bool
	{
		$this->checkGroupEntityFields([
			'ID',
			'CLOSED',
			'PROJECT',
			'SCRUM_MASTER_ID',
			'INITIATE_PERMS',
		]);
		$this->checkRelationEntityFields($this->currentUserRelation, [
			'GROUP_ID',
			'USER_ID',
			'ROLE',
		]);
		$this->checkRelationEntityFields($this->targetUserRelation, [
			'GROUP_ID',
			'ROLE',
			'INITIATED_BY_TYPE',
			'INITIATED_BY_USER_ID',
		]);

		if (
			!$this->isCurrentUserModuleAdmin
			&& !$this->checkRelationGroupId($this->currentUserRelation)
		)
		{
			return false;
		}

		if (
			!$this->targetUserRelation
			|| !$this->checkRelationGroupId($this->targetUserRelation)
		)
		{
			return false;
		}

		if (
			$this->targetUserRelation->get('ROLE') !== UserToGroupTable::ROLE_REQUEST
			|| $this->targetUserRelation->get('INITIATED_BY_TYPE') !== UserToGroupTable::INITIATED_BY_GROUP
		)
		{
			return false;
		}

		if (
			!$this->canCurrentUserInitiate()
			&& (int)$this->targetUserRelation->get('INITIATED_BY_USER_ID') !== $this->currentUserId
		)
		{
			return false;
		}

		return true;
	}

	public function canExclude(): bool
	{
		$this->checkGroupEntityFields([
			'ID',
			'CLOSED',
			'PROJECT',
			'SCRUM_MASTER_ID',
		]);
		$this->checkRelationEntityFields($this->currentUserRelation, [
			'GROUP_ID',
			'USER_ID',
			'ROLE',
		]);
		$this->checkRelationEntityFields($this->targetUserRelation, [
			'GROUP_ID',
			'ROLE',
			'AUTO_MEMBER',
			'INITIATED_BY_TYPE',
		]);

		if (
			!$this->isCurrentUserModuleAdmin
			&& !$this->checkRelationGroupId($this->currentUserRelation)
		)
		{
			return false;
		}

		if (!$this->canCurrentUserModify())
		{
			return false;
		}

		if (
			!$this->targetUserRelation
			|| !$this->checkRelationGroupId($this->targetUserRelation)
			|| $this->targetUserRelation->get('USER_ID') === $this->currentUserId
			|| !in_array($this->targetUserRelation->get('ROLE'), [
				UserToGroupTable::ROLE_MODERATOR,
				UserToGroupTable::ROLE_USER,
			], true)
		)
		{
			return false;
		}

		if ($this->isTargetAddedFromStructure())
		{
			$this->addError(self::ERROR_EXCLUDE_FROM_STRUCTURE);

			return false;
		}

		if ($this->checkScrumMaster($this->targetUserRelation))
		{
			return false;
		}

		if (
			!$this->isCurrentUserModuleAdmin
			&& !$this->checkClosedGroup()
		)
		{
			return false;
		}

		return true;
	}

	public function canProcessIncomingRequest(): bool
	{
		$this->checkGroupEntityFields([
			'ID',
			'CLOSED',
			'PROJECT',
			'SCRUM_MASTER_ID',
			'INITIATE_PERMS',
		]);
		$this->checkRelationEntityFields($this->currentUserRelation, [
			'GROUP_ID',
			'USER_ID',
			'ROLE',
		]);
		$this->checkRelationEntityFields($this->targetUserRelation, [
			'GROUP_ID',
			'ROLE',
			'INITIATED_BY_TYPE',
		]);

		if (
			!$this->isCurrentUserModuleAdmin
			&& !$this->checkRelationGroupId($this->currentUserRelation)
		)
		{
			return false;
		}

		if (
			!$this->targetUserRelation
			|| !$this->checkRelationGroupId($this->targetUserRelation)
		)
		{
			return false;
		}

		if ($this->targetUserRelation->get('ROLE') !== UserToGroupTable::ROLE_REQUEST)
		{
			return false;
		}

		if (
			!$this->canCurrentProcessRequestsIn()
			|| $this->targetUserRelation->get('INITIATED_BY_TYPE') !== UserToGroupTable::INITIATED_BY_USER
		)
		{
			return false;
		}

		return true;
	}

	public function canDeleteIncomingRequest(): bool
	{
		$this->checkRelationEntityFields($this->currentUserRelation, [
			'GROUP_ID',
		]);
		$this->checkRelationEntityFields($this->targetUserRelation, [
			'GROUP_ID',
			'ROLE',
			'INITIATED_BY_TYPE',
			'INITIATED_BY_USER_ID',
		]);

		if (
			!$this->isCurrentUserModuleAdmin
			&& !$this->checkRelationGroupId($this->currentUserRelation)
		)
		{
			return false;
		}

		if (
			!$this->targetUserRelation
			|| !$this->checkRelationGroupId($this->targetUserRelation)
		)
		{
			return false;
		}

		if (
			$this->targetUserRelation->get('ROLE') !== UserToGroupTable::ROLE_REQUEST
			|| $this->targetUserRelation->get('INITIATED_BY_TYPE') !== UserToGroupTable::INITIATED_BY_USER

		)
		{
			return false;
		}

		if (
			!$this->isCurrentUserModuleAdmin
			&& (int)$this->targetUserRelation->get('INITIATED_BY_USER_ID') !== $this->currentUserId
		)
		{
			return false;
		}

		return true;
	}

	public function canConvertToCollab(): bool
	{
		return
			Feature::isNewProjectsOn()
			&& $this->currentUserRelation
			&& $this->isConvertibleToCollabType()
			&& $this->checkOwner($this->currentUserRelation)
		;
	}

	// The type column is nullable, and a row that holds no type is not an ordinary group by default: a
	// legacy scrum board kept its scrum master, and the conversion must stay closed for it
	private function isConvertibleToCollabType(): bool
	{
		$storedType = Type::tryFrom((string)$this->group->getType());

		if ($storedType !== null)
		{
			return $storedType === Type::Group || $storedType === Type::Project;
		}

		return !$this->isLegacyScrum();
	}

	// The scrum grid tells a typeless scrum from a project by the scrum master alone
	// (ScrumRepository::applyTypeFilter()). The caller may not have selected the marker - then the row is
	// classified by the provider, which reads it
	private function isLegacyScrum(): bool
	{
		if ($this->group->has('SCRUM_MASTER_ID'))
		{
			return (int)$this->group->getScrumMasterId() > 0;
		}

		return GroupProvider::getInstance()->getGroupType((int)$this->group->getId()) === Type::Scrum;
	}

	protected function checkGroupEntityFields(array $fieldsList = []): void
	{
		if (!$this->group)
		{
			return;
		}

		$this->checkEntityFields($this->group, $fieldsList);
	}

	protected function checkRelationEntityFields(?EO_UserToGroup $relation, array $fieldsList = []): void
	{
		if (!$relation)
		{
			return;
		}

		$this->checkEntityFields($relation, $fieldsList);
	}

	protected function checkFavoritesEntityFields(?EO_WorkgroupFavorites $favoritesEntity, array $fieldsList = []): void
	{
		if (!$favoritesEntity)
		{
			return;
		}

		$this->checkEntityFields($favoritesEntity, $fieldsList);
	}

	protected function checkEntityFields(\Bitrix\Main\ORM\Objectify\EntityObject $entityObject, array $fieldsList = []): void
	{
		foreach ($fieldsList as $field)
		{
			if (!$entityObject->has($field))
			{
				throw new ArgumentException('Entity has no '. $field . ' field.');
			}
		}
	}

	protected function checkRelationGroupId(
		?EO_UserToGroup $relation
	): bool
	{
		return (
			$relation
			&& (int)$this->group->get('ID') === (int)$relation->get('GROUP_ID')
		);
	}

	protected function checkFavoritesEntityGroupId(
		?EO_WorkgroupFavorites $favoritesEntity
	): bool
	{
		return (
			$favoritesEntity
			&& (int)$this->group->get('ID') === (int)$favoritesEntity->get('GROUP_ID')
		);
	}

	protected function checkOwnerOrScrumMaster(
		?EO_UserToGroup $relation
	): bool
	{
		return (
			$relation
			&& (
				$this->checkOwner($relation)
				|| $this->checkScrumMaster($relation)
			)
		);
	}

	protected function checkOwner(
		EO_UserToGroup $relation
	): bool
	{
		return ($relation->get('ROLE') === UserToGroupTable::ROLE_OWNER);
	}

	protected function checkScrumMaster(
		?EO_UserToGroup $relation
	): bool
	{
		return (
			$this->group->get('PROJECT')
			&& $relation
			&& (int)$this->group->get('SCRUM_MASTER_ID') === (int)$relation->get('USER_ID')
		);
	}

	protected function isTargetAddedFromStructure(): bool
	{
		return (
			$this->targetUserRelation
			&& (
				$this->targetUserRelation->get('AUTO_MEMBER')
				|| $this->targetUserRelation->get('INITIATED_BY_TYPE') === UserToGroupTable::INITIATED_BY_STRUCTURE
			)
		);
	}

	protected function isCurrentUserAddedFromStructure(): bool
	{
		return (
			$this->currentUserRelation
			&& (
				$this->currentUserRelation->get('AUTO_MEMBER')
				|| $this->currentUserRelation->get('INITIATED_BY_TYPE') === UserToGroupTable::INITIATED_BY_STRUCTURE
			)
		);
	}

	protected function checkScrum(): bool
	{
		return (
			$this->group->get('PROJECT')
			&& (int)$this->group->get('SCRUM_MASTER_ID') > 0
		);
	}

	protected function checkClosedGroup(): bool
	{
		return (
			!$this->group->get('CLOSED')
			|| \Bitrix\Socialnetwork\Item\Workgroup::canWorkWithClosedWorkgroups()
		);
	}

	protected function canCurrentUserModify(): bool
	{
		return (
			$this->isCurrentUserModuleAdmin
			|| $this->checkOwnerOrScrumMaster($this->currentUserRelation)
		);
	}

	protected function canCurrentUserInitiate(): bool
	{
		return (
			$this->isCurrentUserModuleAdmin
			|| (
				$this->group->get('INITIATE_PERMS') === UserToGroupTable::ROLE_OWNER
				&& $this->currentUserRelation->get('ROLE') === UserToGroupTable::ROLE_OWNER
			)
			|| (
				$this->group->get('INITIATE_PERMS') === UserToGroupTable::ROLE_MODERATOR
				&& in_array($this->currentUserRelation->get('ROLE'), [
					UserToGroupTable::ROLE_OWNER,
					UserToGroupTable::ROLE_MODERATOR
				], true)
			)
			|| (
				$this->group->get('INITIATE_PERMS') === UserToGroupTable::ROLE_USER
				&& in_array($this->currentUserRelation->get('ROLE'), UserToGroupTable::getRolesMember(), true)
			)
			|| $this->checkScrumMaster($this->currentUserRelation)
		);
	}

	protected function canCurrentProcessRequestsIn(): bool
	{
		return (
			$this->canCurrentUserInitiate()
			|| (
				$this->currentUserRelation
				&& in_array($this->currentUserRelation->get('ROLE'), [
					UserToGroupTable::ROLE_OWNER,
					UserToGroupTable::ROLE_MODERATOR
				], true)
			)
		);
	}

	/**
	 * Extranet-site membership is container-specific and can be cached for the request.
	 */
	private function isExtranetGroup(int $groupId): bool
	{
		static $cache = [];

		if (!isset($cache[$groupId]))
		{
			$cache[$groupId] = Loader::includeModule('extranet')
				&& \CExtranet::IsExtranetSocNetGroup($groupId)
			;
		}

		return $cache[$groupId];
	}
}
