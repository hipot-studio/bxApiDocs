<?php

declare(strict_types=1);

namespace Bitrix\Crm\Service\StartCatalog;

/**
 * The ticket of an entry point, as it was issued: the employee who opened the form of a smart
 * process, the form, the place it was opened from and the key its submission is recognized by.
 *
 * @see TicketService
 */
final readonly class Ticket
{
	public function __construct(
		public int $userId,
		public int $entityTypeId,
		public int $formId,
		public string $key,
		public array $context,
	)
	{
	}
}
