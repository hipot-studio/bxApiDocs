<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Summary;

use Bitrix\Crm\Field;
use Bitrix\Crm\Item;
use Bitrix\Crm\Item\Deal;
use Bitrix\Crm\RepeatSale\DataCollector\Mapper\SystemFieldsMapper;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Entity\Summary\SummarySection;
use Bitrix\Crm\V2\Internal\Entity\Summary\TruncationReason;
use Bitrix\Main\Type\Date;
use CCrmOwnerType;

/**
 * Deals of a client told in full: one flat record per deal, named and normalized the way the agent
 * prompt of repeat sales already reads them.
 *
 * The map of the fields belongs to {@see SystemFieldsMapper} and is reused as it is, so a summary
 * and a repeat sale never disagree about what the stage or the type of a deal is called. What is
 * not reused is {@see \Bitrix\Crm\RepeatSale\DataCollector\EntityDataCollector}: it runs the data
 * through `array_filter` before mapping, which silently drops a deal amount of zero and an empty
 * stage semantics - both of which a summary has to tell about.
 *
 * The map is fed the fields of {@see SummaryLimits::DEAL_DETAIL_SELECT} read one by one, and not the
 * compatible form of the whole deal: that form also reads the UTM marks, the last communication and
 * the content type of the text fields, three queries per deal that no record of a summary is made of.
 *
 * Two things are done to the data around that map: a field the storage answers `null` for is read
 * as blank, and a text of a deal is cut to {@see SummaryLimits::DEAL_TEXT_LENGTH} so that the size
 * of the section is bounded by the count of the deals and not by what was written on them.
 *
 * @internal
 */
class DealDetailsReader
{
	/**
	 * Fields the map normalizes through a non-nullable string, so a deal that was never given one
	 * would cost the whole record a `TypeError`. Every normalizer of them answers on an empty string
	 * what the field says: an empty text, a source and a type of no name, a deal that is not closed.
	 */
	private const BLANK_WHEN_MISSING = [
		Item::FIELD_NAME_TITLE,
		Item::FIELD_NAME_CLOSED,
		Item::FIELD_NAME_TYPE_ID,
		Item::FIELD_NAME_SOURCE_ID,
		Item::FIELD_NAME_SOURCE_DESCRIPTION,
		Item::FIELD_NAME_COMMENTS,
		Deal::FIELD_NAME_ADDITIONAL_INFO,
	];

	/**
	 * Keys the map is written against for the fields it names differently from the way they are read:
	 * a deal is created at `DATE_CREATE` there and at `CREATED_TIME` everywhere else.
	 */
	private const MAP_KEYS = [
		Item::FIELD_NAME_CREATED_TIME => 'DATE_CREATE',
	];

	/** Fields of a record the length limit of a deal text is counted against, named after the map. */
	private const TEXT_FIELDS = [
		'deal_title',
		'deal_source_description',
		'comments',
		'additional_info',
	];

	/**
	 * @param Item[] $deals Deals to tell the details of, already filtered by the rights of the user.
	 * @param int $accessibleCount Deals of the client the user may see, the ones above included.
	 */
	public function read(array $deals, int $accessibleCount): SummarySection
	{
		$mapper = new SystemFieldsMapper(CCrmOwnerType::Deal);
		$fields = $this->dealFields();

		$records = [];
		$isTextCut = false;
		foreach (array_values($deals) as $deal)
		{
			$record = $mapper->map(self::withoutMissingValues(self::selectedFieldsOf($deal, $fields)));

			[$record, $isRecordCut] = self::withinTextLength($record);
			$isTextCut = $isTextCut || $isRecordCut || self::isTextCutByTheRead($deal);

			$records[] = $record;
		}

		// deals gone between the selection and this read leave nothing to tell, and a section that
		// carries nothing is an empty one: how wide the history was is told by the aggregates instead
		if ($records === [])
		{
			return SummarySection::empty();
		}

		return SummarySection::filled(
			$records,
			self::truncationReason($accessibleCount > count($records), $isTextCut),
		);
	}

	/**
	 * The fields of a deal in the form the map is written against, keyed the way the map names them.
	 *
	 * A text of a deal is read cut by {@see DealSelector::selectDetails()} and arrives under a name of
	 * its own, so it is asked for by that name; what it says about the field is still decided by the
	 * field itself.
	 */
	private static function selectedFieldsOf(Item $deal, ?Field\Collection $fields): array
	{
		$data = [];
		foreach (SummaryLimits::DEAL_DETAIL_SELECT as $field)
		{
			$data[self::MAP_KEYS[$field] ?? $field] = self::externalValueOf(
				$deal->get(SummaryLimits::DEAL_TEXT_ALIASES[$field] ?? $field),
				$fields?->getField($field),
			);
		}

		return $data;
	}

	/**
	 * The value the compatible form of a deal answers for a field: a date says itself as the string it
	 * is stored as, a field the deal has no value for says `null`, and a flag says the `Y` or the `N`
	 * the map normalizes. Without the field of the portal behind it a value is passed on as it is.
	 */
	private static function externalValueOf(mixed $value, ?Field $field): mixed
	{
		if ($value instanceof Date)
		{
			// a date and a date with a time both arrive here, and each says itself its own way
			return $value->toString();
		}

		if ($field === null)
		{
			return $value;
		}

		if (!self::isZero($value) && $field->isValueEmpty($value))
		{
			return null;
		}

		return is_bool($value) && $field->getType() === Field::TYPE_BOOLEAN
			? ($value ? 'Y' : 'N')
			: $value
		;
	}

	/**
	 * A number equal to zero is a value of its own and never a missing one: a deal of exactly no money
	 * is a fact the summary tells, and told as `null` it is the same answer as a deal nobody ever named
	 * an amount of.
	 *
	 * The rule is asked before the field, because a field may call its own zero empty -
	 * {@see \Bitrix\Crm\Field\Opportunity::isValueEmpty()} does exactly that, the deal amount being the
	 * one field of the details that overrides the check. For every other number of a deal the field
	 * itself already answers the same way {@see \Bitrix\Crm\Field::isValueEmpty()}, so the rule changes
	 * nothing there.
	 */
	private static function isZero(mixed $value): bool
	{
		return is_numeric($value) && (float)$value === 0.0;
	}

	/**
	 * The fields of a deal as the portal describes them: which value counts as no value at all and
	 * which field is a flag is decided by the portal, the same way the compatible form decides it.
	 */
	protected function dealFields(): ?Field\Collection
	{
		return Container::getInstance()->getFactory(CCrmOwnerType::Deal)?->getFieldsCollection();
	}

	/**
	 * A field of the compatible form the deal has nothing in becomes an empty string, and only for the
	 * fields the map cannot normalize a `null` of. A key the form did not answer at all stays absent:
	 * the record is made of what was selected.
	 */
	private static function withoutMissingValues(array $data): array
	{
		foreach (self::BLANK_WHEN_MISSING as $field)
		{
			if (array_key_exists($field, $data) && $data[$field] === null)
			{
				$data[$field] = '';
			}
		}

		return $data;
	}

	/**
	 * Whether a text of the deal lost its tail to the query that read it. A text arrives cut to
	 * {@see SummaryLimits::READ_TEXT_LENGTH}, so one that arrived at exactly that length almost
	 * certainly had more to say, and the section has to admit that whatever the markup left of it
	 * afterwards: sixty thousand characters of markup around a sentence clean up to well inside the
	 * limit of a record and would otherwise pass for a whole text.
	 *
	 * The title of a deal is not among the texts checked - it is read whole, its column being short
	 * enough to need no cutting.
	 */
	private static function isTextCutByTheRead(Item $deal): bool
	{
		foreach (SummaryLimits::DEAL_TEXT_ALIASES as $alias)
		{
			if (mb_strlen((string)$deal->get($alias)) >= SummaryLimits::READ_TEXT_LENGTH)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @return array{0: array, 1: bool} The record and whether a text of it had to be cut.
	 */
	private static function withinTextLength(array $record): array
	{
		$isCut = false;
		foreach (self::TEXT_FIELDS as $field)
		{
			$text = (string)($record[$field] ?? '');
			if (mb_strlen($text) > SummaryLimits::DEAL_TEXT_LENGTH)
			{
				$record[$field] = mb_substr($text, 0, SummaryLimits::DEAL_TEXT_LENGTH);
				$isCut = true;
			}
		}

		return [$record, $isCut];
	}

	/**
	 * A deal left out entirely is the coarser loss, so it names the reason when both happened.
	 */
	private static function truncationReason(bool $isItemLimitHit, bool $isLengthLimitHit): ?TruncationReason
	{
		return match (true)
		{
			$isItemLimitHit => TruncationReason::ItemLimit,
			$isLengthLimitHit => TruncationReason::LengthLimit,
			default => null,
		};
	}
}
