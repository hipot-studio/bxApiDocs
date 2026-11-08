<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Api\Request\TemplateNoteService;

final class ReplyToNoteRequest
{
	public function __construct(
		public readonly int $noteId,
		public readonly int $userId,
		public readonly string $message,
	)
	{}
}
