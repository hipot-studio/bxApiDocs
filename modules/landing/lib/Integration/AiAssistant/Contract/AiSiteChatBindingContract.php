<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Contract;

final class AiSiteChatBindingContract
{
	public const TRIGGER_CODE = 'landing.ai-site.chat';
	public const TRIGGER_KIND_UI = 'ui';
	public const TRIGGER_ENTITY_ID_FIELD = 'entityId';
	public const TOOL_BINDING_ID_FIELD = 'bindingId';
	public const CONTEXT_MODULE = 'landing';
	public const CONTEXT_SURFACE_SITES_AI = 'sites-ai';
	public const CONTEXT_SURFACE_EDITOR = 'editor';
	public const CONTEXT_SURFACE_REGULAR_SITE = 'regular-site';
	public const CONTEXT_SURFACE_PUBLIC = 'public';
	public const CONTEXT_SURFACE_PREVIEW = 'preview';
	public const TOOLTIP_TRIGGERS_ENABLED = false;
	public const MANUAL_CHAT_CREATES_BINDING = false;
	public const RECOVERY_UX_ENABLED = false;
	/**
	 * The creation refusals carry no link: the agent gets the link that carries the request of the user from
	 * the transfer tool named in the text.
	 */
	private const HANDOFF_TO_SITES_AI_HINT = 'To create a site, call get_ai_site_handoff_link with target "sites_ai"'
		. ' and the request of the user, and give the user the link it returns.';
	public const BINDING_REQUIRED_ERROR = 'AI site generation can only be started from /sites/ai/'
		. ' with a valid bindingId. ' . self::HANDOFF_TO_SITES_AI_HINT;
	public const BINDING_UNAVAILABLE_ERROR_CODE = 'BINDING_UNAVAILABLE';
	public const BINDING_UNAVAILABLE_MESSAGE = 'AI site generation can only be started from an active /sites/ai/'
		. ' chat. ' . self::HANDOFF_TO_SITES_AI_HINT;
	public const BINDING_UNAVAILABLE_ERROR = self::BINDING_UNAVAILABLE_ERROR_CODE . ': ' . self::BINDING_UNAVAILABLE_MESSAGE;
	/**
	 * One chat - one site: a chat pinned to its site, or a chat started before the markup creation, never
	 * creates another site.
	 */
	public const NEW_CHAT_REQUIRED_ERROR_CODE = 'NEW_CHAT_REQUIRED';
	public const NEW_CHAT_REQUIRED_MESSAGE = 'This chat cannot create a new site: another site is created in a new chat'
		. ' in /sites/ai/. ' . self::HANDOFF_TO_SITES_AI_HINT;
	public const NEW_CHAT_REQUIRED_ERROR = self::NEW_CHAT_REQUIRED_ERROR_CODE . ': ' . self::NEW_CHAT_REQUIRED_MESSAGE;
	public const SITE_CHAT_SURFACES = [
		self::CONTEXT_SURFACE_SITES_AI,
		self::CONTEXT_SURFACE_EDITOR,
	];
	public const EXCLUDED_SITE_CHAT_SURFACES = [
		self::CONTEXT_SURFACE_REGULAR_SITE,
		self::CONTEXT_SURFACE_PUBLIC,
		self::CONTEXT_SURFACE_PREVIEW,
	];

	private function __construct()
	{
	}
}
