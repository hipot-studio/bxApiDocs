<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Access\Measure;

use Bitrix\Crm\V2\Internal\Integration\Catalog\Measure\MeasurePermissionGateway;

/**
 * @internal
 */
class MeasureAccess
{
	public function __construct(
		private readonly MeasurePermissionGateway $permissionGateway = new MeasurePermissionGateway(),
	)
	{
	}

	public function canEdit(): bool
	{
		return $this->canUpdateCrmConfiguration() && $this->permissionGateway->canEdit();
	}

	protected function canUpdateCrmConfiguration(): bool
	{
		return \CCrmAuthorizationHelper::CheckConfigurationUpdatePermission();
	}
}
