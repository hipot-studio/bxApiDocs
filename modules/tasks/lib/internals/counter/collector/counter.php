<?php

namespace Bitrix\Tasks\Internals\Counter\Collector;


final class Counter
{
	public static function getConditionForRecountComments(): string
	{
		return "(
				(TV.VIEWED_DATE IS NOT NULL AND FM.POST_DATE > TV.VIEWED_DATE)
				OR (TV.VIEWED_DATE IS NULL AND FM.POST_DATE >= T.CREATED_DATE)
			)";
	}
}
