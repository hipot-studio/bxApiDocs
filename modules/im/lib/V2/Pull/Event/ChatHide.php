<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Pull\Event;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Pull\EventType;
use Bitrix\Im\V2\Recent\Config\HiddenSectionsResolver;

class ChatHide extends BaseChatEvent
{
	use DialogIdFiller;

	protected int $expiry = 3600;
	protected array $recipients;

	/**
	 * @param int[] $recipients
	 */
	public function __construct(Chat $chat, array $recipients)
	{
		$this->recipients = array_map('intval', $recipients);
		parent::__construct($chat);
	}

	protected function getBasePullParamsInternal(): array
	{
		return [
			'dialogId' => $this->getBaseDialogId(),
			'chatId' => $this->chat->getChatId(),
			'lines' => $this->chat->getType() === Chat::IM_TYPE_OPEN_LINE,
			'recentConfigToHide' => $this->getRecentConfig(),
		];
	}

	protected function getRecipients(): array
	{
		return $this->recipients;
	}

	protected function getType(): EventType
	{
		return EventType::ChatHide;
	}

	protected function getRecentConfig(): array
	{
		$recentConfig = $this->chat->getRecentConfig()->toPullFormat();
		$recentConfig['sections'] = HiddenSectionsResolver::resolve($this->chat);

		return $recentConfig;
	}

	public function shouldSendToOnlySpecificRecipients(): bool
	{
		return true;
	}
}