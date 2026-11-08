<?php

namespace Bitrix\DocumentGenerator\Feature;

use Bitrix\Main\Config\Feature\AbstractFlag;

final class Timezone extends AbstractFlag
{
	public function enabledByDefault(): bool
	{
		return true;
	}
}
