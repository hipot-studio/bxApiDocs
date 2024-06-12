<?php
/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage intranet
 * @copyright 2001-2019 Bitrix
 */

namespace Bitrix\Intranet;

use Bitrix\Intranet\HR\Employee;
use Bitrix\Intranet\Counters\Counter;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Entity\ExpressionField;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\Relations\ManyToMany;
use Bitrix\Main\UI\EntitySelector\EntityUsageTable;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\SystemException;
use Bitrix\Main\UserAccessTable;

/**
 * Class UserTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_User_Query query()
 * @method static EO_User_Result getByPrimary($primary, array $parameters = array())
 * @method static EO_User_Result getById($id)
 * @method static EO_User_Result getList(array $parameters = array())
 * @method static EO_User_Entity getEntity()
 * @method static \Bitrix\Intranet\EO_User createObject($setDefaultValues = true)
 * @method static \Bitrix\Intranet\EO_User_Collection createCollection()
 * @method static \Bitrix\Intranet\EO_User wakeUpObject($row)
 * @method static \Bitrix\Intranet\EO_User_Collection wakeUpCollection($rows)
 */
class UserTable extends \Bitrix\Main\UserTable
{
	public static function postInitialize(\Bitrix\Main\ORM\Entity $entity)
	{
		parent::postInitialize($entity);

		// add intranet user type expression
		$conditionList = [];
		$externalUserTypesUsed = [];

		if (ModuleManager::isModuleInstalled('sale'))
		{
			$conditionList[] = [
				'PATTERN' => 'EXTERNAL_AUTH_ID',
				'VALUE' => "WHEN %s IN ('sale', 'saleanonymous', 'shop') THEN 'sale'"
			];
			$externalUserTypesUsed[] = 'sale';
			$externalUserTypesUsed[] = 'saleanonymous';
			$externalUserTypesUsed[] = 'shop';
		}
		if (ModuleManager::isModuleInstalled('imconnector'))
		{
			$conditionList[] = [
				'PATTERN' => 'EXTERNAL_AUTH_ID',
				'VALUE' => "WHEN %s = 'imconnector' THEN 'imconnector'"
			];
			$externalUserTypesUsed[] = 'imconnector';
		}
		if (ModuleManager::isModuleInstalled('im'))
		{
			$conditionList[] = [
				'PATTERN' => 'EXTERNAL_AUTH_ID',
				'VALUE' => "WHEN %s = 'bot' THEN 'bot'"
			];
			$externalUserTypesUsed[] = 'bot';
		}
		if (ModuleManager::isModuleInstalled('mail'))
		{
			$conditionList[] = [
				'PATTERN' => 'EXTERNAL_AUTH_ID',
				'VALUE' => "WHEN %s = 'email' THEN 'email'"
			];
			$externalUserTypesUsed[] = 'email';
		}

		$externalUserTypes = \Bitrix\Main\UserTable::getExternalUserTypes();
		$externalUserTypesAdditional = array_diff($externalUserTypes, $externalUserTypesUsed);
		if (!empty($externalUserTypesAdditional))
		{
			$sqlHelper = \Bitrix\Main\Application::getInstance()->getConnection()->getSqlHelper();
			foreach($externalUserTypesAdditional as $externalAuthId)
			{
				$value = $sqlHelper->convertToDbText($externalAuthId);
				$conditionList[] = [
					'PATTERN' => 'EXTERNAL_AUTH_ID',
					'VALUE' => "WHEN %s = ".$value." THEN ".$value.""
				];
			}
		}

		// duplicate for inner join
		$conditionListInner = $conditionList;

		$extranetUserType = (
			ModuleManager::isModuleInstalled('extranet')
				? 'extranet'
				: 'shop'
		);

		$serializedValue = serialize([]);

		$conditionList[] = [
			'PATTERN' => 'UF_DEPARTMENT',
			'VALUE' => "WHEN %s = '".$serializedValue."' THEN '".$extranetUserType."'"
		];
		$conditionList[] = [
			'PATTERN' => 'UF_DEPARTMENT',
			'VALUE' => "WHEN %s IS NULL THEN '".$extranetUserType."'"
		];
		$conditionListInner[] = [
			'PATTERN' => 'UTS_OBJECT_INNER.UF_DEPARTMENT',
			'VALUE' => "WHEN %s = '".$serializedValue."' THEN '".$extranetUserType."'"
		];
		$conditionListInner[] = [
			'PATTERN' => 'UTS_OBJECT_INNER.UF_DEPARTMENT',
			'VALUE' => "WHEN %s IS NULL THEN '".$extranetUserType."'"
		];

		// add USER_TYPE with left join
		$condition = "CASE ";
		$patternList = [];

		foreach($conditionList as $conditionFields)
		{
			$condition .= ' '.$conditionFields['VALUE'].' ';
			$patternList[] = $conditionFields['PATTERN'];
		}
		$condition .= "ELSE 'employee' END";

		$entity->addField(new ExpressionField('USER_TYPE',
			$condition,
			$patternList
		));

		if (Loader::includeModule('socialnetwork'))
		{
			$entity->addField(new \Bitrix\Main\ORM\Fields\Relations\OneToMany('TAGS', \Bitrix\Socialnetwork\UserTagTable::class, 'USER'));
		}

		// add USER_TYPE with inner join
		$condition = "CASE ";
		$patternList = [];

		foreach($conditionListInner as $conditionFields)
		{
			$condition .= ' '.$conditionFields['VALUE'].' ';
			$patternList[] = $conditionFields['PATTERN'];
		}
		$condition .= "ELSE 'employee' END";

		$entity->addField(new ExpressionField('USER_TYPE_INNER',
			$condition,
			$patternList
		));

		// add other fields
		$entity->addField(new ExpressionField('USER_TYPE_IS_EMPLOYEE',
			"CASE WHEN %s = 'employee' THEN 1 ELSE 0 END",
			'USER_TYPE_INNER'
		));

		$entity->addField(
			new \Bitrix\Main\ORM\Fields\Relations\Reference(
				'INVITATION',
				\Bitrix\Intranet\Internals\InvitationTable::class,
				\Bitrix\Main\ORM\Query\Join::on('this.ID', 'ref.USER_ID')
			)
		);

		$entity->addField(new DatetimeField('CHECKWORD_TIME'));

		$entity->addField(new ExpressionField(
			'INVITED_SORT',
			"CASE WHEN %s = 'Y' AND %s <> '' THEN 0 ELSE 1 END",
			['ACTIVE', 'CONFIRM_CODE']
		));

		$entity->addField(new ExpressionField(
			'WAITING_CONFIRMATION_SORT',
			"CASE WHEN %s = 'N' AND %s <> '' THEN 0 ELSE 1 END",
			['ACTIVE', 'CONFIRM_CODE']
		));

		$entity->addField(
			(new \Bitrix\Main\ORM\Fields\Relations\Reference(
			'UG',
			\Bitrix\Socialnetwork\UserToGroupTable::class,
			\Bitrix\Main\ORM\Query\Join::on('this.ID', 'ref.USER_ID'))
			)->configureJoinType(\Bitrix\Main\ORM\Query\Join::TYPE_INNER)
		);
	}

	public static function createInvitedQuery(): Query
	{
		return static::query()->addFilter('!CONFIRM_CODE', false)->where('IS_REAL_USER', true);
	}
}

class User
{
	private CurrentUser $currentUser;
	private int $userId;

	/**
	 * @throws ArgumentOutOfRangeException
	 */
	public function __construct(?int $userId = null)
	{
		if (!is_null($userId) && $userId <= 0)
		{
			throw new ArgumentOutOfRangeException('userId', 1);
		}
		$this->currentUser = CurrentUser::get();
		$this->userId = is_null($userId) ? $this->currentUser->getId() : $userId;
	}

	public function getId(): int
	{
		return $this->userId;
	}

	public function isIntranet(): bool
	{
		if ($this->isAdmin())
		{
			return true;
		}

		return $this->hasDepartment();
	}

	private function hasDepartment(): bool
	{
		$fields = $this->getFields();

		return isset($fields["UF_DEPARTMENT"])
			&& (
				(
					is_array($fields["UF_DEPARTMENT"])
					&& (int)($fields["UF_DEPARTMENT"][0] ?? null) > 0
				)
				|| (
					!is_array($fields["UF_DEPARTMENT"])
					&& (int)$fields["UF_DEPARTMENT"] > 0
				)
			);
	}

	public function hasAccessToDepartment(): bool
	{
		$accessManager = new \CAccess;
		$accessManager->UpdateCodes(['USER_ID' => $this->userId]);

		$accessResult = UserAccessTable::query()
			->where('USER_ID', $this->userId)
			->whereLike('ACCESS_CODE', 'D%')
			->whereNotLike('ACCESS_CODE', 'DR%')
			->setLimit(1)
			->fetch();

		return !($accessResult === false);
	}

	public function isAdmin(): bool
	{
		if (
			isset($GLOBALS['USER'])
			&& $GLOBALS['USER'] instanceof \CAllUser
			&& $this->currentUser->getId() === $this->userId)
		{
			return (
					Loader::includeModule('bitrix24')
					&& \CBitrix24::IsPortalAdmin($this->userId)
				)
				|| $this->currentUser->isAdmin();
		}
		else
		{
			$groupIds = (new \CUser())->GetUserGroup($this->userId);

			return in_array(1, $groupIds);
		}
	}

	public function getFields(): array
	{
		$result = \CUser::GetById($this->userId)->fetch();
		return is_array($result) ? $result : [];
	}

	public function numberOfInvitationsSent(): int
	{
		$query = UserTable::createInvitedQuery()->where('ACTIVE', 'Y');

		if (!$this->isAdmin())
		{
			$query->addFilter('INVITATION.ORIGINATOR_ID', $this->userId);
		}

		return $query->queryCountTotal();
	}

	public function fetchOriginatorUser(): ?self
	{
		$user = UserTable::query()
			->where('ID', $this->userId)
			->setSelect(['ID', 'OWN_USER_ID' => 'INVITATION.ORIGINATOR_ID'])
			->setLimit(1)
			->fetch();

		if (isset($user['OWN_USER_ID']) && (int)$user['OWN_USER_ID'] > 0)
		{
			return new static((int)$user['OWN_USER_ID']);
		}

		return null;
	}

	/**
	 * Returns sorted array of user id.
	 * Flags for correct complex sorting
	 * @param bool $onlyActive
	 * @param bool $withInvited
	 * @return array
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getStructureSort(bool $onlyActive = true, bool $withInvited = true): array
	{
		$userDepartment = \CIntranetUtils::GetUserDepartments($this->userId);
		$departmentId = !empty($userDepartment) ? $userDepartment[0] : 0;
		$list = [];

		if ($departmentId)
		{
			if ($managerId = \CIntranetUtils::GetDepartmentManagerID($departmentId))
			{
				$list[] = $managerId;
			}

			$list = array_merge(
				$list,
				Employee::getInstance()->getListByDepartmentId($departmentId, $onlyActive, $withInvited),
			);
		}

		$list = array_merge(
			$list,
			$this->getUserUsageList()
		);

		return array_reverse(array_unique($list));
	}

	private function getUserUsageList(): array
	{
		$query = EntityUsageTable::query()
			->setSelect(['ENTITY_ID', 'ITEM_ID', 'MAX_LAST_USE_DATE'])
			->setGroup(['ENTITY_ID', 'ITEM_ID'])
			->where('USER_ID', $this->userId)
			->where('ENTITY_ID', 'user')
			->registerRuntimeField(new \Bitrix\Main\ORM\Fields\ExpressionField('MAX_LAST_USE_DATE', 'MAX(%s)', 'LAST_USE_DATE'))
			->setOrder(['MAX_LAST_USE_DATE' => 'asc'])
			->setLimit(20);

		$userEntityList = $query->exec()->fetchAll();
		$result = [];

		foreach ($userEntityList as $userEntity)
		{
			$result[] = $userEntity['ITEM_ID'];
		}

		return $result;
	}

	public function isInitializedUser(): bool
	{
		return !UserTable::createInvitedQuery()->where('ID', $this->getId())->queryCountTotal();
	}

	public function getInvitationCounterValue(): int
	{
		return (new Counter(Invitation::getInvitedCounterId()))->getValue($this);
	}

	public function getTotalInvitationCounterValue(): int
	{
		return (new Counter(Invitation::getTotalInvitationCounterId()))->getValue($this);
	}

	public function getWaitConfirmationCounterValue(): int
	{
		return (new Counter(Invitation::getWaitConfirmationCounterId()))->getValue($this);
	}
}