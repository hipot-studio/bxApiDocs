<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\MessageList;

use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Mail\Helper\MailboxDirectoryHelper;

/**
 * Whether a page carries the letters that can still be called back. They stand in front of the whole
 * list, so they are offered on the first page of the sent folder alone and only while nothing narrows
 * it.
 */
final class UndoSendProjectionPolicy
{
	/** Fields of the filter that narrow the list; any of them filled leaves the rows out. */
	private const NARROWING_FIELDS = [
		'FIND',
		'DATE_from',
		'DATE_to',
		'IS_SEEN',
		'BIND',
		'ATTACHMENTS',
		'LABEL_ID',
	];

	/**
	 * The feature check comes first on purpose: everything below it costs a permission check and a
	 * query, and every portal draws the first page of the sent folder.
	 *
	 * @param int $page already brought into range by the navigation
	 */
	public function isOffered(
		ScreenContext $context,
		FilterState $filterState,
		MailboxDirectoryHelper $dirsHelper,
		int $page,
	): bool
	{
		if (!Feature::isUndoSendAvailable())
		{
			return false;
		}

		if ($page !== 1 || $context->isAllMailMode)
		{
			return false;
		}

		$outcomePath = (string)$dirsHelper->getOutcomePath(true);
		if ($outcomePath === '' || $filterState->currentDir !== $outcomePath)
		{
			return false;
		}

		foreach (self::NARROWING_FIELDS as $field)
		{
			$value = $filterState->filter[$field] ?? null;
			if ($value !== null && $value !== '')
			{
				return false;
			}
		}

		return true;
	}
}
