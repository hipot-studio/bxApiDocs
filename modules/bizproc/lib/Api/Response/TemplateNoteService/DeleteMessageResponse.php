<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Api\Response\TemplateNoteService;

use Bitrix\Bizproc\Result;

final class DeleteMessageResponse extends Result
{
	/**
	 * True when that was the last message of the note and the note is gone with it.
	 */
	public function isNoteRemoved(): bool
	{
		return $this->data['noteRemoved'] ?? false;
	}

	public function setNoteRemoved(bool $noteRemoved): self
	{
		$this->data['noteRemoved'] = $noteRemoved;

		return $this;
	}
}
