<?php

namespace Bitrix\Crm\EntityForm;

use Bitrix\Crm\Entity\EntityEditorOptionParser;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\UserPermissions\ConfigurationCallSite;
use Bitrix\Crm\Service\UserPermissions\ConfigurationScope;
use Bitrix\Ui\EntityForm\Scope;

class ScopeAccess extends \Bitrix\Ui\EntityForm\ScopeAccess
{
	protected Scope $scope;
	protected EntityEditorOptionParser $editorOptionParser;

	public function __construct(string $moduleId = null, int $userId = null)
	{
		parent::__construct($moduleId, $userId);

		$this->scope = Scope::getInstance($this->userId);
		$this->editorOptionParser = new EntityEditorOptionParser();
	}

	/**
	 * @param int $scopeId
	 * @return bool
	 */
	public function canRead(int $scopeId): bool
	{
		$scope = $this->scope->getById($scopeId);

		return (
			(isset($scope) && $this->canAddByEntityTypeId($scope['ENTITY_TYPE_ID']))
			|| $this->scope->isHasScope($scopeId)
		);
	}

	/**
	 * Not a path of the common card views: every live scope operation of a CRM card goes through
	 * {@see isAdminForEntityTypeId()}, and the parent methods that would reach this one - `canUpdate()`
	 * and `canAddByEntityTypeId()` - are overridden here. It stays on the global check on purpose: it
	 * names no card, so the contextual right of one cannot answer for it, while dropping the override
	 * would hand the answer to the permissive default of the parent.
	 *
	 * @return bool
	 */
	public function canAdd(): bool
	{
		return Container::getInstance()->getUserPermissions($this->userId)->isCrmAdmin();
	}

	public function canAddByEntityTypeId(string $entityTypeId): bool
	{
		return $this->isAdminForEntityTypeId($entityTypeId);
	}

	/**
	 * @param int $scopeId
	 * @return bool
	 */
	public function canUpdate(int $scopeId): bool
	{
		$scope = $this->scope->getById($scopeId);
		return (isset($scope) && $this->canAddByEntityTypeId($scope['ENTITY_TYPE_ID']) && $scope['CATEGORY'] === $this->moduleId);
	}

	/**
	 * @param array|int $scopeIds
	 * @return bool
	 */
	public function canDelete($scopeIds): bool
	{
		if (!is_array($scopeIds))
		{
			$scopeIds = [$scopeIds];
		}

		foreach ($scopeIds as $scopeId)
		{
			if (!$this->canUpdate($scopeId))
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * Not a path of the common card views either, for the same reason as {@see canAdd()}: the parent
	 * `isAdminForEntityTypeId()` that would reach it is overridden here.
	 */
	public function isAdmin(): bool
	{
		return Container::getInstance()->getUserPermissions($this->userId)->isAdmin();
	}

	/**
	 * The live contextual entry of the surface: every scope operation of the CRM card reaches it, and
	 * the card is named by the option name the scope was saved under ("deal_details_C21").
	 */
	public function isAdminForEntityTypeId(string $entityTypeId): bool
	{
		$parseResult = $this->editorOptionParser->parse($entityTypeId);
		if (!$parseResult->isEntityTypeIdFound())
		{
			return false;
		}

		return Container::getInstance()
			->getUserPermissions($this->userId)
			->configuration()
			->canManageCommonViews(
				ConfigurationCallSite::ScopeAccess,
				ConfigurationScope::ofEntityType($parseResult->entityTypeId(), $parseResult->categoryId()),
			);
	}

	public function canUseOnAddOnUpdateSegregation(): bool
	{
		return true;
	}
}
