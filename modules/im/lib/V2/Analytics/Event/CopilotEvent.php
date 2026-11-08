<?php

namespace Bitrix\Im\V2\Analytics\Event;

use Bitrix\Im\V2\Analytics\CopilotModesSnapshot;
use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Integration\AI\RoleManager;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\Params;
use Bitrix\Imbot\Bot\CopilotChatBot;
use Bitrix\Main\Loader;

class CopilotEvent extends Event
{
	protected function getTool(): string
	{
		return 'ai';
	}

	protected function getCategory(string $eventName): string
	{
		return 'chat_operations';
	}

	protected function setDefaultParams(): self
	{
		$this->setSection('copilot_tab');

		if (!Features::isBitrixGptV2Available())
		{
			$this
				->setCopilotP2()
				->setCopilotP3()
				->setCopilotP4()
				->setCopilotP5()
			;
		}

		return $this;
	}

	public function setCopilotP1(?string $promptCode): self
	{
		$this->p1 = isset($promptCode) ? ('1st-type_' . self::convertUnderscore($promptCode)) : 'none';

		return $this;
	}

	protected function setCopilotP2(): self
	{
		$engineName = null;
		if (Loader::includeModule('imbot'))
		{
			$engineName = CopilotChatBot::getEngineByChat($this->chat)?->getIEngine()?->getName();
		}

		$engineName ??= 'none';
		$this->p2 = 'provider_' . $engineName;

		return $this;
	}

	protected function setCopilotP3(): self
	{
		$this->p3 = $this->chat->getUserCount() > 2 ? 'chatType_multiuser' : 'chatType_private';

		return $this;
	}

	protected function setCopilotP4(): self
	{
		$role = (new RoleManager())->getMainRole($this->chat->getChatId()) ?? '';
		$this->p4 = 'role_' . self::convertUnderscore($role);

		return $this;
	}

	protected function setCopilotP5(): self
	{
		$this->p5 = 'chatId_' . $this->chat->getChatId();

		return $this;
	}

	/**
	 * Ensures the legacy p2–p5 fields are set even when the v2 flag made
	 * setDefaultParams skip them. Used by generate / received_result, which keep
	 * the legacy format in both flag states (under v2 these labels are owned by
	 * the aiassistant pipeline).
	 */
	public function ensureLegacyFormat(): self
	{
		if (Features::isBitrixGptV2Available())
		{
			$this
				->setCopilotP2()
				->setCopilotP3()
				->setCopilotP4()
				->setCopilotP5()
			;
		}

		return $this;
	}

	/**
	 * Switches this event to the new BitrixGPT v2 format (event p-fields format).
	 * Sets p3, p4 (full modes snapshot from message), p5 from chat and section from
	 * the page the request was sent from.
	 * Clears legacy p2 (provider field is not used in new format).
	 */
	public function applyV2Format(Message $message): self
	{
		$this->applyV2FormatCommon();
		$this->p4 = CopilotModesSnapshot::buildFromMessage($message);

		$section = $this->resolvePageContextSection($message);
		if ($section !== null)
		{
			$this->section = $section;
		}

		return $this;
	}

	/**
	 * Switches this event to the new BitrixGPT v2 format with reduced p4 (chat-level, no message).
	 * Used for add_user / delete_user events.
	 */
	public function applyV2FormatReduced(): self
	{
		$this->applyV2FormatCommon();
		$this->p4 = CopilotModesSnapshot::buildReduced($this->chat->getChatId());

		return $this;
	}

	/**
	 * Sets the common fields shared by applyV2Format and applyV2FormatReduced:
	 * clears legacy p2, sets p3 (chat type) and p5 (chatId).
	 */
	private function applyV2FormatCommon(): void
	{
		$this->p2 = null;
		$this->p3 = $this->chat->getUserCount() > 2 ? 'chatType_multiuser' : 'chatType_private';
		$this->p5 = 'chatId_' . $this->chat->getChatId();
	}

	/**
	 * Resolves the section from the page the copilot request was sent from: the client
	 * stores its page context in a message param, from which only the module is taken.
	 * The value is client-provided, so it is bounded to a safe charset / length the same
	 * way the aiassistant REST entry point does. Returns null when there is nothing
	 * trustworthy to use, leaving the default section in place.
	 */
	private function resolvePageContextSection(Message $message): ?string
	{
		$pageContext = $message->getParams()->get(Params::COPILOT_PAGE_CONTEXT)->getValue();
		if (!is_array($pageContext))
		{
			return null;
		}

		$module = $pageContext['system']['module'] ?? null;
		if (!is_string($module))
		{
			return null;
		}

		$module = trim($module);

		return preg_match('/^[A-Za-z0-9_-]{1,64}\z/', $module) === 1 ? $module : null;
	}
}
