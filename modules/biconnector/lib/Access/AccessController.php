<?php

namespace Bitrix\BIConnector\Access;

use Bitrix\BIConnector\Access\Filter\Factory\BIConstructorFilterFactory;
use Bitrix\BIConnector\Access\Model\DashboardAccessItem;
use Bitrix\BIConnector\Access\Model\DashboardAclBatch;
use Bitrix\BIConnector\Access\Model\UserAccessItem;
use Bitrix\BIConnector\Access\Rule\AbstractVariableRule;
use Bitrix\BIConnector\Access\Rule\BaseRule;
use Bitrix\BIConnector\Access\Rule\Factory\BIConstructorRuleFactory;
use Bitrix\BIConnector\Access\Rule\VariableRule;
use Bitrix\BIConnector\Integration\Superset\Model\Dashboard;
use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Main\Access\Exception\UnknownActionException;
use Bitrix\Main\Access\User\AccessibleUser;
use Bitrix\Main\Access\BaseAccessController;

final class AccessController extends BaseAccessController
{
	private ?DashboardAclBatch $dashboardBatch = null;
	private ?DashboardAccessItem $dashboardBatchItem = null;
	private int $dashboardBatchDepth = 0;
	private int $checkDepth = 0;

	public function __construct(int $userId)
	{
		parent::__construct($userId);

		$this->user = $this->loadUser($userId);
		$this->ruleFactory = new BIConstructorRuleFactory();
		$this->filterFactory = new BIConstructorFilterFactory();
	}

	/**
	 * If Model/Dashboard object is available, use DashboardAccessItem::createFromEntity
	 * and pass the access item to check method without unnessesary DB queries.
	 *
	 * @param int|null $itemId
	 *
	 * @return AccessibleItem|null
	 */
	protected function loadItem(?int $itemId = null): ?AccessibleItem
	{
		if ($itemId)
		{
			return DashboardAccessItem::createFromId($itemId);
		}

		return null;
	}

	protected function loadUser(int $userId): AccessibleUser
	{
		return UserAccessItem::createFromId($userId);
	}

	/**
	 * Get instance of AccessController.

	 * @return self
	 */
	public static function getCurrent(): self
	{
		global $USER;

		$userId = 0;
		if (isset($USER) && $USER instanceof \CUser)
		{
			$userId = (int)$USER->GetID();
		}

		return static::getInstance($userId);
	}

	/**
	 * Checking access rights by action.
	 *
	 * @param string $action
	 * @param AccessibleItem|null $item
	 * @param null $params
	 *
	 * @return bool
	 * @throws UnknownActionException
	 */
	public function check(string $action, ?AccessibleItem $item = null, $params = null): bool
	{
		$params ??= [];
		if (is_array($params))
		{
			$params['action'] = $action;
		}

		$this->checkDepth++;
		try
		{
			return parent::check($action, $item, $params);
		}
		finally
		{
			$this->checkDepth--;
		}
	}

	/** @internal Runs the original VIEW dispatch with a chart-local loader scope. */
	public function checkWithDashboardBatch(DashboardAccessItem $item, DashboardAclBatch $batch): bool
	{
		$batch->refresh();
		$previousBatch = $this->dashboardBatch;
		$previousItem = $this->dashboardBatchItem;
		$previousDepth = $this->dashboardBatchDepth;
		$this->dashboardBatch = $batch->isForUser($this->user->getUserId()) ? $batch : null;
		$this->dashboardBatchItem = $item;
		$this->dashboardBatchDepth = $this->checkDepth + 1;
		try
		{
			$result = $this->check(ActionDictionary::ACTION_BIC_DASHBOARD_VIEW, $item);
			$batch->refresh();
			if ($this->dashboardBatch !== null && !$batch->isLegacy())
			{
				$batch->observeAccess($this->user->isAdmin());
			}

			return $result;
		}
		finally
		{
			$this->dashboardBatch = $previousBatch;
			$this->dashboardBatchItem = $previousItem;
			$this->dashboardBatchDepth = $previousDepth;
		}
	}

	/** @internal Effective draft permission may differ from the original VIEW dispatch. */
	public function getDashboardBatch(DashboardAccessItem $item): ?DashboardAclBatch
	{
		if (
			$this->dashboardBatchDepth !== $this->checkDepth
			|| $this->dashboardBatchItem !== $item
			|| $this->dashboardBatch === null
			|| !$this->dashboardBatch->isForUser($this->user->getUserId())
		)
		{
			return null;
		}

		return $this->dashboardBatch;
	}

	/**
	 * @param string $action
	 * @param Dashboard $dashboard
	 * @param null $params
	 *
	 * @return bool
	 */
	public function checkByEntity(string $action, Dashboard $dashboard, $params = null): bool
	{
		$accessItem = DashboardAccessItem::createFromEntity($dashboard);

		return $this->check($action, $accessItem, $params);
	}

	/**
	 * @param string $action
	 * @return array|int|null
	 */
	public function getPermissionValue(string $action)
	{
		$ruleObject = $this->ruleFactory->createFromAction($action, $this);
		if (! $ruleObject instanceof BaseRule)
		{
			return null;
		}

		$params = ['action' => $action];

		return
			$ruleObject instanceof AbstractVariableRule
				? $ruleObject->getPermissionMultiValues($params)
				: $ruleObject->getPermissionValue($params)
		;
	}

	/**
	 * @param string $action
	 * @return array|null
	 */
	public function getAllowedDashboardValue(string $action): ?array
	{
		$ruleObject = $this->ruleFactory->createFromAction($action, $this);
		if (!$ruleObject instanceof VariableRule)
		{
			return null;
		}

		$params = ['action' => $action];

		return $ruleObject->getPermissionAllowedDashboardIds($params);
	}

	/**
	 * @param string $action
	 * @return array|null
	 */
	public function getAllowedGroupValue(string $action): ?array
	{
		$ruleObject = $this->ruleFactory->createFromAction($action, $this);
		if (!$ruleObject instanceof AbstractVariableRule)
		{
			return null;
		}

		$params = ['action' => $action];

		return $ruleObject->getPermissionAllowedGroupIds($params);
	}
}
