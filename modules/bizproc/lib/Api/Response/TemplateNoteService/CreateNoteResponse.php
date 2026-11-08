<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Api\Response\TemplateNoteService;

use Bitrix\Bizproc\Result;
use Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote;
use Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNoteMessage;

/**
 * A note never exists without its first message, so the answer carries both or neither.
 */
final class CreateNoteResponse extends Result
{
	use MentionNotificationTrait;

	public function getNote(): ?EO_TemplateNote
	{
		return $this->data['note'] ?? null;
	}

	public function setNote(EO_TemplateNote $note): self
	{
		$this->data['note'] = $note;

		return $this;
	}

	public function getMessage(): ?EO_TemplateNoteMessage
	{
		return $this->data['message'] ?? null;
	}

	public function setMessage(EO_TemplateNoteMessage $message): self
	{
		$this->data['message'] = $message;

		return $this;
	}
}
