<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\AiAssistant\Service\Tool\Mailbox;

use Bitrix\AiAssistant\Definition\Tool\Contract\ToolContract;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Mail\MailboxTable;

class ListMailboxesTool extends ToolContract
{
	public const ACTION_NAME = 'list_mailboxes';

	public function __construct(
		TracedLogger $tracedLogger,
	)
	{
		parent::__construct($tracedLogger);
	}

	public function getName(): string
	{
		return self::ACTION_NAME;
	}

	public function getDescription(): string
	{
		return
			"Returns the list of active email mailboxes available to the user. "
			. "Each mailbox includes its identifier, email address, and name. "
			. "Optionally filters by name or email."
		;
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'name' => [
					'type' => ['string', 'null'],
					'description' => 'Filter by mailbox name (case-insensitive, partial match).',
					'minLength' => 1,
				],
				'email' => [
					'type' => ['string', 'null'],
					'description' => 'Filter by email address (case-insensitive, partial match).',
					'minLength' => 1,
				],
			],
			'required' => [],
			'additionalProperties' => false,
		];
	}

	public function canList(int $userId): bool
	{
		return true;
	}

	public function canRun(int $userId): bool
	{
		return true;
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$mailboxes = MailboxTable::getUserMailboxes($userId);

		$result = $this->filterAndFormat(
			$mailboxes,
			$args['name'] ?? null,
			$args['email'] ?? null,
		);

		return [
			'mailboxes' => $result,
		];
	}

	/**
	 * @return array<int, array{id: int, email: string, name: string}>
	 */
	private function filterAndFormat(array $mailboxes, ?string $name, ?string $email): array
	{
		$result = [];

		foreach ($mailboxes as $mailbox)
		{
			if ($name !== null && mb_stripos($mailbox['NAME'] ?? '', $name) === false)
			{
				continue;
			}

			if ($email !== null && mb_stripos($mailbox['EMAIL'] ?? '', $email) === false)
			{
				continue;
			}

			$result[] = [
				'id' => (int)($mailbox['ID'] ?? 0),
				'email' => $mailbox['EMAIL'] ?? '',
				'name' => $mailbox['NAME'] ?? '',
			];
		}

		return $result;
	}
}
