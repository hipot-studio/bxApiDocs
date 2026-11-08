<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Menu\MyCompany\Dto;

use Bitrix\Mobile\Dto\Dto;
use Bitrix\Mobile\Provider\CommonUserDto;

/**
 * Data of the employees card: the preview of the staff it shows and the two permissions it acts by.
 */
final class EmployeesCardData extends Dto
{
	/** @var CommonUserDto[] preview of the staff, colleagues of the user first */
	public array $users;

	public int $totalUsersCount;

	public bool $canInvite;

	/** Whether the calls of the card may be placed by the user: read on the tap alone. */
	public bool $canUseTelephony;
}
