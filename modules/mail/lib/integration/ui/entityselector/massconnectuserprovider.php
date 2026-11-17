<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\UI\EntitySelector;

use Bitrix\HumanResources\Builder\Structure\Filter\Column\IdFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\Column\Node\NodeTypeFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\NodeFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\NodeMemberFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\SelectionCondition\Node\NodeAccessFilter;
use Bitrix\HumanResources\Builder\Structure\NodeMemberDataBuilder;
use Bitrix\HumanResources\Enum\DepthLevel;
use Bitrix\HumanResources\Public\Service\Container as HumanResourcesContainer;
use Bitrix\HumanResources\Type\MemberEntityType;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\HumanResources\Type\StructureAction;
use Bitrix\Mail\Access\Permission\PermissionDictionary;
use Bitrix\Mail\Access\Permission\PermissionVariablesDictionary;
use Bitrix\Mail\Helper\MailboxAccess;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Socialnetwork\Integration\UI\EntitySelector\UserProvider;

class MassConnectUserProvider extends UserProvider
{
	public const PROVIDER_ENTITY_ID = 'mail-massconnect-user';

	private const SAFE_OPTIONS = [
		'activeUsers' => true,
		'intranetUsersOnly' => true,
		'extranetUsersOnly' => false,
		'emailUsers' => false,
		'emailUsersOnly' => false,
		'myEmailUsers' => false,
		'networkUsers' => false,
		'networkUsersOnly' => false,
		'collabers' => false,
		'showInvitationFooter' => false,
		'inviteEmployeeLink' => false,
		'inviteExtranetLink' => false,
		'inviteGuestLink' => false,
		'fillDialog' => true,
	];

	public function __construct(array $options = [])
	{
		parent::__construct(self::SAFE_OPTIONS);
	}

	public function isAvailable(): bool
	{
		return static::includeHumanResources() && parent::isAvailable();
	}

	public function getSelectedItems(array $ids): array
	{
		return $this->getItems($ids);
	}

	protected static function getQuery(array $options = []): Query
	{
		$currentUserId = static::getCurrentUserIdForPermissionCheck();
		$options = array_replace($options, self::SAFE_OPTIONS);
		$options['currentUserId'] = $currentUserId;
		$options['invitedUsers'] = false;
		unset($options['departmentId'], $options['ignoreUserWhitelist']);

		$query = static::buildUserQuery($options);
		$query->where('REAL_USER', 'expr', true);

		if ($currentUserId <= 0 || !static::includeHumanResources())
		{
			return static::deny($query);
		}

		if (static::isCurrentUserAdmin())
		{
			return $query;
		}

		$permissionValue = static::getMailboxEditPermissionValue($currentUserId);
		if ($permissionValue === PermissionVariablesDictionary::VARIABLE_ALL)
		{
			return $query;
		}

		if (!in_array($permissionValue, [
			PermissionVariablesDictionary::VARIABLE_SELF_DEPARTMENTS,
			PermissionVariablesDictionary::VARIABLE_DEPARTMENT_WITH_SUBDEPARTMENTS,
		], true))
		{
			return static::deny($query);
		}

		try
		{
			$departmentIds = static::getCurrentUserDepartmentIds($currentUserId);
			if (empty($departmentIds))
			{
				return static::deny($query);
			}

			$allowedUserQuery = static::buildAllowedUserQuery(
				$currentUserId,
				$departmentIds,
				$permissionValue === PermissionVariablesDictionary::VARIABLE_DEPARTMENT_WITH_SUBDEPARTMENTS,
			);
			$query->whereIn('ID', new SqlExpression($allowedUserQuery->getQuery()));
		}
		catch (\Throwable)
		{
			return static::deny($query);
		}

		return $query;
	}

	protected static function buildUserQuery(array $options): Query
	{
		return parent::getQuery($options);
	}

	protected static function getCurrentUserIdForPermissionCheck(): int
	{
		return (int)CurrentUser::get()->getId();
	}

	protected static function isCurrentUserAdmin(): bool
	{
		return CurrentUser::get()->isAdmin();
	}

	protected static function getMailboxEditPermissionValue(int $userId): int
	{
		return (int)MailboxAccess::getPermissionValue(
			PermissionDictionary::MAIL_MAILBOX_LIST_ITEM_EDIT,
			$userId,
		);
	}

	protected static function includeHumanResources(): bool
	{
		return Loader::includeModule('humanresources');
	}

	/**
	 * @return int[]
	 */
	protected static function getCurrentUserDepartmentIds(int $userId): array
	{
		return HumanResourcesContainer::getNodeService()
			->findAllByMemberEntityId(
				memberEntityId: $userId,
				memberEntityType: MemberEntityType::USER,
				nodeTypes: [NodeEntityType::DEPARTMENT],
			)
			->getIds()
		;
	}

	/**
	 * @param int[] $departmentIds
	 */
	protected static function buildAllowedUserQuery(
		int $currentUserId,
		array $departmentIds,
		bool $withSubDepartments,
	): Query
	{
		$depthLevel = $withSubDepartments ? DepthLevel::FULL : 0;
		$accessFilter = new NodeAccessFilter(
			StructureAction::ViewAction,
			$currentUserId,
		);

		return (new NodeMemberDataBuilder())
			->setSelect(['ENTITY_ID'])
			->addFilter(new NodeMemberFilter(
				nodeFilter: new NodeFilter(
					idFilter: IdFilter::fromIds(array_map('intval', $departmentIds)),
					entityTypeFilter: NodeTypeFilter::fromNodeType(NodeEntityType::DEPARTMENT),
					depthLevel: $depthLevel,
					accessFilter: $accessFilter,
				),
				withVirtualUsers: false,
			))
			->prepareQuery()
		;
	}

	private static function deny(Query $query): Query
	{
		return $query->where('ID', 0);
	}
}

