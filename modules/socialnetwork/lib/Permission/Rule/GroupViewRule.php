<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Permission\Rule;

use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Main\Access\Rule\AbstractRule;
use Bitrix\Main\Loader;
use Bitrix\Socialnetwork\Item\Workgroup;
use Bitrix\Socialnetwork\Item\Workgroup\AccessManager;
use Bitrix\Socialnetwork\Permission\Dto\GroupViewResult;
use Bitrix\Socialnetwork\Permission\GroupAccessController;
use Bitrix\Socialnetwork\Permission\Model\GroupModel;
use Bitrix\Socialnetwork\Permission\Query\GroupViewBatchQuery;
use Bitrix\Socialnetwork\Permission\Rule\Trait\AccessTrait;
use Bitrix\Socialnetwork\Permission\User\UserModel;
use Bitrix\Socialnetwork\Permission\Verdict\GroupViewVerdict;

class GroupViewRule extends AbstractRule implements MassRuleInterface
{
	use AccessTrait;

	/** @var GroupAccessController */
	protected $controller;

	/** @var UserModel */
	protected $user;

	public function execute(AccessibleItem $item = null, $params = null): bool
	{
		if (!$item instanceof GroupModel)
		{
			$this->controller->addError(static::class, 'Wrong instance');

			return false;
		}

		if (
			$item->getId() <= 0
			|| $item->getDomainObject() === null
		)
		{
			$this->controller->addError(static::class, 'Group not found');

			return false;
		}

		if (!$this->getAccessManager($item, $this->user->getUserId(), $this->user->getUserId())->canView())
		{
			$this->controller->addError(static::class, 'Access denied by permissions');

			return false;
		}

		return true;
	}

	/**
	 * @param AccessibleItem[] $items
	 *
	 * @return array<int, GroupViewResult>
	 */
	public function executeMass(array $items, mixed $params = null): array
	{
		$groupIds = $this->collectGroupIds($items);
		if (empty($groupIds))
		{
			return [];
		}

		$userId = $this->user->getUserId();
		$isModuleAdmin = AccessManager::isModuleAdmin($userId);
		$isExtranetUser = $this->user->isExtranet();

		$result = [];
		foreach ($groupIds as $groupId)
		{
			$result[$groupId] = GroupViewResult::notFound($groupId);
		}

		$rows = GroupViewBatchQuery::fetch($groupIds, $userId, $this->getExtranetSiteId());

		foreach ($rows as $row)
		{
			$groupId = (int)($row['ID'] ?? 0);

			if (!isset($result[$groupId]))
			{
				continue;
			}

			$result[$groupId] = $this->makeResult($groupId, $row, $isModuleAdmin, $isExtranetUser);
		}

		return $result;
	}

	/**
	 * @param AccessibleItem[] $items
	 *
	 * @return int[]
	 */
	private function collectGroupIds(array $items): array
	{
		$groupIds = [];

		foreach ($items as $item)
		{
			if (!$item instanceof AccessibleItem)
			{
				continue;
			}

			$groupId = $item->getId();
			if ($groupId > 0)
			{
				$groupIds[$groupId] = $groupId;
			}
		}

		return array_values($groupIds);
	}

	private function makeResult(int $groupId, array $row, bool $isModuleAdmin, bool $isExtranetUser): GroupViewResult
	{
		$isVisibleFlagOn = $this->isFlagOn($row['VISIBLE'] ?? null);
		$isExtranetGroup = ($row['EXTRANET_SITE_ID'] ?? null) !== null;
		$role = isset($row['ROLE']) ? (string)$row['ROLE'] : null;

		$type = isset($row['TYPE']) ? (string)$row['TYPE'] : null;
		$isProject = $this->isFlagOn($row['PROJECT'] ?? null);

		return new GroupViewResult(
			groupId: $groupId,
			isFound: true,
			canView: GroupViewVerdict::canView(
				isVisibleFlagOn: $isVisibleFlagOn,
				isExtranetGroup: $isExtranetGroup,
				isExtranetUser: $isExtranetUser,
				currentUserRole: $role,
				isModuleAdmin: $isModuleAdmin,
			),
			isVisible: GroupViewVerdict::isVisibleFor($isVisibleFlagOn, $isExtranetGroup, $isExtranetUser),
			role: $role,
			isExtranet: $isExtranetGroup,
			type: $type,
			isProject: $isProject,
			isNewProject: (new Workgroup([
				'TYPE' => $type,
				'PROJECT' => $isProject ? 'Y' : 'N',
			]))->isCollabProject(),
		);
	}

	/**
	 * Batch rows keep raw boolean column values while objects cast them.
	 */
	private function isFlagOn(mixed $value): bool
	{
		return $value === true || $value === 'Y';
	}

	/**
	 * The batch join must match \CExtranet::IsExtranetSocNetGroup() semantics.
	 */
	private function getExtranetSiteId(): string
	{
		if (!Loader::includeModule('extranet'))
		{
			return '';
		}

		return (string)\CExtranet::GetExtranetSiteID();
	}
}
