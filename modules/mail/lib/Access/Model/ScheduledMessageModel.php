<?php

declare(strict_types=1);

namespace Bitrix\Mail\Access\Model;

final class ScheduledMessageModel
{
	/** @param array<string, mixed> $fields */
	public function __construct(private readonly array $fields)
	{
	}

	public function getId(): int
	{
		return (int)($this->fields['ID'] ?? 0);
	}

	public function getAuthorId(): int
	{
		return (int)($this->fields['AUTHOR_ID'] ?? 0);
	}

	public function getMailboxId(): int
	{
		return (int)($this->fields['MAILBOX_ID'] ?? 0);
	}

	/** @return array<string, mixed> */
	public function getFields(): array
	{
		return $this->fields;
	}
}
