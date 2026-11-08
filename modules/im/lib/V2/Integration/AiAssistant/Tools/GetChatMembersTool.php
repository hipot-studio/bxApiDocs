<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AiAssistant\Tools;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Integration\AiAssistant\Tools\Dto\GetChatMembersDto;
use Bitrix\Im\V2\Integration\AiAssistant\Tools\Support\ImToolContext;
use Bitrix\Im\V2\Integration\AiAssistant\Tools\Support\ImToolFormatter;
use Bitrix\Im\V2\Integration\AiAssistant\ToolScope;
use Bitrix\Im\V2\Permission\Action;
use Bitrix\Im\V2\Relation\Provider\RelationCursor;
use Bitrix\Im\V2\Relation\Provider\RelationProvider;

class GetChatMembersTool extends BaseImTool
{
	private const DEFAULT_LIMIT = 50;
	private const MAX_LIMIT = 100;

	protected function getScope(): ToolScope
	{
		return ToolScope::Read;
	}

	public function getName(): string
	{
		return 'get_chat_members';
	}

	public function getDescription(): string
	{
		return 'Returns the active, visible members of a single chat with their role (owner, manager or member), '
			. 'provided the current user is allowed to view the member list. Members whose user account has been '
			. 'deactivated are not listed. Results use a stable order and are paged with an opaque cursor. '
			. 'Read-only. In the response, chat.type is a one-letter chat type code (e.g. "C" for a group chat, '
			. '"P" for a private chat).';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'dialogId' => [
					'description' => 'The dialog identifier. For a private chat use the user ID, for a group chat use chat<ID>.',
					'type' => 'string',
					'pattern' => '^(chat\d+|\d+)$',
				],
				'limit' => [
					'description' => 'Maximum number of members to return.',
					'type' => 'integer',
					'minimum' => 1,
					'maximum' => self::MAX_LIMIT,
					'default' => self::DEFAULT_LIMIT,
				],
				'cursor' => [
					'description' => 'Opaque pagination cursor. Omit it for the first page; to fetch the next page, '
						. 'pass back the "nextCursor" object exactly as returned by the previous get_chat_members call. '
						. 'Do not construct or edit it manually.',
					'type' => 'object',
					'properties' => [
						'role' => [
							'type' => 'string',
							'enum' => ['owner', 'manager', 'member'],
						],
						'relationId' => [
							'type' => 'integer',
							'minimum' => 1,
						],
					],
					'additionalProperties' => false,
					'required' => ['role', 'relationId'],
				],
			],
			'additionalProperties' => false,
			'required' => ['dialogId'],
		];
	}

	protected function executeStructuredTool(int $userId, ...$args): array
	{
		$context = new ImToolContext();
		$formatter = new ImToolFormatter();

		$dto = GetChatMembersDto::createFromParams($args);
		$context->validateDto($dto);

		$chat = $context->resolveChatByDialogId($userId, $dto->dialogId);

		// The permission matrix maps UserList to ROLE_NONE for every TYPE='P' chat, so canDo() would
		// deny even a member of a dialog the contract explicitly supports. Private chats are gated by
		// membership instead — the same rule REST im.v2.Chat.Member.* applies; other types keep the
		// stricter UserList gate of the UI member list.
		if ($chat->getType() === Chat::IM_TYPE_PRIVATE)
		{
			$context->assertChatAccess($chat, $userId);
		}
		else
		{
			$context->assertCanDo($chat, $userId, Action::UserList);
		}

		$cursor = null;
		if ($dto->cursor !== null)
		{
			$cursor = RelationCursor::createFromArray($dto->cursor);
			$context->validateDto(
				$cursor,
				'Invalid cursor. Pass back the "nextCursor" object exactly as returned by the previous '
				. 'get_chat_members call, or omit the cursor to start from the first page.',
			);
		}

		// Probe one extra relation to detect a real next page: a page of exactly `limit` members
		// must not report hasMore=true when nothing actually follows it.
		$members = (new RelationProvider((int)$chat->getChatId()))->getMembers($dto->limit + 1, $cursor);

		$memberRelations = array_values(iterator_to_array($members, false));
		$hasMore = count($memberRelations) > $dto->limit;
		$memberRelations = array_slice($memberRelations, 0, $dto->limit);

		$nextCursor = null;
		if ($hasMore)
		{
			// The window is sorted by (ROLE_PRIORITY, ID), so the last visible relation is the exact
			// resume point; the probe relation stays outside the window and opens the next page.
			$lastRelation = $memberRelations[array_key_last($memberRelations)];
			$nextCursor = new RelationCursor($lastRelation->getRole(), (int)$lastRelation->getId());
		}

		$formatter->preloadUsers(array_map(static fn ($relation) => $relation->getUserId(), $memberRelations));

		return [
			'chat' => $formatter->formatChat($chat, $userId),
			'members' => array_map(
				static fn ($relation) => $formatter->formatMember($relation),
				$memberRelations,
			),
			'pagination' => [
				'limit' => $dto->limit,
				'cursor' => $cursor?->toRestFormat(),
				'nextCursor' => $nextCursor?->toRestFormat(),
				'hasMore' => $hasMore,
			],
		];
	}
}
