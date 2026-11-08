<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Api\Response\TemplateNoteService;

use Bitrix\Bizproc\Result;
use Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote;

/**
 * The notes of a template without their conversations. The number of messages comes along because the
 * store is the only place it can be counted, while the limits ride here so that the editor can show
 * the length of a message against its limit without a call of its own.
 */
final class GetNotesResponse extends Result
{
	/**
	 * @return EO_TemplateNote[]
	 */
	public function getNotes(): array
	{
		return $this->data['notes'] ?? [];
	}

	/**
	 * @param EO_TemplateNote[] $notes
	 */
	public function setNotes(array $notes): self
	{
		$this->data['notes'] = $notes;

		return $this;
	}

	/**
	 * @return array<int, int> note id => number of messages in it
	 */
	public function getMessageCounts(): array
	{
		return $this->data['messageCounts'] ?? [];
	}

	/**
	 * @param array<int, int> $messageCounts
	 */
	public function setMessageCounts(array $messageCounts): self
	{
		$this->data['messageCounts'] = $messageCounts;

		return $this;
	}

	/**
	 * @return array limits under the names the editor reads, see Limits::toArray()
	 */
	public function getLimits(): array
	{
		return $this->data['limits'] ?? [];
	}

	public function setLimits(array $limits): self
	{
		$this->data['limits'] = $limits;

		return $this;
	}
}
