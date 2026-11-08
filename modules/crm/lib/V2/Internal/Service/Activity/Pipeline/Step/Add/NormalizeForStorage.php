<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Add;

use Bitrix\Crm\V2\Internal\Entity\Activity\ProviderPayload;
use Bitrix\Crm\V2\Internal\Entity\Activity\StorageFieldTransit;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Main\Result;
use Stringable;

/**
 * The last thing that happens to the values before they are written: step 9 of the owner table of an add
 * (crm_activity.php:171-177).
 *
 * A VALUE NO COLUMN CAN HOLD BECOMES NOTHING. Legacy walks the columns of the table and empties every one of
 * them that carries something a column cannot store - an array, an object of no text form - and it does that
 * right before the insert, after the serialization and after the event, because both of those are places a
 * value like that can appear (a subscriber assigning an array, a provider handing its own object over). What
 * survives is what a column can hold: nothing at all, a scalar, or an object that knows its own text
 * (`Bitrix\Main\Type\DateTime` being the one that matters). V2 asks the same question of the two carriers whose
 * value is written as it stands - the storage columns of the transit and the data of the provider - because the
 * typed model cannot hold such a value in the first place.
 *
 * THE OWNER PAIR IS MADE NUMERIC (:174-175), and it is not a formality: the steps below read the owner out of
 * the transit - the record of the activity stream, the event of the calendar, the statistics per binding - and
 * an owner arriving as the text of a number would be compared with a number by some of them.
 *
 * THE OTHER HALF OF THE LEGACY STEP HAS NOTHING TO REPRODUCE. Legacy converts the three moments it writes out
 * of the time of the user into the time of the database, and it does so on a copy of its array precisely so
 * that the caller keeps the text it gave. In V2 a moment is a `DateTime` from the start - the model holds one,
 * a provider hands one over through the transit, and the storage boundary converts it - so there is no text of
 * a user left to convert, and no copy needed to protect one. The same emptiness as the removal of the calendar
 * keys of `[P5.T9]`: the step reproduces the effect that exists and invents nothing for the one that does not.
 *
 * @internal
 */
final class NormalizeForStorage implements PipelineStepInterface
{
	private const COLUMN_OWNER_ID = 'OWNER_ID';
	private const COLUMN_OWNER_TYPE_ID = 'OWNER_TYPE_ID';

	public function getName(): string
	{
		return 'NormalizeForStorage';
	}

	/**
	 * Every add is normalized. Legacy has no path around this.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		return true;
	}

	public function execute(PipelineContext $context): Result
	{
		self::emptyWhatNoColumnCanHold($context->getTransit(), $context->getPayload());
		self::makeTheOwnerPairNumeric($context->getTransit());

		return new Result();
	}

	/**
	 * Legacy interrupts nothing here: a value it could not store simply becomes nothing.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}

	private static function emptyWhatNoColumnCanHold(
		StorageFieldTransit $transit,
		ProviderPayload $payload,
	): void
	{
		foreach ($transit->getValues() as $column => $value)
		{
			if (!self::isStorable($value))
			{
				$transit->set((string)$column, null);
			}
		}

		// The two serialized columns of the payload are a blob by the time the step runs; the data of the
		// provider is the one column of it written as it stands, so it is the one that can carry such a value.
		if (!$payload->has(ProviderPayload::providerData))
		{
			return;
		}

		if (!self::isStorable($payload->get(ProviderPayload::providerData)))
		{
			$payload->set(ProviderPayload::providerData, null);
		}
	}

	private static function makeTheOwnerPairNumeric(StorageFieldTransit $transit): void
	{
		foreach ([self::COLUMN_OWNER_ID, self::COLUMN_OWNER_TYPE_ID] as $column)
		{
			if ($transit->has($column))
			{
				$transit->set($column, (int)$transit->get($column));
			}
		}
	}

	/**
	 * Whether a column can hold the value, in the sense legacy asks it: nothing, a scalar, or an object with a
	 * text form of its own.
	 */
	private static function isStorable(mixed $value): bool
	{
		return $value === null || is_scalar($value) || $value instanceof Stringable;
	}
}
