<?php
declare(strict_types=1);

namespace Bitrix\Disk\Public\Command\Quota;

use Bitrix\Disk\Internal\Service\Logger\LoggerFactory;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\UserTable;
use Bitrix\Main\Validation\ValidationResult;
use Throwable;

/**
 * Assigns the personal storage quota of an employee, in whole gigabytes.
 *
 * Assigning a quota to somebody who already has one changes it rather than adding a second: the row
 * of an employee is one, and the number of gigabytes in it belongs to this command. A quota below
 * what the employee has already taken up is stored as it is - warning about that is the business of
 * the interface, not of the write.
 */
class SetUserQuotaCommand extends AbstractCommand
{
	/** Not a size the column can hold: zero, negative, or beyond the upper bound. */
	public const ERROR_INVALID_QUOTA_SIZE = 'INVALID_QUOTA_SIZE';

	/** Nobody on the portal to assign the quota to. */
	public const ERROR_UNKNOWN_EMPLOYEE = 'UNKNOWN_EMPLOYEE';

	/** The limit was not stored for a reason of the portal rather than of the request. */
	public const ERROR_INTERNAL_FAILURE = 'INTERNAL_FAILURE';

	public const MIN_QUOTA_GB = 1;

	/**
	 * One pebibyte. The limit is stored in a bigint, and a size past this one overflows the column
	 * once turned into bytes: the database would refuse the write where the client deserves an
	 * answer about the number it sent.
	 */
	public const MAX_QUOTA_GB = 1048576;

	protected const LOG_FEATURE = 'disk personal quota assignment';

	public function __construct(
		public readonly int $userId,
		public readonly int $quotaGb,
	)
	{
	}

	/**
	 * The size is judged before the employee is looked up: a size that cannot be stored costs the
	 * portal no query about who it was meant for.
	 */
	protected function validate(): ValidationResult
	{
		$result = new ValidationResult();

		if ($this->quotaGb < self::MIN_QUOTA_GB || $this->quotaGb > self::MAX_QUOTA_GB)
		{
			$result->addError(new Error(
				(string)Loc::getMessage('DISK_QUOTA_SET_ERROR_INVALID_SIZE', [
					'#MIN#' => self::MIN_QUOTA_GB,
					'#MAX#' => self::MAX_QUOTA_GB,
				]),
				self::ERROR_INVALID_QUOTA_SIZE,
			));

			return $result;
		}

		if (!$this->isEmployeeOnTheList())
		{
			$result->addError(new Error(
				(string)Loc::getMessage('DISK_QUOTA_SET_ERROR_UNKNOWN_EMPLOYEE'),
				self::ERROR_UNKNOWN_EMPLOYEE,
			));
		}

		return $result;
	}

	protected function execute(): Result
	{
		$result = new Result();

		try
		{
			ServiceLocator::getInstance()->get(SetUserQuotaCommandHandler::class)($this);
		}
		catch (Throwable $exception)
		{
			$this->logFailure($exception);

			$result->addError(new Error(
				(string)Loc::getMessage('DISK_QUOTA_SET_ERROR_INTERNAL_FAILURE'),
				self::ERROR_INTERNAL_FAILURE,
			));
		}

		return $result;
	}

	/**
	 * The reason of the failure belongs to the journal of the portal and not to the answer: a failed
	 * statement names the table and the columns it was written into, and the code of an arbitrary
	 * throwable is no code of this command.
	 */
	protected function logFailure(Throwable $exception): void
	{
		LoggerFactory::create(feature: self::LOG_FEATURE)->error(
			'Personal quota of user {userId} was not stored: {exception}',
			[
				'userId' => $this->userId,
				'quotaGb' => $this->quotaGb,
				'exception' => $exception,
			],
		);
	}

	/**
	 * Whether the list of limits would show this employee. A row the list never shows is a row
	 * nobody can take away again, so the conditions are the ones the list reads by: dismissal is
	 * deactivation, and a service account is nobody to assign a limit to.
	 *
	 * Taking a limit away is not judged this way: a limit assigned before the employee was dismissed
	 * stays removable ({@see RemoveUserQuotaCommand}).
	 */
	protected function isEmployeeOnTheList(): bool
	{
		$employee = UserTable::getRow([
			'select' => ['ID'],
			'filter' => [
				'=ID' => $this->userId,
				'=ACTIVE' => 'Y',
				'=IS_REAL_USER' => true,
			],
		]);

		return $employee !== null;
	}
}
