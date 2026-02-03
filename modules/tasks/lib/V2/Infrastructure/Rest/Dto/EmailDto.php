<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Rest\Dto;

use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Tasks\V2\Internal\Integration\Mail\Entity\Email;

class EmailDto extends Dto
{
	#[Filterable, Sortable]
	public ?int $id;
	public ?int $taskId;
	public ?int $mailboxId;
	public ?string $title;
	public ?string $body;
	public ?string $from;
	public ?int $dateTs;
	public ?string $link;

	public static function fromEntity(?Email $email, ?Request $request = null): ?self
	{
		if (!$email)
		{
			return null;
		}

		$select = $request?->select?->getList(true) ?? [];

		$dto = new self();

		if (empty($select) || in_array('id', $select, true))
		{
			$dto->id = $email->id;
		}

		if (empty($select) || in_array('taskId', $select, true))
		{
			$dto->taskId = $email->taskId;
		}

		if (empty($select) || in_array('mailboxId', $select, true))
		{
			$dto->mailboxId = $email->mailboxId;
		}

		if (empty($select) || in_array('title', $select, true))
		{
			$dto->title = $email->title;
		}

		if (empty($select) || in_array('body', $select, true))
		{
			$dto->body = $email->body;
		}

		if (empty($select) || in_array('from', $select, true))
		{
			$dto->from = $email->from;
		}

		if (empty($select) || in_array('dateTs', $select, true))
		{
			$dto->dateTs = $email->dateTs;
		}

		if (empty($select) || in_array('link', $select, true))
		{
			$dto->link = $email->link;
		}

		return $dto;
	}
}
