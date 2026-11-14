<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Intranet\AnnualSummary;

use Bitrix\Intranet\Internal\Entity\AnnualSummary\Summary;
use Bitrix\Intranet\Public\Provider\AnnualSummary\SummaryProviderInterface;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Entity\EntityCollection;
use Bitrix\Main\Type\Date;
use Psr\Log\LoggerInterface;

/**
 * Base of the im annual summary providers: period, processed-users cursor and collection assembly.
 * A heir only declares its metric code, its batch size and the counting rule.
 *
 * Runs inside the intranet stepper (Bitrix\Intranet\Infrastructure\Agent\CalcAnnualSummary),
 * never in a user request: there is no access check in this layer.
 */
abstract class AbstractSummaryProvider implements SummaryProviderInterface
{
	private const LOGGER_ID = 'im.AnnualSummary';

	private int $lastUserId = 0;

	public function __construct(
		protected readonly Date $from,
		protected readonly Date $to,
	)
	{
	}

	final public function getLastUserId(): int
	{
		return $this->lastUserId;
	}

	/**
	 * Walks the batch while the users are counted to the end and stops on the first unfinished one.
	 *
	 * @param int[] $userIds
	 * @return EntityCollection<Summary>
	 */
	final public function provide(array $userIds): EntityCollection
	{
		$collection = new EntityCollection();

		foreach ($userIds as $userId)
		{
			try
			{
				$total = $this->calculate($userId);

				if ($total === null)
				{
					// The batch arrives ordered by id and filtered by the cursor, so nobody between
					// the previous cursor and the unfinished user is left uncounted: a cursor put
					// right before them returns them and the rest of the batch to the next step.
					$this->lastUserId = $userId - 1;

					return $collection;
				}

				$collection->add(new Summary($userId, $this->getId(), $total));
			}
			catch (\Throwable $e)
			{
				// A silently dropped user is indistinguishable from an honest zero, so it is logged.
				$this->getLogger()?->error(
					'Failed to calculate the annual summary metric',
					[
						'provider' => $this->getId(),
						'userId' => $userId,
						'exception' => $e->getMessage(),
					],
				);
			}

			// The cursor advances even on a failed user: the stepper must not replay the batch.
			$this->lastUserId = $userId;
		}

		return $collection;
	}

	abstract public function getId(): string;

	abstract public function getUserIdLimit(): int;

	/**
	 * The metric of the user, or null when the step ran out of its budget: such a user keeps no row
	 * in the answer, and the heir returning null owes a saved position the next step resumes from.
	 */
	abstract protected function calculate(int $userId): ?int;

	/**
	 * The registry is bypassed on purpose: these lines are the only trace of a lost or stalled
	 * count, and switching the logger on afterwards is too late, the season is already counted.
	 */
	final protected function getLogger(): ?LoggerInterface
	{
		return (new LoggerFactory())->createById(self::LOGGER_ID, isCheckEnabledFromRegistry: false);
	}
}
