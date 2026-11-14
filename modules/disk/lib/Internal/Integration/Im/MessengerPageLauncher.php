<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Integration\Im;

use Bitrix\Disk\Internal\Enum\DocumentHeaderElement;
use Bitrix\Im\Settings;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use CIMMessenger;

/**
 * Starts the messenger on a page of its own which shows a document with the access popup in its header,
 * so that a chat opened from the mini profile there goes to the messenger slider, as on any portal page.
 */
class MessengerPageLauncher
{
	/**
	 * @param array|null $composition the header composition the page renders with
	 */
	public function shouldLaunch(?array $composition): bool
	{
		$elements = $composition['elements'] ?? null;
		if (!is_array($elements))
		{
			return false;
		}

		// The save status marks the editor, where the chat goes to a new tab rather than to the slider.
		if (
			!in_array(DocumentHeaderElement::Access->value, $elements, true)
			|| in_array(DocumentHeaderElement::SaveStatus->value, $elements, true)
		)
		{
			return false;
		}

		return $this->isAuthorized() && $this->isImAvailable() && !$this->isLegacyChat();
	}

	/**
	 * @return string[]
	 */
	public function getExtensions(): array
	{
		return [
			'im.v2.lib.opener',
			'im.v2.application.quick-access',
		];
	}

	/**
	 * Script without the tag: the template wraps it.
	 * Inside a portal slider the page borrows the messenger of the top window, so it starts none of its own.
	 */
	public function getScript(): string
	{
		return "if (window.top === window) {\n" . $this->getMessengerTemplateJs() . "\n}";
	}

	protected function isImAvailable(): bool
	{
		return Loader::includeModule('im');
	}

	protected function isLegacyChat(): bool
	{
		return Settings::isLegacyChatActivated();
	}

	protected function isAuthorized(): bool
	{
		return (int)CurrentUser::get()->getId() > 0;
	}

	protected function getMessengerTemplateJs(): string
	{
		return CIMMessenger::GetV2TemplateJS(['DESKTOP' => false]);
	}
}
