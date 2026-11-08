<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\WebForm\Section;

/**
 * @internal
 */
final readonly class Responsible
{
	public const FIELD_USER_IDS = 'userIds';
	public const FIELD_CHECK_WORK_TIME = 'checkWorkTime';

	/**
	 * @param null|array<int> $userIds
	 */
	public function __construct(
		public ?array $userIds = null,
		public ?bool $checkWorkTime = null,
	)
	{
	}
}
