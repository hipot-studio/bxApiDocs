<?php

namespace Bitrix\Intranet\Internal\Integration\Im\AnnualSummary;

use Bitrix\Im\Model\ChatTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Intranet\Internal\Entity\AnnualSummary\ChannelFeature;
use Bitrix\Intranet\Internal\Repository\AnnualSummaryRepository;
use Bitrix\Intranet\Internal\Service\AnnualSummary\AbstractFeatureProvider;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;

class ChatProvider extends AbstractFeatureProvider
{
	public function calcValue(int $userId, DateTime $from, DateTime $to): int
	{
		return (int)ChatTable::query()
			->whereIn('TYPE', [Chat::IM_TYPE_CHANNEL, Chat::IM_TYPE_OPEN_CHANNEL])
			->where('DATE_CREATE', '>=', $from)
			->where('DATE_CREATE', '<', $to)
			->where('AUTHOR_ID', $userId)
			->queryCountTotal()
		;
	}

	public function createFeature(int $value): ChannelFeature
	{
		return new ChannelFeature($value);
	}

	public function precalcValue(int $userId): ?int
	{
		return (new AnnualSummaryRepository($userId))->getSerializedOption('annual_summary_25_chat_count');
	}

	public function isAvailable(): bool
	{
		return Loader::includeModule('im');
	}
}
