<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Im\AnnualSummary;

use Bitrix\Im\Model\MessageTable;
use Bitrix\Im\Model\ReactionTable;
use Bitrix\Intranet\Internal\Entity\AnnualSummary\ReactionFeature;
use Bitrix\Intranet\Internal\Repository\AnnualSummaryRepository;
use Bitrix\Intranet\Internal\Service\AnnualSummary\AbstractFeatureProvider;
use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\Type\DateTime;

class ReactionProvider extends AbstractFeatureProvider
{
	public function calcValue(int $userId, DateTime $from, DateTime $to): int
	{
		return (int)ReactionTable::query()
			->registerRuntimeField(
				new Reference(
					'MESSAGE',
					MessageTable::class,
					Join::on('this.MESSAGE_ID', 'ref.ID'),
				),
			)
			->setSelect(['DATE_CREATE', 'MESSAGE_AUTHOR_ID' => 'MESSAGE.AUTHOR_ID'])
			->where('MESSAGE_AUTHOR_ID', $userId)
			->where('DATE_CREATE', '>=', $from)
			->where('DATE_CREATE', '<', $to)
			->queryCountTotal()
		;
	}

	public function createFeature(int $value): ReactionFeature
	{
		return new ReactionFeature($value);
	}

	public function precalcValue(int $userId): ?int
	{
		$data = (new AnnualSummaryRepository($userId))->getSerializedOption('annual_summary_25_im');
		if (!is_array($data))
		{
			return null;
		}

		return $data['reaction'] ?? null;
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
				select count(r.ID) as selectedcount
				from b_im_reaction r
				join b_im_message m on m.ID = r.MESSAGE_ID
				where m.AUTHOR_ID = " . $userId . "
				and m.CHAT_ID IN (" . $chatIdsForSql . ")
				and m.NOTIFY_TYPE = 0
				and r.DATE_CREATE >= '" . $from->format('Y-m-d') . "'
				and r.DATE_CREATE < '" . $to->format('Y-m-d') . "'
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
