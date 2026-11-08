<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Api\Request\TemplateNoteService;

final class CreateNoteRequest
{
	/**
	 * @param int $userId the current user; the note and its first message are attributed to him, the
	 * author never comes from the payload
	 */
	public function __construct(
		public readonly int $templateId,
		public readonly int $userId,
		public readonly NoteAnchor $anchor,
		public readonly Coordinates $offset,
		public readonly Coordinates $position,
		public readonly string $message,
	)
	{}
}
