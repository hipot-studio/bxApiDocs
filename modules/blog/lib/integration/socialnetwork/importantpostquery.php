<?php

namespace Bitrix\Blog\Integration\Socialnetwork;

use Bitrix\Blog\PostTable;
use Bitrix\Main\Type\DateTime;

final class ImportantPostQuery
{
	public function getNextPublicationDelay(DateTime $now, string $siteId): ?int
	{
		$row = PostTable::query()
			->setSelect(['DATE_PUBLISH'])
			->setFilter([
				'=PUBLISH_STATUS' => 'P',
				'>DATE_PUBLISH' => $this->getDateValue($now, $siteId, 'FULL'),
				'=UTS_OBJECT.UF_BLOG_POST_IMPRTNT' => 1,
				[
					'LOGIC' => 'OR',
					'=UTS_OBJECT.UF_IMPRTANT_DATE_END' => false,
					'>=UTS_OBJECT.UF_IMPRTANT_DATE_END' => $this->getDateValue($now, $siteId, 'SHORT'),
				],
			])
			->setOrder(['DATE_PUBLISH' => 'ASC'])
			->setLimit(1)
			->fetch();
		if ($row === false || !($row['DATE_PUBLISH'] instanceof DateTime))
		{
			return null;
		}

		return max(1, $row['DATE_PUBLISH']->getTimestamp() - $now->getTimestamp());
	}

	private function getDateValue(DateTime $now, string $siteId, string $format): string
	{
		return ConvertTimeStamp($now->getTimestamp() + \CTimeZone::GetOffset(null, false, $now), $format, $siteId);
	}
}
