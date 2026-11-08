<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Api\Response\TemplateNoteService;

use Bitrix\Bizproc\Result;
use Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote;
use Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNoteMessage;

final class GetNoteThreadResponse extends Result
{
	public function getNote(): ?EO_TemplateNote
	{
		return $this->data['note'] ?? null;
	}

	public function setNote(EO_TemplateNote $note): self
	{
		$this->data['note'] = $note;

		return $this;
	}

	/**
	 * @return EO_TemplateNoteMessage[] in the order they were written
	 */
	public function getMessages(): array
	{
		return $this->data['messages'] ?? [];
	}

	/**
	 * @param EO_TemplateNoteMessage[] $messages
	 */
	public function setMessages(array $messages): self
	{
		$this->data['messages'] = $messages;

		return $this;
	}
}
