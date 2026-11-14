<?php
declare(strict_types=1);

namespace Bitrix\Disk\Controller\Restrictions;

use Bitrix\Disk\Internal\Service\Logger\LoggerFactory;
use Bitrix\Disk\Internal\Service\Quota\QuotaListProvider;
use Bitrix\Disk\Internal\Service\Quota\QuotaOnboarding;
use Bitrix\Disk\Public\Command\Quota\RemoveUserQuotaCommand;
use Bitrix\Disk\Public\Command\Quota\SetUserQuotaCommand;
use Bitrix\Disk\Public\Enum\RestrictionPolicy;
use Bitrix\Disk\Public\Provider\RestrictionsAvailabilityProvider;
use Bitrix\Main\Command\Exception\CommandValidationException;
use Bitrix\Main\Context;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\HttpMethod as HttpMethodRule;
use Bitrix\Main\Engine\ActionFilter\HttpMethod;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Request;
use Throwable;

/**
 * Admin API of the employee limits: the personal quotas assigned on this portal.
 *
 * The section lives beside the file formats and answers under the same family of actions, so the
 * settings interface reaches both of them the same way. A portal that applies no quota refuses
 * every action here: a limit stored where the write node keeps no limit is a promise not kept.
 */
class Quota extends Controller
{
	use SectionAccessTrait;

	public const ERROR_INVALID_PAGE = 'INVALID_PAGE';
	public const ERROR_INVALID_SORT = 'INVALID_SORT';
	public const ERROR_INVALID_BATCH = 'INVALID_BATCH';

	public const DEFAULT_LIMIT = 30;
	public const MAX_LIMIT = 100;

	/**
	 * Employees one removal takes the limit away from at once. The ceiling is the largest page of
	 * the list (self::MAX_LIMIT), so a whole page selected in the header always comes off as one
	 * batch (AC-074, AC-077); past the interface it keeps the action from walking a list of no end.
	 */
	public const MAX_BATCH_SIZE = 100;

	/** Feature the journal of the portal keeps a failed removal under. */
	protected const LOG_FEATURE = 'disk personal quota withdrawal';

	protected QuotaListProvider $listProvider;
	protected RestrictionsAvailabilityProvider $availabilityProvider;

	public function __construct(?Request $request = null)
	{
		parent::__construct($request);

		$serviceLocator = ServiceLocator::getInstance();

		$this->listProvider = $serviceLocator->get(QuotaListProvider::class);
		$this->availabilityProvider = $serviceLocator->get('disk.restrictionsAvailabilityProvider');
	}

	/**
	 * Returns a page of the employees who have a personal quota assigned.
	 *
	 * The page and the sorting carry no type declaration on purpose: a declared parameter is judged
	 * before the action ever runs, {@see \Bitrix\Main\Engine\AutoWire\TypeDeclarationChecker},
	 * and a value of another type would be answered with a message about a parameter of a method
	 * under a 200 instead of with the 422 the contract names (API-01). What arrived is judged here.
	 *
	 * @param string|null $search Words to look the employee up by.
	 * @param mixed $offset First row of the page, a whole number of zero or more.
	 * @param mixed $limit Size of the page, a whole number from one to self::MAX_LIMIT.
	 * @param mixed $sort Column the list is ordered by, null for the order by default.
	 * @param mixed $order Direction of that column, ascending unless it says otherwise.
	 * @return array|null
	 */
	#[HttpMethodRule([HttpMethod::METHOD_GET])]
	public function listAction(
		?string $search = null,
		$offset = 0,
		$limit = self::DEFAULT_LIMIT,
		$sort = null,
		$order = null,
	): ?array
	{
		if (!$this->checkQuotasAreApplied())
		{
			return null;
		}

		if (!$this->checkPageIsValid($offset, $limit))
		{
			return null;
		}

		// a query string carries a null as an empty value, and the contract reads that as no sorting
		$sort = $sort === '' ? null : $sort;
		$order = $order === '' ? null : $order;

		if (!$this->checkSortIsKnown($sort, $order))
		{
			return null;
		}

		return $this->listProvider->getPage(
			(int)$offset,
			(int)$limit,
			$search,
			$sort === null ? null : (string)$sort,
			$order === null ? null : (string)$order,
		);
	}

	/**
	 * Assigns the personal quota of an employee and answers with the row the assignment made.
	 *
	 * Assigning a quota to somebody who already has one changes it rather than adding a second.
	 * The row of the answer is the row of the list, so the interface puts the assignment where it
	 * belongs instead of reading the page again; an employee the list does not show has no such
	 * row, and the answer carries none.
	 *
	 * @param int $userId Employee the limit belongs to.
	 * @param int $quotaGb Limit in whole gigabytes.
	 * @return array|null
	 */
	#[HttpMethodRule([HttpMethod::METHOD_POST])]
	public function setAction(int $userId, int $quotaGb): ?array
	{
		if (!$this->checkQuotasAreApplied())
		{
			return null;
		}

		if (!$this->checkQuotaSizeIsWhole())
		{
			return null;
		}

		try
		{
			$result = (new SetUserQuotaCommand($userId, $quotaGb))->run();
		}
		catch (CommandValidationException $exception)
		{
			$this->refuseByValidation($exception);

			return null;
		}

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return ['item' => $this->listProvider->getItem($userId)];
	}

	/**
	 * Takes the personal quota away from an employee and tells whether there was one to take away.
	 *
	 * @param int $userId Employee the limit belonged to.
	 * @return array|null
	 */
	#[HttpMethodRule([HttpMethod::METHOD_POST])]
	public function removeAction(int $userId): ?array
	{
		if (!$this->checkQuotasAreApplied())
		{
			return null;
		}

		try
		{
			$result = (new RemoveUserQuotaCommand($userId))->run();
		}
		catch (CommandValidationException $exception)
		{
			$this->refuseByValidation($exception);

			return null;
		}

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return ['removed' => (bool)$result->getData()[RemoveUserQuotaCommand::DATA_REMOVED]];
	}

	/**
	 * Takes the personal quota away from several employees at once and answers for each of them.
	 *
	 * The batch is no transaction: what has been taken away stays taken away, and an employee it did
	 * not work for is named with a reason instead of undoing the removals that did work. Losing the
	 * result for fourteen employees because of the fifteenth would only make the administrator do
	 * all fifteen again.
	 *
	 * Every employee is removed by the command a single removal uses, so the two ways of asking never
	 * come to mean different things about what a removal does to the counter.
	 *
	 * The selection carries no type declaration on purpose: a declared parameter is judged before the
	 * action ever runs, {@see \Bitrix\Main\Engine\AutoWire\TypeDeclarationChecker}, and a client
	 * naming no employee or sending a word where a list belongs would be told about a parameter of a
	 * method under a 200. The refusal stays where the contract keeps it - the section answers a
	 * selection it can make no batch of itself, with the same 422 an oversized one is answered with.
	 *
	 * @param mixed $userIds Employees the limits belonged to, in the order they are answered about.
	 * @return array|null
	 */
	#[HttpMethodRule([HttpMethod::METHOD_POST])]
	public function removeBatchAction($userIds = null): ?array
	{
		if (!$this->checkQuotasAreApplied())
		{
			return null;
		}

		$employees = $this->findBatchWithinTheContract($userIds);
		if ($employees === null)
		{
			return null;
		}

		$removed = [];
		$failed = [];

		foreach ($employees as $userId)
		{
			$failure = $this->findRemovalFailure($userId);

			if ($failure === null)
			{
				$removed[] = $userId;
			}
			else
			{
				$failed[] = ['userId' => $userId, 'message' => $failure];
			}
		}

		return ['removed' => $removed, 'failed' => $failed];
	}

	/**
	 * Remembers that the hint about the employee limits has been shown to this administrator.
	 *
	 * The mark is personal and the call is idempotent: a hint shown again is the same fact written
	 * again, not an error. Nobody reads the mark back through this API - the page of the file list
	 * carries it along with itself, so the hint is decided before it would be drawn.
	 *
	 * @return array|null
	 */
	#[HttpMethodRule([HttpMethod::METHOD_POST])]
	public function markOnboardingShownAction(): ?array
	{
		if (!$this->checkQuotasAreApplied())
		{
			return null;
		}

		QuotaOnboarding::markShown((int)$this->getCurrentUser()?->getId());

		return [];
	}

	protected function getAccessDeniedMessage(): string
	{
		return (string)Loc::getMessage('DISK_RESTRICTIONS_QUOTA_ERROR_ACCESS_DENIED');
	}

	protected function getRestrictionsUnavailableMessage(): string
	{
		return (string)Loc::getMessage('DISK_RESTRICTIONS_QUOTA_ERROR_UNAVAILABLE');
	}

	/**
	 * Every action of this section stands or falls with one policy, and the refusal is a 403 for
	 * all of them alike: a portal that applies no quota neither shows the limits nor stores them.
	 * The trait names the refusal, the section answering with it decides how it reaches the client.
	 */
	protected function checkQuotasAreApplied(): bool
	{
		if ($this->checkPolicyIsApplied(RestrictionPolicy::Quota, $this->availabilityProvider))
		{
			return true;
		}

		$this->setResponseStatus(403);

		return false;
	}

	/**
	 * A page the list cannot read is reported rather than repaired: a silently clamped page would
	 * answer with rows the client never asked for, and the scrolling would skip or repeat them. A
	 * page named by something that is no whole number is no page either and is refused the same way.
	 *
	 * How deep the pages go is not bounded: numbered pages reach the last row of the outcome, and a
	 * ceiling on the depth would show up as navigation breaking off (AC-077). A page beyond the
	 * outcome costs no walk all the same - the list counts before it reads,
	 * {@see QuotaListProvider::getPage()}.
	 */
	protected function checkPageIsValid(mixed $offset, mixed $limit): bool
	{
		$offsetIsValid = $this->isWholeNumber($offset) && (int)$offset >= 0;
		$limitIsValid = $this->isWholeNumber($limit) && (int)$limit >= 1 && (int)$limit <= self::MAX_LIMIT;

		if ($offsetIsValid && $limitIsValid)
		{
			return true;
		}

		$this->setResponseStatus(422);
		$this->addError(new Error(
			(string)Loc::getMessage('DISK_RESTRICTIONS_QUOTA_ERROR_INVALID_PAGE'),
			self::ERROR_INVALID_PAGE,
			['maxLimit' => self::MAX_LIMIT],
		));

		return false;
	}

	/**
	 * The sorting arrives as a name of the contract, not as a piece of the order it stands for: an
	 * unknown name is refused instead of being passed on, and nothing of the request is ever built
	 * into the sql. A value that is no name at all - a list, a number - is refused the same way. The
	 * direction is judged whenever it is sent, even where it decides nothing.
	 */
	protected function checkSortIsKnown(mixed $sort, mixed $order): bool
	{
		$sortIsKnown = $sort === null || (is_string($sort) && QuotaListProvider::isSortSupported($sort));
		$orderIsKnown = $order === null || (is_string($order) && QuotaListProvider::isOrderSupported($order));

		if ($sortIsKnown && $orderIsKnown)
		{
			return true;
		}

		$this->setResponseStatus(422);
		$this->addError(new Error(
			(string)Loc::getMessage('DISK_RESTRICTIONS_QUOTA_ERROR_INVALID_SORT'),
			self::ERROR_INVALID_SORT,
		));

		return false;
	}

	/**
	 * The size is judged whole here rather than by the command, whose parameter is an int already:
	 * the engine coerces the argument of an action, so 12.5 would silently become 12 - a limit the
	 * administrator never asked for. The value the client really sent is judged instead (ERR-001).
	 */
	protected function checkQuotaSizeIsWhole(): bool
	{
		$sentSize = $this->findSourceParameter('quotaGb');
		if ($sentSize === null || $this->isWholeNumber($sentSize))
		{
			return true;
		}

		$this->setResponseStatus(422);
		$this->addError(new Error(
			(string)Loc::getMessage('DISK_RESTRICTIONS_QUOTA_ERROR_FRACTIONAL_SIZE'),
			SetUserQuotaCommand::ERROR_INVALID_QUOTA_SIZE,
		));

		return false;
	}

	/**
	 * A request no removal can be made of is refused as a whole, and that is the only refusal of the
	 * kind here: an employee a removal did not work for is named in the answer instead of failing
	 * the call. The identifiers are judged as the client sent them, the same way the size of a quota
	 * is judged (ERR-001): the engine leaves an array to the action as it stands, so a fraction sent
	 * among the identifiers would reach the command as a whole number of somebody else.
	 *
	 * @return int[]|null Employees to take the limit away from, null when the batch is refused.
	 */
	protected function findBatchWithinTheContract(mixed $userIds): ?array
	{
		$sentIds = $this->findSourceParameter('userIds') ?? $userIds;

		if (is_array($sentIds) && $this->isBatchOfWholeNumbers($sentIds))
		{
			return $this->collapseRepeatedEmployees($sentIds);
		}

		$this->setResponseStatus(422);
		$this->addError(new Error(
			(string)Loc::getMessage('DISK_RESTRICTIONS_QUOTA_ERROR_INVALID_BATCH'),
			self::ERROR_INVALID_BATCH,
			['maxBatchSize' => self::MAX_BATCH_SIZE],
		));

		return null;
	}

	/**
	 * An employee named twice is answered about once, by the first mention: the second one would be
	 * told the limit is not assigned any more, and the answer would contradict itself about the same
	 * employee. The ceiling is counted before this, on the identifiers as they arrived - walking
	 * through them is cheaper than making a million of them unique first.
	 *
	 * @return int[] Employees in the order they were first named.
	 */
	protected function collapseRepeatedEmployees(array $sentIds): array
	{
		$employees = array_map(static fn(mixed $sentId): int => (int)$sentId, $sentIds);

		return array_values(array_unique($employees));
	}

	protected function isBatchOfWholeNumbers(array $userIds): bool
	{
		if ($userIds === [] || count($userIds) > self::MAX_BATCH_SIZE)
		{
			return false;
		}

		foreach ($userIds as $userId)
		{
			if (!$this->isWholeNumber($userId))
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * Takes the limit of one employee away as a part of a batch.
	 *
	 * A batch keeps going where a single removal would refuse: an employee the portal does not have
	 * and an employee who was given no limit are both reported about rather than answered with. An
	 * unforeseen failure of one employee is no reason to drop the rest either, and the words of a
	 * throwable are no words to show an administrator - the section says it in its own.
	 *
	 * The throwable itself belongs to the journal all the same, and this is the only place that puts
	 * it there: the command writes down what fails inside its own work, but the check it makes before
	 * that work runs outside the writing, {@see \Bitrix\Main\Command\AbstractCommand::run()}.
	 *
	 * @return string|null Why the limit was not taken away, null when it was.
	 */
	protected function findRemovalFailure(int $userId): ?string
	{
		try
		{
			$result = (new RemoveUserQuotaCommand($userId))->run();
		}
		catch (CommandValidationException $exception)
		{
			return $this->describeFailure($exception->getValidationErrors());
		}
		catch (Throwable $exception)
		{
			LoggerFactory::create(feature: self::LOG_FEATURE)->error(
				'Personal quota of user {userId} was not taken away: {exception}',
				['userId' => $userId, 'exception' => $exception],
			);

			return (string)Loc::getMessage('DISK_RESTRICTIONS_QUOTA_ERROR_BATCH_FAILURE');
		}

		if (!$result->isSuccess())
		{
			return $this->describeFailure($result->getErrors());
		}

		if (!$result->getData()[RemoveUserQuotaCommand::DATA_REMOVED])
		{
			return (string)Loc::getMessage('DISK_RESTRICTIONS_QUOTA_ERROR_BATCH_NOTHING_TO_REMOVE');
		}

		return null;
	}

	/**
	 * @param Error[] $errors Errors of the command, which name the reason in russian already.
	 * @return string Reason shown to the administrator; a failure naming none is told by the section.
	 */
	protected function describeFailure(array $errors): string
	{
		foreach ($errors as $error)
		{
			$message = trim((string)$error->getMessage());
			if ($message !== '')
			{
				return $message;
			}
		}

		return (string)Loc::getMessage('DISK_RESTRICTIONS_QUOTA_ERROR_BATCH_FAILURE');
	}

	/**
	 * The value is taken from the very list the engine binds the arguments of an action from,
	 * {@see \Bitrix\Main\Engine\Binder}, and in the order it reads them: what is judged here and
	 * what reaches the action are then one and the same value.
	 */
	protected function findSourceParameter(string $name): mixed
	{
		foreach ((array)$this->getSourceParametersList() as $source)
		{
			if (isset($source[$name]))
			{
				return $source[$name];
			}
		}

		return null;
	}

	/**
	 * A number sent over the wire reaches the action as an int, a float or a string of digits,
	 * depending on how the client encoded the request. Whether it is whole is the same question
	 * in all three cases; whether it fits the allowed range is the business of the command.
	 */
	protected function isWholeNumber(mixed $value): bool
	{
		if (is_int($value))
		{
			return true;
		}

		if (is_float($value))
		{
			return $value === floor($value);
		}

		return is_string($value) && preg_match('/^\s*-?\d+\s*$/', $value) === 1;
	}

	/**
	 * Validation of a command refuses by an exception rather than by an unsuccessful result, and
	 * the errors it carries are ready to be answered with: they name the code and speak russian.
	 */
	protected function refuseByValidation(CommandValidationException $exception): void
	{
		$this->setResponseStatus(422);
		$this->addErrors($exception->getValidationErrors());
	}

	protected function setResponseStatus(int $status): void
	{
		Context::getCurrent()?->getResponse()?->setStatus((string)$status);
	}
}
