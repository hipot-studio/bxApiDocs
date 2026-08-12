<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\AiAssistant\Tools\Collab;

use Bitrix\Intranet\Enum\InvitationStatus;
use Bitrix\Intranet\Enum\InvitationType;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Dto\Collab\ListInvitationsDto;

class ListInvitationsTool extends BaseCollabTool
{
	public function getName(): string
	{
		return 'list_invitations';
	}

	public function getDescription(): string
	{
		return
			'Lists current project invitations with optional filters by status, channel, and free-text query. '
			. 'This tool is based on current project relations, so it reliably returns current pending and accepted invitations. '
			. 'Historical states such as declined, expired, and revoked are not stored as active relations and therefore return no current rows. '
			. 'Pagination is cursor-based: omit cursor or use 0 for the first page, then pass nextCursor from the previous response to fetch the next page.'
		;
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'projectId' => [
					'type' => 'integer',
					'description' => 'Target project ID.',
					'minimum' => 1,
				],
				'statuses' => [
					'type' => 'array',
					'description' => 'Optional invitation statuses to include.',
					'items' => [
						'type' => 'string',
						'enum' => [
							InvitationStatus::INVITED->value,
							InvitationStatus::ACTIVE->value,
						],
					],
				],
				'channels' => [
					'type' => 'array',
					'description' => 'Optional invitation channels to include.',
					'items' => [
						'type' => 'string',
						'enum' => [InvitationType::EMAIL->value, InvitationType::PHONE->value],
					],
				],
				'query' => [
					'type' => 'string',
					'description' => 'Optional free-text search over recipient name, email, and phone.',
				],
				'limit' => [
					'type' => 'integer',
					'minimum' => 1,
					'maximum' => 100,
					'description' => 'Maximum number of items to return. Default is 20.',
				],
				'cursor' => [
					'type' => 'integer',
					'minimum' => 0,
					'description' => 'Pagination cursor. Use 0 or omit it for the first page. For the next page, pass nextCursor from the previous response. The cursor is the last returned invitationId.',
				],
			],
			'required' => ['projectId'],
			'additionalProperties' => false,
		];
	}

	protected function executeStructuredInternal(int $userId, ...$args): array
	{
		$dto = ListInvitationsDto::fromArray($args);
		$this->resolveProject($userId, $dto->projectId);

		$page = $this->getInvitationPage($dto);

		return [
			'projectId' => $dto->projectId,
			'invitations' => $page['invitations'],
			'limit' => $dto->limit,
			'cursor' => $dto->cursor,
			'nextCursor' => $page['nextCursor'],
			'hasMore' => $page['hasMore'],
		];
	}

	protected function getInvitationPage(ListInvitationsDto $dto): array
	{
		$matches = [];
		$afterId = $dto->cursor;

		do
		{
			$batch = $this->getCurrentProjectInvitationsBatch(
				$dto->projectId,
				afterId: $afterId,
				statuses: $dto->statuses,
			);

			if ($batch['rawCount'] === 0)
			{
				break;
			}

			$afterId = (int)$batch['lastId'];
			$filteredBatch = $this->filterInvitations($batch['items'], $dto);

			foreach ($filteredBatch as $item)
			{
				$matches[] = $item;
				if (count($matches) > $dto->limit)
				{
					break 2;
				}
			}
		}
		while ($batch['rawCount'] === 100);

		$hasMore = count($matches) > $dto->limit;
		$items = array_slice($matches, 0, $dto->limit);
		$nextCursor = null;

		if ($hasMore && !empty($items))
		{
			$nextCursor = (int)($items[array_key_last($items)]['invitationId'] ?? 0);
		}

		return [
			'invitations' => $items,
			'nextCursor' => $nextCursor,
			'hasMore' => $hasMore,
		];
	}

	protected function filterInvitations(array $items, ListInvitationsDto $dto): array
	{
		if (!empty($dto->channels))
		{
			$items = array_values(
				array_filter(
					$items,
					static fn (array $item): bool =>
						is_string($item['channel'] ?? null)
						&& in_array(
							InvitationType::tryFrom($item['channel']),
							$dto->channels,
							true,
						),
				)
			);
		}

		if ($dto->query !== null && trim($dto->query) !== '')
		{
			$query = mb_strtolower(trim($dto->query));
			$items = array_values(array_filter($items, static function(array $item) use ($query): bool
			{
				$recipient = $item['recipient'];
				$haystack = implode(' ', array_filter([
					$recipient['name'] ?? null,
					$recipient['lastName'] ?? null,
					$recipient['fullName'] ?? null,
					$recipient['email'] ?? null,
					$recipient['phoneNumber'] ?? null,
				]));

				return mb_stripos(mb_strtolower($haystack), $query) !== false;
			}));
		}

		return $items;
	}
}
