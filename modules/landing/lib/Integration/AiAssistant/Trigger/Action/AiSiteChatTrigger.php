<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Trigger\Action;

use Bitrix\AiAssistant\Core\Dto\HintButton;
use Bitrix\AiAssistant\Trigger\Action\BaseAction;
use Bitrix\AiAssistant\Trigger\Service\Dto\TriggerInitDto;
use Bitrix\Landing\Integration\AiAssistant\Contract\AiSiteChatBindingContract;
use Bitrix\Landing\Integration\AiAssistant\Service\AiSiteChatNotice;
use Bitrix\Landing\Integration\AiAssistant\Service\AiSiteChatNoticeRegistry;
use Bitrix\Landing\Integration\AiAssistant\Service\AiSiteChatPlainText;
use Bitrix\Landing\Integration\AiAssistant\Service\AiSiteChatSiteTitleProvider;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

final class AiSiteChatTrigger extends BaseAction
{
	/**
	 * The limit of the title of a chat in im.
	 */
	private const CHAT_TITLE_MAX_LENGTH = 255;
	private const CHAT_TITLE_ELLIPSIS = '…';

	private AiSiteChatSiteTitleProvider $siteTitleProvider;

	public function __construct(?AiSiteChatSiteTitleProvider $siteTitleProvider = null)
	{
		$this->siteTitleProvider = $siteTitleProvider ?? new AiSiteChatSiteTitleProvider();
	}

	public static function getCode(): string
	{
		return AiSiteChatBindingContract::TRIGGER_CODE;
	}

	public function isEntityBound(): bool
	{
		return true;
	}

	public function isAgentRoutedChat(): bool
	{
		return true;
	}

	protected function shouldRunAction(TriggerInitDto $triggerInitDto): bool
	{
		return false;
	}

	protected function runAction(int $userId, int $progressId): bool
	{
		return true;
	}

	protected function needCompleteAfterRun(): bool
	{
		return false;
	}

	public function getMessage(?string $entityId = null): string
	{
		return '';
	}

	/**
	 * Text of the server message registered for the chat; empty without a registered message, so a client
	 * call with existingMessage publishes nothing.
	 */
	public function getExistingChatMessage(string $entityId): string
	{
		$notice = AiSiteChatNoticeRegistry::find($entityId);
		if ($notice === null)
		{
			return '';
		}

		$replace = ['#TITLE#' => AiSiteChatPlainText::title($notice->siteTitle)];

		return match ($notice->type)
		{
			AiSiteChatNotice::TYPE_CREATED => (string)Loc::getMessage('LANDING_AI_SITE_CHAT_NOTICE_CREATED', $replace),
			AiSiteChatNotice::TYPE_FAILED => (string)Loc::getMessage('LANDING_AI_SITE_CHAT_NOTICE_FAILED'),
			AiSiteChatNotice::TYPE_DELETED => (string)Loc::getMessage('LANDING_AI_SITE_CHAT_NOTICE_DELETED', $replace),
			default => '',
		};
	}

	/**
	 * @return HintButton[]
	 */
	public function getExistingChatButtons(int $userId, string $entityId, int $chatId): array
	{
		$notice = AiSiteChatNoticeRegistry::find($entityId);
		if ($notice === null || $notice->buttonUrl === null || $notice->buttonUrl === '')
		{
			return [];
		}

		$titleCode = match ($notice->type)
		{
			AiSiteChatNotice::TYPE_CREATED => 'LANDING_AI_SITE_CHAT_NOTICE_BUTTON_OPEN_EDITOR',
			AiSiteChatNotice::TYPE_FAILED => 'LANDING_AI_SITE_CHAT_NOTICE_BUTTON_RETRY',
			AiSiteChatNotice::TYPE_DELETED => 'LANDING_AI_SITE_CHAT_NOTICE_BUTTON_SITES',
			default => null,
		};
		if ($titleCode === null)
		{
			return [];
		}

		return [HintButton::link((string)Loc::getMessage($titleCode), $notice->buttonUrl)];
	}

	/**
	 * Title of a new chat of the row: the name of its site; null keeps the default title of AI Assistant
	 * for a row without a site, a row of another user or an invalid key.
	 */
	public function getSuggestedTitle(?string $entityId = null): ?string
	{
		$siteTitle = $this->siteTitleProvider->findByEntityId($entityId);

		return $siteTitle === null ? null : self::buildChatTitle($siteTitle);
	}

	/**
	 * «Site "title"» within the limit of the title of a chat in im: a long title of the site is cut, the
	 * quotes stay. Also used to rename the chat the site was created from.
	 */
	public static function buildChatTitle(string $siteTitle): ?string
	{
		$chatTitle = self::formatChatTitle($siteTitle);
		$overflow = mb_strlen($chatTitle) - self::CHAT_TITLE_MAX_LENGTH;
		if ($overflow > 0)
		{
			$cutLength = max(0, mb_strlen($siteTitle) - $overflow - mb_strlen(self::CHAT_TITLE_ELLIPSIS));
			$siteTitle = rtrim(mb_substr($siteTitle, 0, $cutLength)) . self::CHAT_TITLE_ELLIPSIS;
			$chatTitle = mb_substr(self::formatChatTitle($siteTitle), 0, self::CHAT_TITLE_MAX_LENGTH);
		}

		return $chatTitle !== '' ? $chatTitle : null;
	}

	private static function formatChatTitle(string $siteTitle): string
	{
		return trim((string)Loc::getMessage('LANDING_AI_SITE_CHAT_TITLE', ['#TITLE#' => $siteTitle]));
	}
}
