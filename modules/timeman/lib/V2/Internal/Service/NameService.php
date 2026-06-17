<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Service;

class NameService
{
	public function format(array $user): string
	{
		return \CUser::FormatName(
			\CSite::GetNameFormat(),
			$user,
			true,
			false,
		);
	}
}
