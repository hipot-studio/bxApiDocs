<?php

namespace Bitrix\Crm\Security\Controller;

class Quote extends Base
{
	/** @var string */
	protected static $progressRegex = '/^QUOTE_ID([0-9A-Z\:\_\-]+)$/i';

	/**
	 * Get Entity Type ID
	 * @return int
	 */
	public function getEntityTypeId(): int
	{
		return \CCrmOwnerType::Quote;
	}

	public function isPermissionEntityTypeSupported($entityType): bool
	{
		if (!self::enabled())
		{
			return false;
		}

		return parent::isPermissionEntityTypeSupported($entityType);
	}

	protected function getSelectFields(): array
	{
		return [
			'ID',
			'ASSIGNED_BY_ID',
			'OPENED',
			'STATUS_ID',
		];
	}

	protected function extractProgressStepFromFields(array $fields): string
	{
		return (isset($fields['STATUS_ID']) ? (string)$fields['STATUS_ID'] : '');
	}

	//region ProgressSteps
	public function hasProgressSteps(): bool
	{
		return true;
	}

	public function getProgressSteps($permissionEntityType): array
	{
		return array_keys(\CCrmStatus::GetStatusList('QUOTE_STATUS'));
	}
	//endregion

	//region Parsing of attributes
	public function tryParseProgressStep($attribute, &$value): bool
	{
		if (preg_match(self::$progressRegex, $attribute, $m) !== 1)
		{
			return false;
		}

		$value = isset($m[1]) ? $m[1] : '';

		return true;
	}

	public function prepareProgressStepAttribute(array $fields): string
	{
		return isset($fields['STATUS_ID']) && $fields['STATUS_ID'] !== ''
			? "STATUS_ID{$fields['STATUS_ID']}" : '';
	}
	//endregion


	public function register(string $permissionEntityType, int $entityId, ?RegisterOptions $options = null): void
	{
		if (self::enabled() && $options)
		{
			(new (\Bitrix\Crm\Security\Manager::getCompatibleController()))
				->register($permissionEntityType, $entityId, $options)
			;
		}

		parent::register($permissionEntityType, $entityId, $options);
	}


	public function unregister(string $permissionEntityType, int $entityId): void
	{
		if (self::enabled())
		{
			(new (\Bitrix\Crm\Security\Manager::getCompatibleController()))
				->unregister($permissionEntityType, $entityId)
			;
		}

		parent::unregister($permissionEntityType, $entityId);
	}

	public static function enabled(): bool
	{
		return	\Bitrix\Main\Config\Option::get('crm.agent', '~CRM_REBUILD_QUOTE_SECURITY_ATTR', 'N') !== 'Y';
	}

	public static function enable(): void
	{
		\Bitrix\Main\Config\Option::delete('crm.agent', ['name' =>'~CRM_REBUILD_QUOTE_SECURITY_ATTR']);
	}


}
