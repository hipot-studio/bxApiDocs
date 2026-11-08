<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Access\Rule;

use Bitrix\Bizproc\Internal\Access\Permission\PermissionDictionary;
use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Main\Access\Rule\AbstractRule;

/**
 * Single flat-matrix rule: the action passed to the controller is the permission code from
 * {@see PermissionDictionary}. Toggler permissions ({@see PermissionDictionary::BIZPROC_TEMPLATE_CREATE}) are a plain
 * grant; multivariables permissions are checked against the template scope carried by the item.
 *
 * @property \Bitrix\Bizproc\Internal\Access\Model\UserModel $user
 * @property \Bitrix\Bizproc\Internal\Access\AccessController $controller
 */
class BaseRule extends AbstractRule
{
	public function execute(?AccessibleItem $item = null, $params = null): bool
	{
		if ($this->controller->isAdmin())
		{
			return true;
		}

		$params = is_array($params) ? $params : [];
		$permissionId = (string)($params['action'] ?? '');
		if ($permissionId === '')
		{
			return false;
		}

		$descriptor = PermissionDictionary::getPermission($permissionId);
		if (($descriptor['type'] ?? null) === PermissionDictionary::TYPE_MULTIVARIABLES)
		{
			return $this->checkScope($permissionId, $item);
		}

		return (bool)$this->user->getPermission($permissionId);
	}

	/**
	 * The branch order matters: "all", then a match by template id, then "own". The template row is read
	 * on the last branch only, so a holder of a plain scope keeps its current query count.
	 *
	 * The portal flag is not read here: the "own" sentinel is already subtracted at the source of the scope
	 * ({@see \Bitrix\Bizproc\Internal\Access\Model\UserModel::getPermissionMulti}), so the value either is in
	 * the scope and applies, or is absent altogether.
	 */
	private function checkScope(string $permissionId, ?AccessibleItem $item): bool
	{
		$scope = $this->user->getPermissionMulti($permissionId);
		if (!$scope)
		{
			return false;
		}

		if (in_array(PermissionDictionary::VALUE_VARIATION_ALL, $scope, true))
		{
			return true;
		}

		$ownGranted = in_array(PermissionDictionary::VALUE_VARIATION_OWN, $scope, true);

		if ($item === null)
		{
			return true;
		}

		$templateId = (int)$item->getId();

		// A row that does not exist yet is decided by the mere presence of "own" in the scope: there is no
		// authorship to compare against. Named explicitly instead of leaning on the branch above, which
		// grants on any non-empty scope - for "own" that would be no authorship check at all.
		if ($templateId <= 0)
		{
			return $ownGranted;
		}

		if (in_array($templateId, $scope, true))
		{
			return true;
		}

		if (!$ownGranted)
		{
			return false;
		}

		return $this->controller->authorship()->isOwn($templateId, $this->user->getUserId());
	}
}
