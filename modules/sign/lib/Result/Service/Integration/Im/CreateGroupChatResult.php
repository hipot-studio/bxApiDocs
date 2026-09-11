<?php

namespace Bitrix\Sign\Result\Service\Integration\Im;

use Bitrix\Sign\Result\SuccessResult;

class CreateGroupChatResult extends SuccessResult
{
	public function __construct(
		public readonly int $chatId,
		public readonly ?string $warning = null,
	)
	{
		parent::__construct();
	}
}