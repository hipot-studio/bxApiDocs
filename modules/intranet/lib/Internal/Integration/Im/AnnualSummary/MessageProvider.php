<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Im\AnnualSummary;

use Bitrix\Im\Model\ChatTable;
use Bitrix\Im\Model\MessageTable;
use Bitrix\Intranet\Internal\Entity\AnnualSummary\MessageFeature;
use Bitrix\Intranet\Internal\Repository\AnnualSummaryRepository;
use Bitrix\Intranet\Internal\Service\AnnualSummary\AbstractFeatureProvider;
use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;

class MessageProvider extends AbstractFeatureProvider
{
	public function calcValue(int $userId, DateTime $from, DateTime $to): int
	{
		return (int)MessageTable::query()
			->where('AUTHOR_ID', $userId)
			->where('DATE_CREATE', '>=', $from)
			->where('DATE_CREATE', '<', $to)
			->queryCountTotal()
		;
	}

	public function createFeature(int $value): MessageFeature
	{
		return new MessageFeature($value);
	}

	public function precalcValue(int $userId): ?int
	{
		$data = (new AnnualSummaryRepository($userId))->getSerializedOption('annual_summary_25_im');
		if (!is_array($data))
		{
			return null;
		}

		return $data['message'] ?? null;
	}

	public function isAvailable(): bool
	{
		return Loader::includeModule('im');
	}

	public function needPartCalc(): bool
	{
		return true;
	}

	public function partCalc(int $userId, DateTime $from, DateTime $to, int $lastId): array
	{
		$chats = Application::getConnection()->query("
			select DISTINCT r.CHAT_ID, c.ID
			from b_im_relation r
					 inner join b_im_chat c on c.ID = r.CHAT_ID
			where r.USER_ID = " . $userId . "
			and c.ID > " . $lastId . "
			and exists(
				select CHAT_ID
				 from b_im_message
				 where chat_id = c.ID
				   and DATE_CREATE >= '" . $from->format('Y-m-d') . "'
				 order by DATE_CREATE desc)
			order by c.ID asc
			limit 10;
		")->fetchAll();
		$partCount = 0;
		$chatIds = array_column($chats, 'CHAT_ID');
		if (!empty($chatIds))
		{
			$chatIdsForSql = implode(',', $chatIds);
			$result = Application::getConnection()->query("
				select count(ID) as selectedcount 
				from b_im_message
				where AUTHOR_ID = " . $userId . "
					and CHAT_ID IN (" . $chatIdsForSql . ")
					and NOTIFY_TYPE = 0
					and DATE_CREATE >= '" . $from->format('Y-m-d') . "'
					and DATE_CREATE < '" . $to->format('Y-m-d') . "'
			")->fetch();
			$partCount = (int)$result['selectedcount'];
			if (count($chatIds) >= 10)
			{
				$lastId = (int)array_pop($chatIds);
			}
		}

		return [$lastId, $partCount];
	}
}
