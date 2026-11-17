<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Task\Dto;

use Bitrix\Tasks\Deadline\Configuration;
use Bitrix\Tasks\Internals\Attribute\Max;
use Bitrix\Tasks\Internals\Attribute\Min;
use Bitrix\Tasks\Internals\Attribute\Required;
use Bitrix\Tasks\Internals\Dto\AbstractBaseDto;

class DefaultDeadlineValueDto extends AbstractBaseDto
{
	#[Required]
	#[Min(1)]
	#[Max(Configuration::MAX_DEFAULT_DEADLINE_IN_SECONDS)]
	public int $defaultDeadlineInSeconds;

	#[Required]
	public bool $isExactDeadlineTime;
}
