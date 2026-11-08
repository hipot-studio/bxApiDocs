<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Config\Feature;

use Bitrix\Main\Config\Feature\AbstractFlag;

final class LegacyNonAppEmbeddings extends AbstractFlag
{
	public function enabledByDefault(): bool
	{
		return false;
	}
}
