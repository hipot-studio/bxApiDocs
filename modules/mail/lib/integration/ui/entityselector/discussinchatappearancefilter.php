<?php

namespace Bitrix\Mail\Integration\UI\EntitySelector;

use Bitrix\Im\V2\Chat;
use Bitrix\UI\EntitySelector\BaseFilter;
use Bitrix\UI\EntitySelector\Dialog;
use Bitrix\UI\EntitySelector\Item;

class DiscussInChatAppearanceFilter extends BaseFilter
{
	private const CONTEXT = 'MAIL_DISCUSS_IN_CHAT';
	private const ICON_BASE = '/bitrix/js/mail/client/action/discuss-in-chat/src/images/selectors';

	private const ENTITY_TYPE_USER = 'im-user';

	public function __construct()
	{
		parent::__construct();
	}

	public function isAvailable(): bool
	{
		return true;
	}

	public function apply(array $items, Dialog $dialog): void
	{
		if ($dialog->getContext() !== self::CONTEXT)
		{
			return;
		}

		foreach ($items as $item)
		{
			if (!$item instanceof Item)
			{
				continue;
			}

			if (!empty($item->getAvatar()))
			{
				continue;
			}

			$this->applyDefaultAvatar($item);
		}
	}

	private function applyDefaultAvatar(Item $item): void
	{
		if ($item->getEntityType() === self::ENTITY_TYPE_USER)
		{
			$item->setAvatarOptions([
				'bgImage' => $this->getIcon('bind-user.svg'),
			]);

			return;
		}

		$customData = $item->getCustomData()->getValues();
		$chatData = $customData['chat'] ?? null;
		if ($chatData !== null)
		{
			$this->applyChatAvatar($item, $chatData);
		}
	}

	private function applyChatAvatar(Item $item, array $chatData): void
	{
		$messageType = $chatData['messageType'] ?? '';

		// TODO: replace 'E' with Chat::IM_TYPE_OPEN_COLLAB when im module dependency is available
		// @see \Bitrix\Im\V2\Chat::IM_TYPE_OPEN_COLLAB
		if ($messageType === Chat::IM_TYPE_COLLAB || $messageType === 'E')
		{
			$item->setAvatarOptions([
				'bgImage' => $this->getIcon('bind-collab.svg'),
			]);

			return;
		}

		if ($messageType === Chat::IM_TYPE_CHANNEL || $messageType === Chat::IM_TYPE_OPEN_CHANNEL)
		{
			$item->setAvatarOptions([
				'bgImage' => $this->getIcon('bind-channel.svg'),
				'borderRadius' => '6px',
			]);

			return;
		}

		$item->setAvatarOptions([
			'bgImage' => $this->getIcon('bind-chat.svg'),
		]);
	}

	private function getIcon(string $name): string
	{
		return $this->getIconStyle($this->getIconPath($name));
	}

	private function getIconPath(string $name): string
	{
		return sprintf('%s/%s', self::ICON_BASE, $name);
	}

	private function getIconStyle(string $path): string
	{
		return sprintf("url('%s')", $path);
	}
}
