<?php

namespace Bitrix\Main\Cli\Security;

use RuntimeException;

class UnsafeCommandGuard
{
	public function assertAllowed(): void
	{
		if (
			!defined('BX_LOCAL_CLI_ALLOW_UNSAFE_COMMANDS')
			|| BX_LOCAL_CLI_ALLOW_UNSAFE_COMMANDS !== true
		)
		{
			throw new RuntimeException(
				'Unsafe commands are disabled. Locally define BX_LOCAL_CLI_ALLOW_UNSAFE_COMMANDS constant with value true',
			);
		}
	}
}
