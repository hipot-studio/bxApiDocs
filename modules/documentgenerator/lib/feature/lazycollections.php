<?php

namespace Bitrix\DocumentGenerator\Feature;

use Bitrix\Main\Config\Feature\AbstractFlag;

final class LazyCollections extends AbstractFlag
{
	public function enabledByDefault(): bool
	{
		return true;
	}
}
