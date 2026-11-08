<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;

/**
 * Whom a record of the history of the CRM names as its author.
 *
 * THE ROW ANSWERS FIRST, THE HIT NEVER DOES. Legacy resolves the author out of the activity itself - the editor
 * of it, otherwise the person responsible, otherwise its author (`resolveEventAuthorId()`,
 * crm_activity.php:6362-6373) - and where the row names nobody the record falls back to the user the caller set
 * on the context explicitly, never to the ambient user of the request (`createEvent()`, :6338-6345). A zero
 * travels on from there: the writer of the history stamps the system user with it. The rule is what keeps a
 * background flow - an agent, a queue, a hook of another module - from being recorded as whoever happened to
 * open a page at that moment (Mantis 184341).
 *
 * WHY A NAMED PLACE FOR IT. Three steps in three files write records of the history, and before this class each
 * carried its own copy of the resolution; the copies then stayed behind when legacy changed the rule. One place
 * is what makes the next change of it a change of one line rather than of three that may be missed.
 *
 * THE TWO HALVES ARE ASKED SEPARATELY, because legacy asks them separately: the record of an add and the records
 * of an update pass through both, while a relation of an add is written straight from the row and keeps a zero
 * as it is.
 *
 * @internal
 */
final class HistoryAuthor
{
	/**
	 * The author the row of the activity names: the editor, otherwise the person responsible, otherwise the
	 * author of the activity. Zero when it names none of the three.
	 */
	public static function ofTheRow(Activity $activity): int
	{
		$candidates = [$activity->getEditorId(), $activity->getResponsibleId(), $activity->getAuthorId()];
		foreach ($candidates as $candidate)
		{
			if ((int)$candidate > 0)
			{
				return (int)$candidate;
			}
		}

		return 0;
	}

	/**
	 * The author of a record where the row named one, and the explicitly set user of the context where it did
	 * not. Zero means "nobody named an actor", which the writer of the history turns into the system user.
	 */
	public static function orTheExplicitUser(int $authorId): int
	{
		if ($authorId > 0)
		{
			return $authorId;
		}

		return (int)(Container::getInstance()->getContext()->getExplicitUserId() ?? 0);
	}
}
