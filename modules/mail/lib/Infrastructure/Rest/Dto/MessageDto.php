<?php

declare(strict_types=1);

namespace Bitrix\Mail\Infrastructure\Rest\Dto;

use Bitrix\Rest\V3\Dto\Dto;

class MessageDto extends Dto
{
	public ?int $id;

	public ?int $mailboxId;

	public ?string $mailboxEmail;

	public ?string $subject;

	public ?string $from;

	public ?string $to;

	public ?string $cc;

	public ?string $date;

	public ?bool $isSeen;

	public ?bool $hasAttachments;

	public ?string $url;

	public ?array $bindings;

	public ?string $body;
}
