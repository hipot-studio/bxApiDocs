<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service;

use CSite;
use CUser;

class NameService
{
	public function format(array $user): string
	{
		return CUser::FormatName(
			CSite::GetNameFormat(),
			$user,
			true,
			false,
		);
	}
}
