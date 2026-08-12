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
	public const BINDING_REQUIRED_ERROR = 'AI site generation can only be started from /sites/ai/ with a valid bindingId.';
	public const BINDING_UNAVAILABLE_ERROR = 'AI site generation can only be started from an active /sites/ai/ chat. Open /sites/ai/ to create a new site.';
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
