<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Service;

use Bitrix\Bizproc\Internal\Access\AccessController;
use Bitrix\Bizproc\Internal\Access\Model\TemplateItem;
use Bitrix\Bizproc\Internal\Access\Model\UserModel;
use Bitrix\Bizproc\Internal\Access\Permission\PermissionDictionary;
use Bitrix\Bizproc\Internal\Container;

/**
 * Single public entry point for workflow-template ACL checks. Every channel (chart editor, template
 * service, classic designer, grid/REST, permissions page) asks this facade instead of the legacy
 * document-type gate.
 *
 * publish dominates edit per template (SDD invariant 1): a holder of "publish" on a template may also
 * edit that template, but not the other way around. The OR lives here and is evaluated per concrete
 * template — never as a global "has any publish" shortcut. The other rights are independent.
 *
 * A null $userId resolves the CURRENT user (never someone else's or an anonymous one). $documentType is
 * reserved for the document-type / owner scope the rule may consume later; the current flat-matrix rule
 * ignores it.
 */
class TemplateAccessService
{
	public function canAccessTemplates(?int $userId = null): bool
	{
		$controller = $this->controller($userId);

		return $controller->check((string)PermissionDictionary::BIZPROC_TEMPLATE_CREATE)
			|| $controller->check((string)PermissionDictionary::BIZPROC_TEMPLATE_EDIT)
			|| $controller->check((string)PermissionDictionary::BIZPROC_TEMPLATE_PUBLISH);
	}

	public function canCreate(?int $userId = null, array $documentType = []): bool
	{
		// CREATE is a scope-less toggler: no template item participates.
		return $this->controller($userId)->check((string)PermissionDictionary::BIZPROC_TEMPLATE_CREATE);
	}

	public function canEdit(int $templateId, ?int $userId = null, array $documentType = []): bool
	{
		return $this->check(PermissionDictionary::BIZPROC_TEMPLATE_EDIT, $templateId, $userId)
			|| $this->check(PermissionDictionary::BIZPROC_TEMPLATE_PUBLISH, $templateId, $userId);
	}

	/**
	 * A storage holding a data view answers to the template owning the view; any other storage stays open.
	 * The owner is not client input, so a since-deleted owner template does not lock its leftover view.
	 */
	public function canEditStorage(int $storageTypeId, ?int $userId = null): bool
	{
		if ($storageTypeId <= 0)
		{
			return true;
		}

		$view = Container::getDataViewRepository()?->getByStorageTypeId($storageTypeId);
		$ownerTemplateId = $view?->getOwnerTemplateId();

		return $ownerTemplateId === null
			|| $ownerTemplateId <= 0
			|| $this->canEdit($ownerTemplateId, $userId)
			|| !Container::getWorkflowTemplateRepository()?->exists($ownerTemplateId);
	}

	/**
	 * Storages of views whose owner template the user may not edit; one right check per distinct owner.
	 *
	 * @return int[]
	 */
	public function getClosedStorageTypeIds(?int $userId = null): array
	{
		if ($this->controller($userId)->isAdmin())
		{
			return [];
		}

		$ownerByStorageTypeId = Container::getDataViewRepository()?->getOwnerTemplateIds() ?? [];

		$ownerIds = [];
		foreach ($ownerByStorageTypeId as $ownerTemplateId)
		{
			$ownerTemplateId = (int)$ownerTemplateId;
			if ($ownerTemplateId > 0)
			{
				$ownerIds[$ownerTemplateId] = $ownerTemplateId;
			}
		}

		if ($ownerIds === [])
		{
			return [];
		}

		$this->warmupAuthorshipForPermission(
			array_values($ownerIds),
			PermissionDictionary::BIZPROC_TEMPLATE_EDIT,
			$userId,
		);

		$lockingOwnerIds = [];
		foreach ($ownerIds as $ownerTemplateId)
		{
			if (!$this->canEdit($ownerTemplateId, $userId))
			{
				$lockingOwnerIds[] = $ownerTemplateId;
			}
		}
		$lockingOwnerIds = array_flip(
			Container::getWorkflowTemplateRepository()?->filterExisting($lockingOwnerIds) ?? [],
		);

		$closedIds = [];
		foreach ($ownerByStorageTypeId as $storageTypeId => $ownerTemplateId)
		{
			if (isset($lockingOwnerIds[(int)$ownerTemplateId]))
			{
				$closedIds[] = (int)$storageTypeId;
			}
		}

		return $closedIds;
	}

	/**
	 * The edit right of a whole set of users on one template - the question a caller holding the set already
	 * asks: the page of candidates of the mention hint, the addressees of one note message. The single entry
	 * point for such a caller, so that a set is answered by the very same canEdit() and never by a second
	 * reading of the matrix beside it.
	 *
	 * A user is answered once however many times the set names them, and an id of zero or below is dropped
	 * rather than resolved to the current user: a set is a set of concrete people. An empty set costs nothing.
	 *
	 * The set is what makes the checks cheap: the access codes of every user of it are read by one query before
	 * the first check instead of a query per user inside them. That prewarm is an optimization and not a gate -
	 * every answer stays the one canEdit() gives on its own, only more expensive - and it is dropped as soon as
	 * the set is answered, exceptions included {@see UserModel::preloadAccessCodes()}.
	 *
	 * @param int[] $userIds
	 * @return array<int, bool> user id => whether that user may edit the template, in the order of the input
	 */
	public function canEditBatch(int $templateId, array $userIds): array
	{
		$askedIds = [];
		foreach ($userIds as $userId)
		{
			$userId = (int)$userId;
			if ($userId > 0)
			{
				$askedIds[$userId] = $userId;
			}
		}

		if (!$askedIds)
		{
			return [];
		}

		$rights = [];
		$this->preloadAccessCodes($askedIds);

		try
		{
			foreach ($askedIds as $userId)
			{
				$rights[$userId] = $this->canEdit($templateId, $userId);
			}
		}
		finally
		{
			UserModel::forgetPreloadedAccessCodes();
		}

		return $rights;
	}

	/**
	 * @param int[] $userIds
	 */
	private function preloadAccessCodes(array $userIds): void
	{
		$repository = Container::getAccessRepository();
		if ($repository === null)
		{
			return;
		}

		UserModel::preloadAccessCodes($repository->getUserAccessCodes($userIds));
	}

	public function canPublish(int $templateId, ?int $userId = null, array $documentType = []): bool
	{
		return $this->check(PermissionDictionary::BIZPROC_TEMPLATE_PUBLISH, $templateId, $userId);
	}

	public function canDelete(int $templateId, ?int $userId = null, array $documentType = []): bool
	{
		return $this->check(PermissionDictionary::BIZPROC_TEMPLATE_DELETE, $templateId, $userId);
	}

	/**
	 * Batch warmup of the authorship predicate for a caller that already holds the whole list of ids (the
	 * bulk delete of the processes grid, a page of the AI-agents grid). A separate method rather than
	 * something inside the checks: the batch cannot be derived from a per-template call. It is an
	 * optimization, not a gate - every check stays correct without it, only more expensive.
	 *
	 * Warms the deletion right, the caller this batch was introduced for. A caller that is about to check
	 * another right asks {@see self::warmupAuthorshipForPermission()} instead: the signature published here
	 * stays as it is, because this facade is an extension point and a method an inheritor overrides cannot
	 * grow a parameter without breaking the load of that inheritor.
	 *
	 * @param array<int, int|string> $templateIds
	 */
	public function warmupAuthorship(array $templateIds, ?int $userId = null): void
	{
		$this->warmupAuthorshipForPermission(
			$templateIds,
			PermissionDictionary::BIZPROC_TEMPLATE_DELETE,
			$userId,
		);
	}

	/**
	 * The same batch for a caller that is about to check $permission rather than the deletion right: the
	 * warmup is skipped whenever the checks ahead cannot reach authorship at all, so a user who never asks
	 * the question pays no query for it {@see self::needsAuthorship()}.
	 *
	 * @param array<int, int|string> $templateIds
	 * @param int $permission One of the multivariables rights of {@see PermissionDictionary}.
	 */
	public function warmupAuthorshipForPermission(
		array $templateIds,
		int $permission,
		?int $userId = null,
	): void
	{
		$controller = $this->controller($userId);
		if (!$this->needsAuthorship($controller, $permission))
		{
			return;
		}

		$controller->authorship()->warmup($templateIds);
	}

	/**
	 * Whether a check of $permission by this user can reach the authorship branch of the rule at all. Read
	 * off the scope the check itself consults ({@see \Bitrix\Bizproc\Internal\Access\Rule\BaseRule::checkScope()}),
	 * so no state of its own is introduced: a portal administrator never gets as far as the scope, an "all"
	 * scope and a scope without the "own" sentinel answer before the authorship branch, and an empty scope
	 * refuses outright. The portal switch needs no reading either - the sentinel is subtracted at the source
	 * of the scope while it is off.
	 *
	 * The edit right is an OR of two scopes here (publish dominates edit), so both are read for it - the same
	 * rule as in canEdit(), never a second copy of it.
	 */
	private function needsAuthorship(AccessController $controller, int $permission): bool
	{
		if ($controller->isAdmin())
		{
			return false;
		}

		/** @var UserModel $user */
		$user = $controller->getUser();

		foreach ($this->scopesOfCheck($permission) as $permissionId)
		{
			$scope = $user->getPermissionMulti((string)$permissionId) ?? [];

			// strictly, exactly as the rule compares: a scope value is an int by the contract of the layout
			if (
				in_array(PermissionDictionary::VALUE_VARIATION_OWN, $scope, true)
				&& !in_array(PermissionDictionary::VALUE_VARIATION_ALL, $scope, true)
			)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Rights a check of $permission consults, in the order it consults them: one right per check, except the
	 * edit one, which canEdit() answers as "edit OR publish".
	 *
	 * @return list<int>
	 */
	private function scopesOfCheck(int $permission): array
	{
		return $permission === PermissionDictionary::BIZPROC_TEMPLATE_EDIT
			? [PermissionDictionary::BIZPROC_TEMPLATE_EDIT, PermissionDictionary::BIZPROC_TEMPLATE_PUBLISH]
			: [$permission]
		;
	}

	public function canConfigureRights(?int $userId = null): bool
	{
		// CONFIGURE_RIGHTS is a scope-less toggler: holding it (or being an admin) grants full access to the
		// rights configuration (any right, every role, every template). No template item participates.
		return $this->controller($userId)->check((string)PermissionDictionary::BIZPROC_TEMPLATE_CONFIGURE_RIGHTS);
	}

	private function check(int $permission, int $templateId, ?int $userId): bool
	{
		return $this->controller($userId)->check((string)$permission, new TemplateItem($templateId));
	}

	protected function controller(?int $userId): AccessController
	{
		return $userId === null
			? AccessController::getCurrent()
			: AccessController::getInstance($userId);
	}
}
