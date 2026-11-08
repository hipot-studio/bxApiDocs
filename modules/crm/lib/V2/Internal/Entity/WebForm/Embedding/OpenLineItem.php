<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\WebForm\Embedding;

/**
 * An open line chat of the portal projected against a requested CRM form.
 *
 * A chat greets its visitors with at most one welcome form, so the state of that form is what makes
 * the chat an option for the requested one. A chat already taken by a foreign form is known by the
 * id of that form alone: its name belongs to the form, not to the chat.
 *
 * @internal
 */
final readonly class OpenLineItem
{
	public function __construct(
		public int $id,
		public string $name,
		public bool $bound,
		public bool $welcomeFormEnabled,
		public ?int $welcomeFormId,
		public bool $welcomeFormDelayed,
	)
	{
	}
}
