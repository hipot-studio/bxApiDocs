<?php

namespace Bitrix\Im\V2\TariffLimit;

use Bitrix\Bitrix24\Feature;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Integration\AiAssistant\AiAssistantService;
use Bitrix\Im\V2\TariffLimit\Event\CollectRestrictionsEvent;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;

class Limit
{
	private const DAYS_LIMIT_HISTORY_VARIABLE = 'im_history_days_limit';
	private const HISTORY_LIMIT_ID = 'im_history_limit';
	private const CHAT_TYPE_BLACKLIST = [
		Chat\ChannelChat::class,
		Chat\CollabChat::class,
		Chat\ExternalChat::class
	];

	private static ?self $instance;

	private AiAssistantService $aiAssistantService;

	private function __construct()
	{
		$this->aiAssistantService = ServiceLocator::getInstance()->get(AiAssistantService::class);
	}

	public static function getInstance(): static
	{
		if (!isset(static::$instance))
		{
			static::$instance = new static();
		}

		return static::$instance;
	}

	public static function onLicenseHasChanged(): void
	{
		if (Loader::includeModule('pull'))
		{
			\CPullStack::AddShared([
				'module_id' => 'im',
				'command' => 'changeTariff',
				'params' => [
					'tariffRestrictions' => self::getInstance()->getRestrictions(),
				],
				'extra' => \Bitrix\Im\Common::getPullExtra()
			]);
		}
	}

	public function getRestrictions(): array
	{
		return $this
			->enrichByExternalRestrictions(restrictions: $this->getInternalRestrictions())
			->jsonSerialize()
		;
	}

	private function getInternalRestrictions(): TariffRestrictionCollection
	{
		return (new TariffRestrictionCollection())
			->withAdded(
				new FullChatHistoryRestriction(
					!$this->hasRestrictions(),
					$this->getLimitDays(),
				)
			)
		;
	}

	private function enrichByExternalRestrictions(TariffRestrictionCollection $restrictions): TariffRestrictionCollection
	{
		$event = new CollectRestrictionsEvent($restrictions);
		$event->send();

		return $event->getNewRestrictions();
	}

	protected function getLimitDays(): ?int
	{
		return null;

		if (!Loader::includeModule('bitrix24'))
		{
			return null;
		}

		if (Feature::isFeatureEnabled(self::HISTORY_LIMIT_ID))
		{
			return null;
		}

		$limitDays = Feature::getVariable(self::DAYS_LIMIT_HISTORY_VARIABLE);

		return $limitDays === null ? null : (int)$limitDays;
	}

	public function hasAccessByDate(DateFilterable $item, DateTime $date): bool
	{
		if (!$this->shouldFilterByDate($item))
		{
			return true;
		}

		return $this->getLimitDate()->getTimestamp() <= $date->getTimestamp();
	}

	public function hasRestrictions(): bool
	{
		return $this->getLimitDays() !== null;
	}

	public function shouldFilterByDate(DateFilterable $item): bool
	{
		if (!$this->hasRestrictions())
		{
			return false;
		}

		$chatId = $item->getRelatedChatId();

		if (!$chatId)
		{
			return false;
		}

		return $this->isChatTypeAllowed(Chat::getInstance($chatId));
	}

	public function getLimitDate(): DateTime
	{
		Loader::requireModule('bitrix24');
		$days = $this->getLimitDays();

		return (new DateTime())->add("-{$days} days");
	}

	/**
	 * A discussion keeps no history rule of its own and is weighed by the rule of the chat it belongs
	 * to. Otherwise the limit would leave a door around itself: what the boundary hides in a chat would
	 * stay reachable through the discussions hanging off its posts. The comments of a channel reach the
	 * channel this way and keep the exception it has.
	 *
	 * One step up and no further: the tree admits no discussion under a discussion.
	 */
	private function isChatTypeAllowed(Chat $chat): bool
	{
		if ($chat instanceof Chat\CommentChat)
		{
			// a parent the row does not name comes back as the null chat of the module, which is neither
			// blacklisted nor an assistant, so such a discussion stays under the limit
			$chat = $chat->getParentChat();
		}

		foreach (self::CHAT_TYPE_BLACKLIST as $type)
		{
			if ($chat instanceof $type)
			{
				return false;
			}
		}

		if ($this->aiAssistantService->isAiAssistantChat($chat))
		{
			return false;
		}

		return true;
	}
}
