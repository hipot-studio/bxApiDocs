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
 * Takes the personal storage quota away from an employee.
 *
 * The counter of what they have taken up goes with the limit rather than by an operation of its
 * own: the size an employee with no limit occupies is nobody's accounting, and a counter left
 * behind would only be served stale the day a limit is assigned again.
 */
class RemoveUserQuotaCommand extends AbstractCommand
{
	/** Nobody on the portal to take the quota away from. */
	public const ERROR_UNKNOWN_EMPLOYEE = 'UNKNOWN_EMPLOYEE';

	/** The limit was not taken away for a reason of the portal rather than of the request. */
	public const ERROR_INTERNAL_FAILURE = 'INTERNAL_FAILURE';

	/** Whether there was a quota to take away, {@see Result::getData()}. */
	public const DATA_REMOVED = 'removed';

	protected const LOG_FEATURE = 'disk personal quota withdrawal';

	public function __construct(
		public readonly int $userId,
	)
	{
	}

	protected function validate(): ValidationResult
	{
		$result = new ValidationResult();

		if (!$this->employeeExists())
		{
			$result->addError(new Error(
				(string)Loc::getMessage('DISK_QUOTA_REMOVE_ERROR_UNKNOWN_EMPLOYEE'),
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
			$removed = ServiceLocator::getInstance()->get(RemoveUserQuotaCommandHandler::class)($this);

			$result->setData([self::DATA_REMOVED => $removed]);
		}
		catch (Throwable $exception)
		{
			$this->logFailure($exception);

			$result->addError(new Error(
				(string)Loc::getMessage('DISK_QUOTA_REMOVE_ERROR_INTERNAL_FAILURE'),
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
			'Personal quota of user {userId} was not taken away: {exception}',
			[
				'userId' => $this->userId,
				'exception' => $exception,
			],
		);
	}

	protected function employeeExists(): bool
	{
		$employee = UserTable::getRow([
			'select' => ['ID'],
			'filter' => ['=ID' => $this->userId],
		]);

		return $employee !== null;
	}
}
