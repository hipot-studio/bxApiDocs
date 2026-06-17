<?php

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool;

use Bitrix\Crm\Dto\Dto;

abstract class AbstractToolDto extends Dto
{
	final public function __construct(
		private readonly int $currentUserId,
		?array $fields = null,
	)
	{
		parent::__construct($fields);
	}

	final public function getUserId(): int
	{
		return $this->currentUserId;
	}
}
