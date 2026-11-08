<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\V3\Controller\Sync;

use Bitrix\Disk\Infrastructure\Rest\V3\Controller\Sync\ActionFilter\SyncFeatureEnabled;
use Bitrix\Disk\Infrastructure\Rest\V3\Exception\SyncAccessDeniedException;
use Bitrix\Disk\Infrastructure\Rest\V3\Exception\SyncRestException;
use Bitrix\Disk\Internal\Service\Sync\CallingApplication;
use Bitrix\Disk\Internal\Service\Sync\VisibilityReadGuard;
use Bitrix\Main\Engine\Action;
use Bitrix\Main\SystemException;
use Bitrix\Rest\V3\Controller\ActionFilter\IdempotencyFilter;
use Bitrix\Rest\V3\Controller\RestController;

/**
 * Common base of the disk.sync.* contour.
 *
 * Refusals of the contour travel as {@see SyncRestException} descendants: the inherited V3 error
 * path already turns them into the published code, status and phrase, so no action of the contour
 * builds an error answer of its own.
 */
abstract class BaseSyncController extends RestController
{
	/**
	 * Which action of the contour reads the fetch by visibility, and therefore stands behind
	 * {@see VisibilityReadGuard}.
	 *
	 * The rule is addressed and never given to the prefix disk.sync.*. capabilities.get reads nothing
	 * a user owns. content.get is left out on purpose: it is called at the rate of the number of
	 * files, so recomputing rights and comparing the snapshot on every download would both multiply
	 * the cost of a burst and be able to publish "the whole access of the user" in the middle of the
	 * initial fetch, sending the client into a reconciliation of its whole memory while it is still
	 * filling it. Its own check of rights on the identifier it is handed it does like any other method.
	 *
	 * Every controller of the contour is named here exactly once, with the actions of it that read
	 * visibility and with nothing else. A controller that is not named is refused rather than let
	 * through: a method of phases P3-P5 joins the rule by being written into this list, and forgetting
	 * to write it in has to be loud, while joining by accident has to be impossible.
	 */
	private const VISIBILITY_READING_ACTIONS = [
		Capabilities::class => [],
		Session::class => ['create'],
		Snapshot::class => ['list'],
		Changes::class => ['list'],
		State::class => ['check'],
		Content::class => [],
	];

	/**
	 * The availability switch stands before idempotency: while the contour is off, the call is
	 * refused on every path it can take, and no idempotency record is kept for a call that never ran.
	 *
	 * The idempotency filter is moved rather than rebuilt - the same instance serves the pre- and the
	 * post-chain, and the state it sets before the action has to survive to after it. Its position in
	 * the parent list is found by type, not assumed.
	 */
	protected function getDefaultPreFilters(): array
	{
		$inheritedFilters = [];
		$idempotencyFilters = [];
		foreach (parent::getDefaultPreFilters() as $filter)
		{
			if ($filter instanceof IdempotencyFilter)
			{
				$idempotencyFilters[] = $filter;
			}
			else
			{
				$inheritedFilters[] = $filter;
			}
		}

		return [
			...$inheritedFilters,
			new SyncFeatureEnabled(),
			...$idempotencyFilters,
		];
	}

	/**
	 * The guard stands here and not in processBeforeAction because processBeforeAction runs ahead of
	 * the pre-filters: the switch of the contour would not have spoken yet, and a call refused as
	 * unavailable would still have refreshed codes and written a snapshot. Here every filter has
	 * already had its say and the body of the action has not started, which is what "before any
	 * selection" means. A call the idempotency filter answers from its record never gets here, and it
	 * is right that it does not: nothing is read on that path, the answer was built by the call that
	 * did run, and refreshing codes for it would only move the snapshot of the pair without a reader.
	 *
	 * The request is built before the guard runs and not by it running later. Binding the arguments of
	 * the action is what validates the request, and a call the portal is about to refuse as malformed
	 * must not first recompute the codes of the user and move the snapshot of the pair: the comparison
	 * of that snapshot publishes what changed since the previous call, and spending it on a request
	 * that never read anything would announce the difference to a caller that gets an error instead of
	 * a page. The binder keeps what it built, so the action below is run over the same arguments.
	 *
	 * @throws SyncAccessDeniedException the pair cannot be named
	 * @throws SystemException the controller is not named in the rule of visibility
	 */
	protected function getActionResponse(Action $action)
	{
		if ($this->readsVisibility($action))
		{
			$action->getArguments();

			(new VisibilityReadGuard())->onVisibilityReadingMethod($this->resolveUserId(), $this->resolveAppId());
		}

		return parent::getActionResponse($action);
	}

	/**
	 * @throws SyncAccessDeniedException
	 */
	final protected function resolveUserId(): int
	{
		$userId = (int)$this->getCurrentUser()?->getId();
		if ($userId <= 0)
		{
			throw new SyncAccessDeniedException();
		}

		return $userId;
	}

	/**
	 * The owner of a session is the pair of a user and an application, so the application has to be
	 * named. A caller the portal cannot name at all gets no session: an unnamed pair would be one
	 * shared bucket in which any caller could invalidate the fetch of any other.
	 *
	 * @throws SyncAccessDeniedException
	 */
	final protected function resolveAppId(): string
	{
		return CallingApplication::nameOf($this->getServer()) ?? throw new SyncAccessDeniedException();
	}

	/**
	 * @throws SystemException
	 */
	private function readsVisibility(Action $action): bool
	{
		$declaredActions = self::VISIBILITY_READING_ACTIONS[static::class] ?? null;
		if ($declaredActions === null)
		{
			throw new SystemException(
				static::class . ' says nothing about reading the fetch by visibility. Name it in '
				. self::class . '::VISIBILITY_READING_ACTIONS, with the actions that read it or with none.',
			);
		}

		return in_array($action->getName(), $declaredActions, true);
	}
}
