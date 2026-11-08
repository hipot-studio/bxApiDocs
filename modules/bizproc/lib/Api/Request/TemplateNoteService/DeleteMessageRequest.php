<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Api\Request\TemplateNoteService;

final class DeleteMessageRequest
{
	public function __construct(
		public readonly int $messageId,
		public readonly int $userId,
	)
	{}
}
