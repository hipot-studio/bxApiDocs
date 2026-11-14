<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Intranet\AnnualSummary;

use Bitrix\Im\Model\ChatTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Main\ORM\Fields\ExpressionField;

/**
 * Channels created by the user within the period.
 */
class ChannelProvider extends AbstractSummaryProvider
{
	public const PROVIDER_ID = 'im.created_channels';

	/**
	 * Only regulator of the metric load. The size follows a step budget of database time on the
	 * benchmark profile of a few dozen channels per author; another distribution has another price.
	 */
	public const USER_ID_LIMIT = 500;

	public function getId(): string
	{
		return self::PROVIDER_ID;
	}

	public function getUserIdLimit(): int
	{
		return self::USER_ID_LIMIT;
	}

	protected function calculate(int $userId): int
	{
		// Fits IX_IM_CHAT_1 (AUTHOR_ID, TYPE) entirely; the period is filtered by DATE_CREATE.
		$row = ChatTable::query()
			->setSelect(['COUNT'])
			->registerRuntimeField('COUNT', new ExpressionField('COUNT', 'COUNT(*)'))
			->where('AUTHOR_ID', $userId)
			->whereIn('TYPE', [Chat::IM_TYPE_CHANNEL, Chat::IM_TYPE_OPEN_CHANNEL])
			->where('DATE_CREATE', '>=', $this->from)
			->where('DATE_CREATE', '<', $this->to)
			->fetch()
		;

		return (int)($row['COUNT'] ?? 0);
	}
}
