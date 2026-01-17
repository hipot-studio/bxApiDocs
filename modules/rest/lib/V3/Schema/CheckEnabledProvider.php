<?php

namespace Bitrix\Rest\V3\Schema;

interface CheckEnabledProvider
{
	public function isEnabled(): bool;
}
