<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Api\Request\TemplateNoteService;

final class CompleteNoteRequest
{
	public function __construct(
		public readonly int $noteId,
		public readonly int $userId,
	)
	{}
}
