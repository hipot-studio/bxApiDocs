<?php

declare(strict_types=1);

namespace Bitrix\BIConnectorMobile\Dto;

use Bitrix\Mobile\Dto\Dto;

final class DashboardListFilter extends Dto
{
	public string $searchString = '';
	public int $groupId = 0;
	public string $scopeCode = '';
	public string $presetId = '';
	public array $tmpFields = [];
}
