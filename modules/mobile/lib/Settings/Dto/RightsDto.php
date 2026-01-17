<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Settings\Dto;

use Bitrix\Mobile\Dto\Dto;

final class RightsDto extends Dto
{
	public bool $isAllUser;
	/** @var int[] */
	public array $userIds;
	/** @var int[] */
	public array $departmentIds;
	/** @var int[] */
	public array $departmentWithAllChildIds;
}