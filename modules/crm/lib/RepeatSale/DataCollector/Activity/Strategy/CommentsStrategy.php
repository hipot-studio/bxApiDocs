<?php

namespace Bitrix\Crm\RepeatSale\DataCollector\Activity\Strategy;

use Bitrix\Crm\RepeatSale\DataCollector\Activity\ActivityType;
use Bitrix\Crm\RepeatSale\DataCollector\Activity\IdentifiedRecordsInterface;
use Bitrix\Crm\RepeatSale\DataCollector\Activity\StrategyBase;

class CommentsStrategy extends StrategyBase implements IdentifiedRecordsInterface
{
	public function getType(): ActivityType
	{
		return ActivityType::COMMENTS;
	}

	public function collect(int $entityId, int $limit): array
	{
		return array_column($this->collectIdentified($entityId, $limit), 'text');
	}

	/**
	 * The identifier is the timeline entry the comment belongs to: one entry bound to a client and to a
	 * deal of it is one comment, and two entries saying the same thing are two.
	 */
	public function collectIdentified(int $entityId, int $limit): array
	{
		$commentsRaw = $this
			->queryBuilder
			->buildCommentsQuery($this->entityTypeId, $entityId, $limit)
			->addSelect('ID')
			->fetchAll()
		;
		if (empty($commentsRaw))
		{
			return [];
		}

		$comments = [];
		foreach ($commentsRaw as $row)
		{
			$comment = $row['COMMENT'] ?? '';
			$normalizedText = $this
				->textNormalizer
				->normalize($comment, $this->getType())
			;
			if ($normalizedText !== null && trim($normalizedText) !== '')
			{
				$comments[] = ['id' => (int)$row['ID'], 'text' => $normalizedText];
			}
		}

		return $comments;
	}
}
