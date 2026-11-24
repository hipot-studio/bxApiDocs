<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Service\Marketing\Fact;

use Bitrix\Mobile\Internal\Entity\Marketing\EvaluationContext;
use Bitrix\Mobile\Internal\Entity\Marketing\FactDefinition;
use Bitrix\Mobile\Internal\Entity\Marketing\FactType;
use Bitrix\Mobile\Internal\Integration\Crm\DealReader;
use Bitrix\Mobile\Internal\Integration\Intranet\InvitationReader;
use Bitrix\Mobile\Internal\Integration\ServiceFactory;
use Bitrix\Mobile\Internal\Integration\Tasks\TaskReader;
use Bitrix\Mobile\Internal\Integration\UnreadableRightsException;
use Bitrix\Mobile\Internal\Service\Marketing\FactSource;
use Bitrix\Mobile\Internal\Service\Marketing\PartialReadException;

/**
 * What the user may do, apart from what he did: a missing right does not change the fact of an action.
 * Each right is read per user. A reader that cannot answer at all - its module is absent - leaves every
 * user of the pack unknown, never false; a right that fails to be read for some users is reported for
 * them alone, and the evaluator fails only them.
 */
final class PermissionFacts implements FactSource
{
	public const CREATE_TASK = 'permission.createTask';
	public const CREATE_DEAL = 'permission.createDeal';
	public const INVITE = 'permission.invite';

	private readonly TaskReader $tasks;
	private readonly DealReader $deals;
	private readonly InvitationReader $invitations;

	public function __construct(
		?TaskReader $tasks = null,
		?DealReader $deals = null,
		?InvitationReader $invitations = null,
	)
	{
		$this->tasks = $tasks ?? new TaskReader();
		$this->deals = $deals ?? (new ServiceFactory())->createDealReader();
		$this->invitations = $invitations ?? new InvitationReader();
	}

	public function getDefinitions(): array
	{
		return [
			new FactDefinition(self::CREATE_TASK, FactType::Boolean, true),
			new FactDefinition(self::CREATE_DEAL, FactType::Boolean, true),
			new FactDefinition(self::INVITE, FactType::Boolean, true),
		];
	}

	public function read(string $code, array $params, EvaluationContext $context, array $userIds): array
	{
		$userIds = array_values(array_unique(array_map('intval', $userIds)));
		if ($userIds === [])
		{
			return [];
		}

		try
		{
			$rights = match ($code)
			{
				self::CREATE_TASK => $this->tasks->canCreate($userIds),
				self::CREATE_DEAL => $this->deals->canCreate($userIds),
				self::INVITE => $this->invitations->canInvite($userIds),
				default => null,
			};
		}
		catch (UnreadableRightsException $exception)
		{
			$failures = $exception->getFailures();
			$readable = array_values(array_diff($userIds, array_keys($failures)));

			throw new PartialReadException(self::valuesOf($exception->getRights(), $readable), $failures);
		}

		return self::valuesOf($rights, $userIds);
	}

	/**
	 * @param array<int, bool>|null $rights
	 * @param int[] $userIds
	 * @return array<int, bool|null>
	 */
	private static function valuesOf(?array $rights, array $userIds): array
	{
		$values = [];
		foreach ($userIds as $userId)
		{
			$values[$userId] = $rights[$userId] ?? null;
		}

		return $values;
	}
}
