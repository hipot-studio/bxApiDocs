<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Api\Request\TemplateNoteService;

final class UpdateMessageRequest
{
	/**
	 * @param int $revision the revision the editor has been showing; the message is rewritten only while
	 * it still stands, otherwise the answer carries the actual message instead
	 */
	public function __construct(
		public readonly int $messageId,
		public readonly int $userId,
		public readonly string $message,
		public readonly int $revision,
	)
	{}
}
