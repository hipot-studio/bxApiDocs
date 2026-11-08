<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Api\Response\TemplateNoteService;

use Bitrix\Bizproc\Result;
use Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNoteMessage;

final class ReplyToNoteResponse extends Result
{
	use MentionNotificationTrait;

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
