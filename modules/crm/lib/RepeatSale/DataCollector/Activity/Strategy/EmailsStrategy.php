<?php

namespace Bitrix\Crm\RepeatSale\DataCollector\Activity\Strategy;

use Bitrix\Crm\Activity\Provider\Email;
use Bitrix\Crm\RepeatSale\DataCollector\Activity\ActivityType;
use Bitrix\Crm\RepeatSale\DataCollector\Activity\IdentifiedRecordsInterface;
use Bitrix\Crm\RepeatSale\DataCollector\Activity\StrategyBase;
use CCrmContentType;

class EmailsStrategy extends StrategyBase implements IdentifiedRecordsInterface
{
	public function getType(): ActivityType
	{
		return ActivityType::EMAILS;
	}

	public function collect(int $entityId, int $limit): array
	{
		return array_column($this->collectIdentified($entityId, $limit), 'text');
	}

	/**
	 * The identifier is the activity the letter belongs to: one letter bound to a client and to a deal
	 * of it is one letter, and two letters of the same wording are two.
	 */
	public function collectIdentified(int $entityId, int $limit): array
	{
		$emailsRaw = $this
			->queryBuilder
			->buildActivityWithBindingQuery($this->entityTypeId, $entityId, Email::getId(), $limit)
			->addSelect('ID')
			->fetchAll()
		;
		if (empty($emailsRaw))
		{
			return [];
		}

		$emails = [];
		foreach ($emailsRaw as $row)
		{
			$subject = $row['SUBJECT'] ?? '';
			$description = $row['DESCRIPTION'] ?? '';
			if (empty($description))
			{
				continue;
			}

			$emailText = $this->formatEmailText($subject, $description);
			$normalizedText = $this
				->textNormalizer
				->normalize($emailText, $this->getType(), CCrmContentType::Html)
			;
			if ($normalizedText !== null && trim($normalizedText) !== '')
			{
				$emails[] = ['id' => (int)$row['ID'], 'text' => $normalizedText];
			}
		}

		return $emails;
	}

	private function formatEmailText(string $subject, string $description): string
	{
		if (empty($subject))
		{
			return $description;
		}

		return sprintf('%s: %s', $subject, $description);
	}
}
