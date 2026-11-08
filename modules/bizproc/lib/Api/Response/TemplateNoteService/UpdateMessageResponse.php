<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Api\Response\TemplateNoteService;

use Bitrix\Bizproc\Result;
use Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNoteMessage;

final class UpdateMessageResponse extends Result
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

	/**
	 * The message as it stands now. Comes with the REVISION_MISMATCH refusal instead of the rewritten
	 * message, so that the editor shows what it has missed and offers to repeat the edit without
	 * reading the whole conversation again.
	 */
	public function getActualMessage(): ?EO_TemplateNoteMessage
	{
		return $this->data['actualMessage'] ?? null;
	}

	public function setActualMessage(EO_TemplateNoteMessage $message): self
	{
		$this->data['actualMessage'] = $message;

		return $this;
	}
}
