<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Deadline\Controllers\Dto;

use Bitrix\Tasks\Deadline\Configuration;
use Bitrix\Tasks\Internals\Attribute\Max;
use Bitrix\Tasks\Internals\Attribute\Min;
use Bitrix\Tasks\Internals\Attribute\Required;
use Bitrix\Tasks\Internals\Dto\AbstractBaseDto;

/**
 * @method self setDefault(int $default)
 * @method self setIsExactTime(bool $isExactTime)
 */
class DeadlineDto extends AbstractBaseDto
{
	#[Required]
	#[Min(0)]
	#[Max(Configuration::MAX_DEFAULT_DEADLINE_IN_SECONDS)]
	public int $default;

	#[Required]
	public bool $isExactTime;
}
