<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Copilot;

use Bitrix\AiAssistant\RemoteMcp\Contract\McpSelectionContract;
use Bitrix\AiAssistant\RemoteMcp\Service\McpSelectionService;
use Bitrix\Im\V2\Application\Features;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;

/**
 * Injects the per-user `mcpAuth` selection into an already-assembled `copilot.chats[]` REST block.
 *
 * The selection is a PER-USER choice (it is stored by userId + chatId), so it is attached ONLY on
 * the synchronous per-user chat load path, where $userId is the authenticated request user. It is
 * deliberately kept OUT of the shared popup-DTO layer (CopilotPopupItem::getChatsForRest), because
 * that layer is reused by broadcast payloads (recent list, chats sync, recent-preview pull): there
 * one payload is built for many recipients, and a selection resolved from the ambient context user
 * would leak to all of them. That layer also serves chat lists, where a per-chat lookup would be an
 * N+1; the chat load resolves a single chat.
 */
class McpSelectionEnricher
{
	/**
	 * Adds `mcpAuth` to every element of $rest['copilot']['chats'] on behalf of $userId:
	 * {id, name, icon, available} for a stored selection, null for none. The key stays absent while
	 * the feature is off, which is how the client tells "no selection" from "nothing is known".
	 */
	public function enrich(array $rest, int $userId): array
	{
		$chats = $rest['copilot']['chats'] ?? null;
		if (empty($chats) || !is_array($chats))
		{
			return $rest;
		}

		// The feature check does not imply the aiassistant module: an absent module reads as
		// "not restricted", so the module is loaded later, in isSelectionReadable().
		if ($userId <= 0 || !Features::isAiAssistantMcpSelectionRestoreAvailable())
		{
			return $rest;
		}

		foreach ($rest['copilot']['chats'] as &$chat)
		{
			$chat['mcpAuth'] = $this->getSelection($userId, $this->resolveGroupChatId($chat['dialogId'] ?? null));
		}
		unset($chat);

		return $rest;
	}

	/**
	 * Only the group-chat form is parsed, and it is parsed by pattern, as in DialogChatIdResolver:
	 * Dialog::getChatId() falls through to CIMMessage::GetChatId() for a numeric dialogId, which
	 * creates a private chat as a side effect, and a read path must not create anything. Every
	 * other form reads as "no chat id" and is reported the same way as a chat without a dialogId.
	 */
	private function resolveGroupChatId(mixed $dialogId): int
	{
		if (!is_string($dialogId) || !preg_match('/^chat\d+$/i', $dialogId))
		{
			return 0;
		}

		return (int)mb_substr($dialogId, 4);
	}

	/**
	 * An unreadable selection is reported as an empty one: the client waits for this field before it
	 * sends, so leaving it out would cost the user the full send timeout on every message.
	 *
	 * @return array{id: int, name: string, icon: ?string, available: bool}|null
	 */
	private function getSelection(int $userId, int $chatId): ?array
	{
		if ($chatId <= 0 || !$this->isSelectionReadable())
		{
			return null;
		}

		$result = ServiceLocator::getInstance()
			->get(McpSelectionService::class)
			->getWithoutRefresh($userId, $chatId)
		;

		if (!$result->isSuccess())
		{
			return null;
		}

		$data = $result->getData();
		// The client works with a single connection, and the reading entry point describes only the
		// first stored one; the status it reports is about that same connection.
		$auth = $data['auths'][0] ?? null;
		if ($auth === null)
		{
			return null;
		}

		return [
			'id' => $auth->id,
			'name' => $auth->name,
			'icon' => $auth->icon,
			'available' => ($data['status'] ?? '') === McpSelectionContract::STATUS_SELECTED,
		];
	}

	/**
	 * The read-only entry point ships with the aiassistant module on its own schedule: until it
	 * lands, report "no selection" instead of fataling when the feature flag is enabled early.
	 */
	protected function isSelectionReadable(): bool
	{
		return Loader::includeModule('aiassistant')
			&& method_exists(McpSelectionService::class, 'getWithoutRefresh');
	}
}
