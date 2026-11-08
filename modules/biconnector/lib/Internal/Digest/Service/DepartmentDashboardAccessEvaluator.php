<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Digest\Service;

use Bitrix\BIConnector\Access\Permission\PermissionDictionary;
use Bitrix\BIConnector\Integration\Superset\Model;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Internal\Digest\Dto\DepartmentAccessDto;
use Bitrix\BIConnector\Internal\Digest\Provider\DashboardGrantingAccessCodeProvider;
use Bitrix\BIConnector\Superset\Logger\Logger;
use Bitrix\Bitrix24\Feature;
use Bitrix\HumanResources\Enum\DepthLevel;
use Bitrix\HumanResources\Item\Node;
use Bitrix\HumanResources\Public\Service\Container;
use Bitrix\HumanResources\Type\AccessCodeType;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\UserAccessTable;
use Bitrix\Main\UserTable;

/**
 * Report view right of a whole department, answered without walking its people.
 *
 * Follows the rules {@see \Bitrix\BIConnector\Access\AccessController} applies to each recipient
 * on sending: the view right on a group of the report, the edit right for a draft
 * ({@see \Bitrix\BIConnector\Access\Rule\DashboardViewRule}), administrators and a disabled
 * rights feature let everyone through. A role relation naming the department or one of its
 * ancestors answers at once; otherwise one aggregate query counts the people of the department
 * and the ones of them holding none of the relation codes.
 *
 * Left open for extension so tests can switch the rights feature off.
 */
class DepartmentDashboardAccessEvaluator
{
	private const EVERYONE_ACCESS_CODES = ['AE0', 'G2'];
	private const LEGACY_DEPARTMENT_ACCESS_CODE_PATTERN = '/^D(\d+)$/';
	private const CHAT_ACCESS_PROVIDER_ID = 'imchat';

	/** @var array<string, string[]> dashboard id and status => relation codes of the roles granting the action */
	private array $relationCodes = [];

	/** @var string[]|null */
	private ?array $adminAccessCodes = null;

	private readonly DashboardGrantingAccessCodeProvider $accessCodeProvider;

	public function __construct(?DashboardGrantingAccessCodeProvider $accessCodeProvider = null)
	{
		$this->accessCodeProvider = $accessCodeProvider ?? new DashboardGrantingAccessCodeProvider();
	}

	/**
	 * @return DepartmentAccessDto|null Null when the department is unknown or the check failed.
	 */
	public function evaluate(Model\Dashboard $dashboard, int $nodeId, bool $withSubdepartments): ?DepartmentAccessDto
	{
		if ($nodeId <= 0 || !Loader::includeModule('humanresources'))
		{
			return null;
		}

		try
		{
			$node = Container::getNodeService()->getById($nodeId);
			if ($node === null || $node->type !== NodeEntityType::DEPARTMENT)
			{
				return null;
			}

			if (!$this->isRightsFeatureEnabled())
			{
				return DepartmentAccessDto::full($nodeId);
			}

			$relationCodes = $this->getRelationCodes($dashboard);
			if (!empty(array_intersect($relationCodes, $this->getCoveringAccessCodes($node, $withSubdepartments))))
			{
				return DepartmentAccessDto::full($nodeId);
			}

			return $this->countDepartment($nodeId, $withSubdepartments, $relationCodes);
		}
		catch (\Throwable $exception)
		{
			Logger::logErrors(
				[new Error($exception->getMessage())],
				[
					'message' => 'BI digest setup: department access check failed',
					'dashboard_id' => $dashboard->getId(),
					'node_id' => $nodeId,
				],
			);

			return null;
		}
	}

	protected function isRightsFeatureEnabled(): bool
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return true;
		}

		return Feature::isFeatureEnabled('bi_constructor_rights');
	}

	/**
	 * @return string[]
	 */
	private function getRelationCodes(Model\Dashboard $dashboard): array
	{
		$cacheKey = $dashboard->getId() . '_' . $dashboard->getStatus();
		if (isset($this->relationCodes[$cacheKey]))
		{
			return $this->relationCodes[$cacheKey];
		}

		$this->relationCodes[$cacheKey] = $this->loadRelationCodes($dashboard);

		return $this->relationCodes[$cacheKey];
	}

	/**
	 * @return string[]
	 */
	private function loadRelationCodes(Model\Dashboard $dashboard): array
	{
		$permissionCode =
			$dashboard->getStatus() === SupersetDashboardTable::DASHBOARD_STATUS_DRAFT
				? PermissionDictionary::BIC_DASHBOARD_EDIT
				: PermissionDictionary::BIC_DASHBOARD_VIEW
		;

		return $this->accessCodeProvider->getRoleRelationCodes($dashboard->getId(), $permissionCode);
	}

	/**
	 * Codes a relation may name to cover every person of the department at once.
	 *
	 * @return string[]
	 */
	private function getCoveringAccessCodes(Node $node, bool $withSubdepartments): array
	{
		$codes = self::EVERYONE_ACCESS_CODES;

		$departments = [$node];
		$ancestors = Container::getNodeService()->findParentsByNodeId($node->id, depthLevel: DepthLevel::FULL);
		foreach ($ancestors ?? [] as $ancestor)
		{
			$departments[] = $ancestor;
		}

		foreach ($departments as $department)
		{
			$codes[] = AccessCodeType::HrDepartmentRecursiveType->buildAccessCode($department->id);
			$legacyId = $this->getLegacyDepartmentId($department);
			if ($legacyId !== null)
			{
				$codes[] = AccessCodeType::IntranetDepartmentRecursiveType->buildAccessCode($legacyId);
			}
		}

		if (!$withSubdepartments)
		{
			$codes[] = AccessCodeType::HrDepartmentType->buildAccessCode($node->id);
			$legacyId = $this->getLegacyDepartmentId($node);
			if ($legacyId !== null)
			{
				$codes[] = AccessCodeType::IntranetDepartmentType->buildAccessCode($legacyId);
			}
		}

		return $codes;
	}

	private function getLegacyDepartmentId(Node $node): ?int
	{
		if (preg_match(self::LEGACY_DEPARTMENT_ACCESS_CODE_PATTERN, $node->accessCode ?? '', $matches) !== 1)
		{
			return null;
		}

		return (int)$matches[1];
	}

	/**
	 * @param string[] $relationCodes
	 */
	private function countDepartment(int $nodeId, bool $withSubdepartments, array $relationCodes): DepartmentAccessDto
	{
		$memberCodeType =
			$withSubdepartments
				? AccessCodeType::HrDepartmentRecursiveType
				: AccessCodeType::HrDepartmentType
		;
		$grantingCodes = array_values(array_unique([...$relationCodes, ...$this->getAdminAccessCodes()]));

		// One aggregate over the access codes of the department people: the granted ones are joined on the
		// side, so the people are neither loaded nor counted in PHP. Codes of chat members are no grant.
		$row = UserAccessTable::query()
			->registerRuntimeField(new Reference(
				'MEMBER',
				UserTable::class,
				Join::on('this.USER_ID', 'ref.ID'),
				['join_type' => Join::TYPE_INNER],
			))
			->registerRuntimeField(new Reference(
				'GRANTED',
				UserAccessTable::class,
				Join::on('this.USER_ID', 'ref.USER_ID')
					->whereIn('ref.ACCESS_CODE', $grantingCodes)
					->whereNot('ref.PROVIDER_ID', self::CHAT_ACCESS_PROVIDER_ID)
				,
				['join_type' => Join::TYPE_LEFT],
			))
			->registerRuntimeField(new ExpressionField('TOTAL', 'COUNT(DISTINCT %s)', ['USER_ID']))
			->registerRuntimeField(new ExpressionField(
				'WITHOUT_ACCESS',
				'COUNT(DISTINCT CASE WHEN %s IS NULL THEN %s END)',
				['GRANTED.USER_ID', 'USER_ID'],
			))
			->setSelect(['TOTAL', 'WITHOUT_ACCESS'])
			->where('ACCESS_CODE', $memberCodeType->buildAccessCode($nodeId))
			->where('MEMBER.ACTIVE', true)
			->fetch()
		;

		return DepartmentAccessDto::fromCounts(
			$nodeId,
			(int)($row['WITHOUT_ACCESS'] ?? 0),
			(int)($row['TOTAL'] ?? 0),
		);
	}

	/**
	 * @return string[]
	 */
	private function getAdminAccessCodes(): array
	{
		$this->adminAccessCodes ??= $this->accessCodeProvider->getAdminAccessCodes();

		return $this->adminAccessCodes;
	}
}
