<?php

namespace Bitrix\Im\V2\Pull\Event;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Pull\Dto\Diff;
use Bitrix\Im\V2\Pull\EventType;
use Bitrix\Im\V2\Pull\RecentPreviewPullTrait;
use Bitrix\Im\V2\Reading\Counter\CountersProvider;
use Bitrix\Im\V2\Reading\Counter\Entity\UsersCounterMap;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Type\DateTime;

class RecentUpdate extends BaseChatEvent
{
	use RecentPreviewPullTrait;

	protected array $recipients;
	protected UsersCounterMap $counters;
	protected DateTime $lastActivity;

	/**
	 * @param int[] $recipients
	 */
	public function __construct(Chat $chat, array $recipients, ?DateTime $lastActivity = null)
	{
		$this->recipients = array_map('intval', $recipients);
		$this->counters = ServiceLocator::getInstance()->get(CountersProvider::class)->getForUsers($chat->getChatId(), $recipients);
		$this->lastActivity = $lastActivity ?? new DateTime();

		parent::__construct($chat);
	}

	protected function getRecipients(): array
	{
		return $this->recipients;
	}

	protected function getBasePullParamsInternal(): array
	{
		return $this->getBaseRecentPreviewParams($this->chat, lastActivityDate: $this->lastActivity);
	}

	protected function getDiffByUser(int $userId): Diff
	{
		return new Diff(
			$userId,
			array_merge(
				$this->getRecentPreviewUserDiffParams($this->chat, $userId),
				[
					'counter' => $this->counters->getByUserId($userId),
				],
			),
		);
	}

	protected function getType(): EventType
	{
		return EventType::RecentUpdate;
	}

	public function shouldSendToOnlySpecificRecipients(): bool
	{
		return true;
	}
}
