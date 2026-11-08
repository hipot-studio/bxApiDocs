<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Analytics;

use Bitrix\AiAssistant\RemoteMcp\Service\AuthorizationService;
use Bitrix\AiAssistant\RemoteMcp\Service\McpServerService;
use Bitrix\Im\V2\Analytics\Event\Event;
use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Integration\AI\RoleManager;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\Params;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Loader;
use Bitrix\Main\Web\Json;

/**
 * Builds the p4 JSON snapshot of copilot modes for analytics events.
 *
 * Only keys whose features are available are included.
 * All underscore-bearing dynamic values are passed through Event::convertUnderscore
 * so the resulting JSON carries no underscores: a p-field value with two or more of them
 * makes the whole event invalid.
 */
class CopilotModesSnapshot
{
	private const CHAT_TYPE_GENERAL = 'general';
	private const CHAT_TYPE_RIGHT = 'right';
	private const MESSENGER_MODULE_ID = 'im';

	/**
	 * Builds a full snapshot from a concrete Message for the v2-format message-level
	 * ai-labels (send_message / attach_file / add_reaction / message_regenerate).
	 *
	 * @param Message $message The message carrying the copilot mode params (the user's request).
	 * @return string JSON-encoded snapshot string.
	 */
	public static function buildFromMessage(Message $message): string
	{
		$params = $message->getParams();
		$chatId = $message->getChatId();
		$authorId = $message->getAuthorId();
		$p4 = [];

		// mcp: available only if MCP selector feature is on
		if (Features::isAiAssistantMcpSelectorAvailable())
		{
			$mcpAuthId = (int)$params->get(Params::COPILOT_MCP_AUTH_ID)->getValue();
			$mcpOn = $mcpAuthId > 0;
			$p4['mcp'] = $mcpOn ? 'on' : 'off';

			// mcpServer: only when mcp=on and server name is resolvable and non-empty
			if ($mcpOn)
			{
				$serverName = self::resolveMcpServerName($mcpAuthId, $authorId);
				if ($serverName !== null && $serverName !== '')
				{
					// A p-field value with two or more underscores invalidates the whole event
					// (\Bitrix\Main\Analytics\AnalyticsEvent::validatePField), silently dropping every
					// message-level label of the message — not just this dimension.
					$p4['mcpServer'] = Event::convertUnderscore($serverName);
				}
			}
		}

		// reasoning: always available
		$reasoning = (bool)$params->get(Params::COPILOT_REASONING)->getValue();
		$p4['reasoning'] = $reasoning ? 'on' : 'off';

		// webSearch: available only if force-search feature is on
		if (Features::isCopilotForceSearchAvailable())
		{
			$webSearch = (bool)$params->get(Params::COPILOT_FORCE_SEARCH)->getValue();
			$p4['webSearch'] = $webSearch ? 'on' : 'off';
		}

		// agentMode: available only if agent-mode feature is on
		if (Features::isAiAssistantAgentModeAvailable())
		{
			$agentMode = (bool)$params->get(Params::COPILOT_AGENT_MODE)->getValue();
			$p4['agentMode'] = $agentMode ? 'on' : 'off';
		}

		// role: copilot context only; use param first, then chat-level fallback
		$role = (string)$params->get(Params::COPILOT_ROLE)->getValue();
		if ($role === '')
		{
			$role = (string)(new RoleManager())->getMainRole($chatId);
		}
		if ($role !== '')
		{
			$p4['role'] = Event::convertUnderscore($role);
		}

		// chatType: always present, resolved from the page the request was sent from
		$p4['chatType'] = self::resolveChatType($message);

		return Json::encode($p4);
	}

	/**
	 * Builds a reduced snapshot for chat-level events (add_user / delete_user / create_new_chat).
	 * Only role + chatType are included (no message params available).
	 *
	 * @param int|null $chatId The chat ID for role resolution.
	 * @return string JSON-encoded reduced snapshot string.
	 */
	public static function buildReduced(?int $chatId): string
	{
		$p4 = [];

		// role from chat-level RoleManager only
		$role = (string)(new RoleManager())->getMainRole($chatId);
		if ($role !== '')
		{
			$p4['role'] = Event::convertUnderscore($role);
		}

		// chatType: always present; chat-level events have no message to resolve the page from
		$p4['chatType'] = self::CHAT_TYPE_GENERAL;

		return Json::encode($p4);
	}

	/**
	 * The messenger page counts as 'general' only for the very chat opened in its layout, since
	 * the widget is available over the messenger as well; without a usable page context the
	 * value falls back to 'general'.
	 */
	private static function resolveChatType(Message $message): string
	{
		$pageContext = $message->getParams()->get(Params::COPILOT_PAGE_CONTEXT)->getValue();
		if (!is_array($pageContext))
		{
			return self::CHAT_TYPE_GENERAL;
		}

		$module = $pageContext['system']['module'] ?? null;
		if (!is_string($module))
		{
			return self::CHAT_TYPE_GENERAL;
		}

		$module = trim($module);
		if ($module === '')
		{
			return self::CHAT_TYPE_GENERAL;
		}

		if ($module !== self::MESSENGER_MODULE_ID)
		{
			return self::CHAT_TYPE_RIGHT;
		}

		$rawChatId = $pageContext['custom']['im']['currentChat']['chatId'] ?? null;
		$currentChatId = filter_var($rawChatId, FILTER_VALIDATE_INT);
		if ($currentChatId === false)
		{
			return self::CHAT_TYPE_RIGHT;
		}

		return $currentChatId === $message->getChatId()
			? self::CHAT_TYPE_GENERAL
			: self::CHAT_TYPE_RIGHT
		;
	}

	private const MCP_SERVER_NAME_CACHE_LIMIT = 128;
	private const MCP_SERVER_NAME_CACHE_TTL = 60;

	/**
	 * Process-static memoization cache for resolveMcpServerName.
	 * Stores both positive (string name) and negative (null = not found / error) results
	 * together with an expiry timestamp.
	 * Key: "authId:userId" (string) — resolution is user-scoped, so the same authId may
	 * resolve differently for different users within one process.
	 * Value: [resolved name or null, expiry unix timestamp].
	 * The result is ACL-derived, so entries expire after MCP_SERVER_NAME_CACHE_TTL seconds:
	 * within one web hit the memoization never expires, while a long-living process (CLI,
	 * workers) picks up a revoked or granted sharing after at most the TTL.
	 * Size is capped: on overflow expired entries are dropped first, then the oldest ones —
	 * the fresh part of the cache survives instead of being reset wholesale.
	 *
	 * @var array<string, array{0: string|null, 1: int}>
	 */
	private static array $mcpServerNameCache = [];

	private static bool $narrowResolverWarningLogged = false;

	/**
	 * Resolves the MCP server name by auth-id using the aiassistant public services.
	 *
	 * The lookup is user-scoped: the auth-id comes from a user-controlled hidden message
	 * param, so the name is resolved only within the message author's visibility (owner
	 * or shared to all users / the user / the user's department). This prevents a user
	 * from causing the name of an MCP authorization they cannot access to be recorded
	 * in the analytics event. No server feature filter is applied: for a message already
	 * sent with this auth the name should resolve regardless.
	 *
	 * The preferred path is the narrow AuthorizationService::resolveServerNameForUser()
	 * (no AUTH_DATA decryption, no sharing collection hydration, no full server DTO;
	 * one indexed query for the owner case). The getAuthorizationWithShared() +
	 * McpServerService::getServer() pair is kept as a fallback for an aiassistant
	 * version that does not ship the narrow resolver yet — same semantics, heavier reads.
	 *
	 * Results (including null / not-found) are memoized per (authId, userId) with a short
	 * TTL to avoid repeated DB queries when multiple v2 events, such as send_message and
	 * attach_file, build p4 for the same message.
	 * Returns null on any error or unavailability (graceful degradation).
	 */
	private static function resolveMcpServerName(int $mcpAuthId, int $userId): ?string
	{
		$cacheKey = $mcpAuthId . ':' . $userId;
		$cached = self::$mcpServerNameCache[$cacheKey] ?? null;
		if ($cached !== null && $cached[1] > time())
		{
			return $cached[0];
		}

		if (!Loader::includeModule('aiassistant'))
		{
			return self::rememberMcpServerName($cacheKey, null);
		}

		if (!class_exists(AuthorizationService::class) || !class_exists(McpServerService::class))
		{
			return self::rememberMcpServerName($cacheKey, null);
		}

		try
		{
			$serviceLocator = ServiceLocator::getInstance();
			/** @var AuthorizationService $authService */
			$authService = $serviceLocator->get(AuthorizationService::class);

			if (method_exists($authService, 'resolveServerNameForUser'))
			{
				$serverName = $authService->resolveServerNameForUser($mcpAuthId, $userId);

				return self::rememberMcpServerName($cacheKey, $serverName !== '' ? $serverName : null);
			}

			self::warnAboutMissingNarrowResolver();

			$authorization = $authService->getAuthorizationWithShared($mcpAuthId, $userId);
			if ($authorization === null)
			{
				return self::rememberMcpServerName($cacheKey, null);
			}

			/** @var McpServerService $serverService */
			$serverService = $serviceLocator->get(McpServerService::class);

			return self::rememberMcpServerName($cacheKey, $serverService->getServer($authorization->mcpServerId)?->name);
		}
		catch (\Throwable)
		{
			return self::rememberMcpServerName($cacheKey, null);
		}
	}

	/**
	 * The narrow resolver is the aiassistant surface this snapshot depends on; its absence means
	 * a module version that predates it, and the fallback silently costs heavier reads for every
	 * name. Warned once per process so the degradation shows up in the log, not only in queries.
	 */
	private static function warnAboutMissingNarrowResolver(): void
	{
		if (self::$narrowResolverWarningLogged)
		{
			return;
		}

		self::$narrowResolverWarningLogged = true;

		(new LoggerFactory())->createById('im.CopilotModesSnapshot')?->warning(
			'aiassistant has no AuthorizationService::resolveServerNameForUser, MCP server name falls back to the heavy lookup',
		);
	}

	private static function rememberMcpServerName(string $cacheKey, ?string $serverName): ?string
	{
		if (count(self::$mcpServerNameCache) >= self::MCP_SERVER_NAME_CACHE_LIMIT)
		{
			self::evictMcpServerNameCache();
		}

		self::$mcpServerNameCache[$cacheKey] = [$serverName, time() + self::MCP_SERVER_NAME_CACHE_TTL];

		return $serverName;
	}

	private static function evictMcpServerNameCache(): void
	{
		$now = time();
		self::$mcpServerNameCache = array_filter(
			self::$mcpServerNameCache,
			static fn (array $entry): bool => $entry[1] > $now,
		);

		while (count(self::$mcpServerNameCache) >= self::MCP_SERVER_NAME_CACHE_LIMIT)
		{
			unset(self::$mcpServerNameCache[array_key_first(self::$mcpServerNameCache)]);
		}
	}
}
