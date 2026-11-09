<?php

declare(strict_types=1);

namespace Bitrix\Landing\Markup;

use Bitrix\Landing\Internals\BlockTable;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;

/**
 * The single server predicate of a custom markup page.
 *
 * A page is custom when it holds a live block with a stored package manifest or a live
 * block with the carrier code: a carrier whose manifest is not saved yet (a creation
 * failed right after the block step) is custom too. Every check "is the page custom"
 * goes through this class.
 */
final class MarkupPage
{
	/**
	 * @param string|null $public Edition of the page: 'N' - the draft, 'Y' - the published one, null - any.
	 */
	public static function isMarkupLanding(int $landingId, ?string $public = null): bool
	{
		if ($landingId <= 0)
		{
			return false;
		}

		$query = BlockTable::query()
			->setSelect(['ID'])
			->where('LID', $landingId)
			->where('DELETED', 'N')
			->where(self::getCarrierFilter())
			->setLimit(1)
		;
		if ($public !== null)
		{
			$query->where('PUBLIC', $public);
		}
		$row = $query->exec()->fetch();

		return (bool)$row;
	}

	/**
	 * Whether the block is the carrier of a markup package, regardless of its page.
	 */
	public static function isCarrierBlock(int $blockId): bool
	{
		if ($blockId <= 0)
		{
			return false;
		}

		$row = BlockTable::query()
			->setSelect(['ID'])
			->where('ID', $blockId)
			->where(self::getCarrierFilter())
			->exec()
			->fetch()
		;

		return (bool)$row;
	}

	/**
	 * Whether the page holds a live ordinary block of the same edition (draft or public)
	 * as the given block: a carrier restored beside it would mix the two kinds of page.
	 */
	public static function hasOrdinaryBlocksBeside(int $landingId, int $blockId): bool
	{
		if ($landingId <= 0 || $blockId <= 0)
		{
			return false;
		}

		$block = BlockTable::query()
			->setSelect(['PUBLIC'])
			->where('ID', $blockId)
			->exec()
			->fetch()
		;
		if (!$block)
		{
			return false;
		}

		$row = BlockTable::query()
			->setSelect(['ID'])
			->where('LID', $landingId)
			->where('DELETED', 'N')
			->where('PUBLIC', $block['PUBLIC'])
			->where('ID', '!=', $blockId)
			->where('CODE', '!=', Intake::BLOCK_CODE)
			->where(
				Query::filter()
					->logic('or')
					->whereNull('MARKUP_MANIFEST')
					->where('MARKUP_MANIFEST', ''),
			)
			->setLimit(1)
			->exec()
			->fetch()
		;

		return (bool)$row;
	}

	private static function getCarrierFilter(): ConditionTree
	{
		return Query::filter()
			->logic('or')
			->where('CODE', Intake::BLOCK_CODE)
			->where(
				Query::filter()
					->whereNotNull('MARKUP_MANIFEST')
					->where('MARKUP_MANIFEST', '!=', ''),
			)
		;
	}
}
