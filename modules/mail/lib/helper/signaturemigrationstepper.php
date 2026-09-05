<?php

namespace Bitrix\Mail\Helper;

use Bitrix\Mail\Service\SharedSignature\SignatureMigrator;
use Bitrix\Main;
use Bitrix\Main\Localization\Loc;

/**
 * Walks the legacy table of personal signatures in portions and copies every row into the unified
 * model with the very same migration the access points use (P7.T3).
 *
 * Its purpose is to make the migration finite, not to make it faster: active users are migrated by
 * their own access — opening the signature list or saving a signature. A person who never opens
 * that screen would stay in the legacy table forever, because the substitution of a signature is a
 * hot read path and writing from it is forbidden. And while anybody is left there, the fallback
 * read, the legacy table itself and the compatibility adapter cannot be removed.
 *
 * The upper bound of the cursor is fixed on the first step, so the rows created while the stepper
 * is running do not widen its range — those users are covered by the access-time migration. A
 * repeated run creates no duplicates: the migration is idempotent through the unique index over
 * LEGACY_ID, which also makes working side by side with a user safe.
 *
 * Completion is observable from outside: the option main.stepper.mail is dropped when the walk is
 * over, and SignatureMigrator::countPending() answers how many rows are left. A walk that leaves
 * nothing behind also records the border it reached, see SignatureMigrator::confirmCompletion() —
 * that is what lets the compatibility adapter stop searching the legacy table by owner.
 */
class SignatureMigrationStepper extends Main\Update\Stepper
{
	/** Rows one step copies. */
	public const LIMIT = 50;

	protected static $moduleId = 'mail';

	public static function getTitle()
	{
		return Loc::getMessage('MAIL_SIGNATURE_MIGRATION_STEPPER_TITLE') ?? parent::getTitle();
	}

	public function execute(array &$option): bool
	{
		if (empty($option))
		{
			$option['lastId'] = 0;
			$option['maxId'] = SignatureMigrator::maxLegacyId();
			$option['count'] = SignatureMigrator::countPending();
			$option['steps'] = 0;
		}

		if ((int)$option['lastId'] >= (int)$option['maxId'])
		{
			return $this->finish();
		}

		$portion = (new SignatureMigrator())->migrateRange(
			(int)$option['lastId'],
			(int)$option['maxId'],
			self::LIMIT,
		);

		if ($portion['processed'] === 0)
		{
			// Nothing left within the fixed range even though the cursor has not reached its end:
			// the rows in between are gone.
			return $this->finish();
		}

		$option['lastId'] = $portion['lastId'];
		$option['steps'] = (int)($option['steps'] ?? 0) + $portion['processed'];

		return (int)$option['lastId'] >= (int)$option['maxId']
			? $this->finish()
			: self::CONTINUE_EXECUTION
		;
	}

	/**
	 * Ends the walk and remembers the border everything below which has a copy, unless something is
	 * still left to copy — a row the copying failed on. That border is what keeps the compatibility
	 * adapter from searching the legacy table by owner on every personal-signature read.
	 */
	private function finish(): bool
	{
		SignatureMigrator::confirmCompletion();

		return self::FINISH_EXECUTION;
	}
}
