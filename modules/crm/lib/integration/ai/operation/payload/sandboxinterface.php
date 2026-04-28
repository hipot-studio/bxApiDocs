<?php

namespace Bitrix\Crm\Integration\AI\Operation\Payload;

interface SandboxInterface
{
	public function setSandboxData(array $data): self;
}
