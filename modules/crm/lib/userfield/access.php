<?php

namespace Bitrix\Crm\UserField;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\UserPermissions\Configuration;
use Bitrix\Crm\Service\UserPermissions\ConfigurationCallSite;
use Bitrix\Crm\Service\UserPermissions\ConfigurationScope;
use Bitrix\Main\UserField\Types\DoubleType;
use Bitrix\Main\UserField\UserFieldAccess;
use Bitrix\Main\UserFieldTable;

class Access extends UserFieldAccess
{
	private const DEFAULT_DOUBLE_PRECISION = 2;

	/** @var array<int, string[]> */
	private array $availableEntityIdsByUser = [];

	protected function getAvailableEntityIds(): array
	{
		// The list is asked for on every read and every write check, and setUserId() may change the user
		// of a live object - hence a memo per user rather than a single field.
		if (isset($this->availableEntityIdsByUser[$this->userId]))
		{
			return $this->availableEntityIdsByUser[$this->userId];
		}

		$entityIds = [];
		$types = Container::getInstance()->getTypesMap()->getFactories();
		foreach ($types as $type)
		{
			$scope = ConfigurationScope::ofEntityType($type->getEntityTypeId());
			// Reading the fields of an entity serves both operations, so either right opens the list;
			// which of them a field may be written by is decided per operation below.
			if ($this->canManageFields($scope) || $this->canDeleteFields($scope))
			{
				$entityIds[] = $type->getUserFieldEntityId();
			}
		}

		return $this->availableEntityIdsByUser[$this->userId] = $entityIds;
	}

	protected function getImmutableFieldNames(): array
	{
		return [
			\CCrmInvoice::GetUserFieldEntityID() => \CCrmInvoice::GetUserFieldsReserved(),
			\CCrmCompany::GetUserFieldEntityID() => array_keys(\CCrmCompany::getMyCompanyAdditionalUserFields()),
		];
	}

	protected function isFieldImmutable(array $field): bool
	{
		$entityId = $field['ENTITY_ID'] ?? '';
		$immutableFields = $this->getImmutableFieldNames();
		if (isset($immutableFields[$entityId]))
		{
			$fieldName = $field['FIELD_NAME'] ?? '';
			return in_array($fieldName, $immutableFields[$entityId], true);
		}

		return false;
	}

	public function canAdd(array $field): bool
	{
		return parent::canAdd($field)
			&& !$this->isFieldImmutable($field)
			&& $this->canManageFields(ConfigurationScope::ofUserFieldEntityId((string)($field['ENTITY_ID'] ?? '')));
	}

	public function canUpdate(int $id): bool
	{
		$field = UserFieldTable::getById($id)->fetch();

		return parent::canUpdate($id)
			&& $field
			&& !$this->isFieldImmutable($field)
			&& $this->canManageFields(ConfigurationScope::ofUserFieldEntityId((string)($field['ENTITY_ID'] ?? '')));
	}

	public function canDelete(int $id): bool
	{
		$field = UserFieldTable::getById($id)->fetch();

		// Not parent::canDelete(): it delegates to canUpdate(), which would make deleting a field
		// demand the right to change one as well. The line below has to stay equal to the body of
		// parent::canUpdate() - a check added there is not inherited here.
		return $this->canRead($id)
			&& $field
			&& !$this->isFieldImmutable($field)
			&& $this->canDeleteFields(ConfigurationScope::ofUserFieldEntityId((string)($field['ENTITY_ID'] ?? '')));
	}

	public function getRestrictedTypes(): array
	{
		return array_merge(parent::getRestrictedTypes(), [
			'video',
			'vote',
			'url_preview',
			'string_formatted',
			'disk_file',
			'disk_version',
			'snils',
		]);
	}

	public function getDefaultSettings(array $field): array
	{
		if (($field['USER_TYPE_ID'] ?? null) !== DoubleType::USER_TYPE_ID)
		{
			return [];
		}

		$entityId = (string)($field['ENTITY_ID'] ?? '');
		$entityTypeId = (int)\CCrmOwnerType::ResolveIDByUFEntityID($entityId);
		if (!\CCrmOwnerType::isDefined($entityTypeId))
		{
			return [];
		}

		return [
			'PRECISION' => self::DEFAULT_DOUBLE_PRECISION,
		];
	}

	private function canManageFields(ConfigurationScope $scope): bool
	{
		return $this->configuration()->canManageFields(ConfigurationCallSite::UserFieldAccess, $scope);
	}

	private function canDeleteFields(ConfigurationScope $scope): bool
	{
		return $this->configuration()->canDeleteFields(ConfigurationCallSite::UserFieldAccess, $scope);
	}

	private function configuration(): Configuration
	{
		return Container::getInstance()->getUserPermissions($this->userId)->configuration();
	}
}
