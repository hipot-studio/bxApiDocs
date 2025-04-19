<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Onboarding\Transfer;

use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Tasks\Onboarding\Internal\Type;

final class UserJob
{
	public function __construct(
		public readonly Type $type,
		#[PositiveNumber]
		public readonly int $userId
	)
	{

	}
}