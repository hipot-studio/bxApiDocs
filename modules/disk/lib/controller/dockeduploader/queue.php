<?php

declare(strict_types=1);

namespace Bitrix\Disk\Controller\DockedUploader;

use Bitrix\Disk\Internal\Enum\DockedUpload\ConflictPolicy;
use Bitrix\Disk\Internal\Enum\DockedUpload\ItemStatus;
use Bitrix\Disk\Internal\Enum\DockedUpload\ItemType;
use Bitrix\Disk\Internal\Service\DockedUpload\FolderTreePathAnalyzer;
use Bitrix\Disk\Internal\Service\DockedUpload\ItemMutation;
use Bitrix\Disk\Internal\Service\DockedUpload\QueueRateLimiter;
use Bitrix\Disk\Internal\Service\DockedUpload\UnexpectedErrorReporter;
use Bitrix\Disk\Internals\Engine\Controller;
use Bitrix\Disk\Public\Command\DockedUpload\CancelCommand;
use Bitrix\Disk\Public\Command\DockedUpload\CreateQueueCommand;
use Bitrix\Disk\Public\Command\DockedUpload\DismissCommand;
use Bitrix\Disk\Public\Command\DockedUpload\ResumeCommand;
use Bitrix\Disk\Public\Command\DockedUpload\RetryCommand;
use Bitrix\Disk\Public\Command\DockedUpload\ResolveConflictCommand;
use Bitrix\Main\Command\CommandInterface;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;

/**
 * Docked uploader queue mutation surface (API-01).
 *
 * Ajax-resolved as `disk.dockeduploader.queue.<action>` (namespace lower-cased,
 * action camelCase - see the SDD Errata). It is a thin adapter over the
 * Public/Command/DockedUpload/* commands: it authenticates, enforces the input
 * size/rate limits (Q-6) and translates a command Result into the JSON envelope.
 *
 * The controller extends the disk engine Controller (Authentication + Csrf +
 * HttpMethod), NOT BaseObject: a queue is not a Disk object, so there is no
 * CheckReadPermission prefilter.
 */
final class Queue extends Controller
{
	// Server-side feature kill switch for every action of this controller (#11).
	use DockedUploaderGate;

	/**
	 * Q-6 defaults. The live thresholds are configurable module options (see the
	 * getters below); these constants only supply the fallbacks so the limits stay
	 * tunable per SDD section 7 without touching configuration.php.
	 */
	/** Default cumulative per-queue item ceiling. */
	public const MAX_QUEUE_ITEMS = 1000;
	/** Default max elements in a single enqueue batch (create/createFolderTree). */
	public const MAX_BATCH_SIZE = 500;
	/** Default cumulative ceiling of a user's active (non-terminal) items. */
	public const MAX_USER_ITEMS = 5000;
	/**
	 * Default cumulative ceiling of the rows a user keeps in the item table - every element
	 * of their queues that has not expired yet, closed queues included. Four times the
	 * active one: a user may legitimately fill and finish their in-flight budget several
	 * times over a working day, while the rows of what they finished stay for the 24h TTL.
	 */
	public const MAX_USER_RETAINED_ITEMS = 20000;
	/**
	 * Default enqueue rate limit: queue ELEMENTS per window per user (a call spends what it
	 * asks the server to do, not one slot). Twice the active ceiling, so the largest legal
	 * burst - filling the whole in-flight budget in one minute, folder trees included -
	 * still fits, while the close-and-refill loop of a script no longer writes at the
	 * request rate.
	 */
	private const RATE_LIMIT_ITEMS = 10000;
	/**
	 * Default rate limit of the queue-closing actions (cancel / dismiss), in calls: a human
	 * spends units of it per session, and the cleanup after an enqueue that could not build
	 * its folders spends one for the whole pack (cancel takes the folders by itemIds).
	 */
	private const RATE_LIMIT_LIFECYCLE_CALLS = 120;
	/**
	 * Default row-mutation rate limit (updateItem / updateItems), in rows per window per user.
	 * Twice the enqueue one and four times MAX_USER_ITEMS: every enqueued element takes a few
	 * row changes on its way to a commit, and the heaviest legal burst - a takeover of a full
	 * in-flight budget, 2 x 5000 rows - still takes only half of the window.
	 */
	private const RATE_LIMIT_MUTATION_ROWS = 20000;
	private const RATE_WINDOW_SECONDS = 60;

	/** The one recognised updateItem ownerAction: hand the item back (API-01). */
	public const ACTION_RELEASE = 'release';

	/**
	 * createFolderTree path-shape caps (Q-6). RELATIVE_PATH is a 1000-char column, so
	 * a single path is bounded in total length, depth and per-segment length before it
	 * ever reaches the tree builder (a synchronous folder create per level).
	 *
	 * MAX_PATH_DEPTH is shared with create: the two surfaces nest folders by different
	 * means (a relativePath there, a parentClientId chain here) and one cap bounds both.
	 */
	private const MAX_PATH_LENGTH = 1000;
	private const MAX_PATH_DEPTH = 30;
	private const MAX_PATH_SEGMENT_LENGTH = 255;

	/** Module option names backing the Q-6 thresholds. */
	private const OPTION_MAX_BATCH_SIZE = 'docked_upload_batch_max_size';
	private const OPTION_MAX_QUEUE_ITEMS = 'docked_upload_queue_max_items';
	private const OPTION_MAX_USER_ITEMS = 'docked_upload_user_max_items';
	private const OPTION_MAX_USER_RETAINED_ITEMS = 'docked_upload_user_max_retained_items';
	private const OPTION_RATE_LIMIT_ITEMS = 'docked_upload_rate_limit_items';
	private const OPTION_RATE_LIMIT_LIFECYCLE_CALLS = 'docked_upload_rate_limit_lifecycle_calls';
	private const OPTION_RATE_LIMIT_MUTATION_ROWS = 'docked_upload_rate_limit_mutation_rows';
	private const OPTION_RATE_WINDOW_SECONDS = 'docked_upload_rate_window_seconds';

	protected function getDefaultPreFilters()
	{
		// Mutations are POST-only under Authentication + Csrf.
		return [
			new ActionFilter\Authentication(),
			new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
			new ActionFilter\Csrf(),
		];
	}

	public function createAction(array $items, CurrentUser $currentUser, ?string $conflictPolicy = null): ?array
	{
		$userId = (int)$currentUser->getId();

		if ($items === [])
		{
			$this->addError(new Error('Empty batch', 'DISK_DOCKED_EMPTY_BATCH'));

			return null;
		}

		// create is the ONLY point that inserts queue items: createFolderTree merely
		// binds the already-queued items to materialized folders (it never inserts).
		// So the cumulative per-queue ceiling is enforced here, at the sole insert
		// point, jointly with the per-batch limit: the effective cap is the smaller of
		// the two. With the defaults (batch 500 <= queue 1000) the batch limit wins -
		// which is why the old `|| count > MAX_QUEUE_ITEMS` was dead - but honoring the
		// ceiling keeps it meaningful if an admin lowers the queue option below batch.
		$insertCap = min($this->getMaxBatchSize(), $this->getMaxQueueItems());
		if (count($items) > $insertCap)
		{
			$this->addError(new Error('Batch size limit exceeded', 'DISK_DOCKED_LIMIT_BATCH'));

			return null;
		}
		if (!$this->validateParentChainDepth($items))
		{
			$this->addError(new Error('Path limit exceeded', 'DISK_DOCKED_LIMIT_PATH'));

			return null;
		}
		// The enqueue budget is spent per ELEMENT: a batch of 500 is 500 slots, not one.
		if ($this->hitEnqueueRateLimit($userId, count($items)))
		{
			$this->addError(new Error('Rate limit exceeded', 'DISK_DOCKED_RATE_LIMIT'));

			return null;
		}

		return $this->runCommand(new CreateQueueCommand(
			userId: $userId,
			items: $this->withClientIds($items),
			// Unlike the status above, an unreadable policy stays a soft degradation: it
			// falls back to the safe default (skip, never replace_all), which is what keeps
			// a client naming a policy this server does not know yet working.
			conflictPolicy: $conflictPolicy !== null ? ConflictPolicy::tryFrom($conflictPolicy) : null,
			userMaxActiveItems: $this->getMaxUserItems(),
			userMaxRetainedItems: $this->getMaxUserRetainedItems(),
		));
	}

	/**
	 * @param string|null $knownStaleClientId additive API-01 field: the owner the
	 *   caller believes is dead (its BroadcastChannel peer stopped answering), which
	 *   widens the ownership CAS to that one owner. The client's verdict is final:
	 *   OWNER_HEARTBEAT_AT is not part of the acquire guard, so naming a LIVE tab
	 *   takes the row away from it mid-transfer. The server arbitrates the user
	 *   boundary and concurrency here, not liveness - the only guard against a false
	 *   death detect is the client-side ownerDeadThreshold.
	 * @param string|null $ownerAction additive API-01 field: the only recognised value
	 *   is ACTION_RELEASE (graceful hand-back of the item on tab close - reclassify
	 *   and unown, never resume a transfer); anything else means "no ownership
	 *   action". It is deliberately a named action rather than a boolean flag: an
	 *   action parameter is bound in weak mode against an is_scalar() check
	 *   ({@see \Bitrix\Main\Engine\AutoWire\TypeDeclarationChecker}), so a bool would
	 *   read the form-encoded string "false" - what BX.ajax puts on the wire for a JS
	 *   false - as true and silently release an item that was meant to commit.
	 * @param array|null $transportError additive API-01 field: the terminal transport
	 *   failure the client reports for this item, as `['code' => string]`. It is a
	 *   prompt to re-check, not a verdict - the server re-verifies the quota itself
	 *   and only then stops the queue.
	 */
	public function updateItemAction(
		int $itemId,
		CurrentUser $currentUser,
		?string $tempToken = null,
		?string $status = null,
		?string $ownerClientId = null,
		?string $knownStaleClientId = null,
		?string $ownerAction = null,
		?array $bizProcParameters = null,
		?array $transportError = null
	): ?array
	{
		$userId = (int)$currentUser->getId();

		// Not the enqueue throttle (R6/Q-6): a folder upload performs one commit-transition
		// per file, so capping it by the enqueue budget would cap folder uploads. The call
		// spends one row of the row-mutation budget instead, shared with updateItems. The
		// refusal is fail-closed; the client retries the commit with a backoff.

		$requestedStatus = self::parseRequestedStatus($status);
		if ($requestedStatus === false)
		{
			$this->addError(self::unreadableStatusError());

			return null;
		}

		if ($this->hitMutationRateLimit($userId, 1))
		{
			$this->addError(new Error('Rate limit exceeded', 'DISK_DOCKED_RATE_LIMIT'));

			return null;
		}

		return $this->runCommand(new \Bitrix\Disk\Public\Command\DockedUpload\UpdateItemCommand(
			userId: $userId,
			itemId: $itemId,
			tempToken: $tempToken,
			status: $requestedStatus,
			ownerClientId: $ownerClientId,
			knownStaleClientId: $knownStaleClientId,
			// Strict comparison is the whole safety property: only the exact action name
			// releases, so no unrecognised value can ever be coerced into intent.
			releaseOwnership: $ownerAction === self::ACTION_RELEASE,
			bizProcParameters: $bizProcParameters ?? [],
			// #14: this is a public endpoint with no server-side signature check, so the
			// "parameters are signed" flag must NEVER be taken from the client - a forged
			// true would switch off BizProcDocument::validateParameters. Server validation
			// stays on unconditionally; the flag is hardcoded false.
			bizProcParametersAreSigned: false,
			transportError: $transportError,
		));
	}

	/**
	 * Batch form of updateItem (API-01 updateItems, CONTRACT-API-06): the same
	 * per-row mutation surface, one request. Every row is parsed exactly as
	 * updateItemAction parses its input - the wire is form-encoded, so each scalar
	 * arrives as a string: ItemStatus::tryFrom on the status, the strict
	 * ownerAction comparison (a bogus value, "false" included, means "no ownership
	 * action") and the hardcoded false signed-flag (#14) all behave identically.
	 *
	 * bizProcParameters is read from the ROW and never from the envelope: each row
	 * carries its own workflow parameter set, applied to its own commit alone. An
	 * envelope-wide set must not be introduced - a batch legally mixes elements of
	 * different (storage, scenario) pairs (a folder upload spanning two storages
	 * does), so a shared set would glue foreign parameters onto them.
	 *
	 * Spends the row-mutation budget shared with updateItem (see the comment there), one
	 * row per row of the batch, once the batch is parsed - a request refused on its shape
	 * costs nothing. A keepalive volley ({@see ItemMutation::isKeepaliveBatch()}) is free:
	 * it changes no status and must keep beating at the ceiling. The refusal answers the
	 * whole request and changes no row.
	 *
	 * @param array<int, mixed> $items
	 */
	public function updateItemsAction(array $items, CurrentUser $currentUser): ?array
	{
		if ($items === [])
		{
			$this->addError(new Error('Empty batch', 'DISK_DOCKED_EMPTY_BATCH'));

			return null;
		}
		if (count($items) > $this->getMaxBatchSize())
		{
			$this->addError(new Error('Batch size limit exceeded', 'DISK_DOCKED_LIMIT_BATCH'));

			return null;
		}

		$mutations = [];
		$seenItemIds = [];
		foreach ($items as $row)
		{
			$itemId = is_array($row) && is_scalar($row['itemId'] ?? null) ? (int)$row['itemId'] : 0;
			if ($itemId <= 0)
			{
				$this->addError(new Error('Invalid itemId in batch', 'DISK_DOCKED_BATCH_INVALID_ITEM'));

				return null;
			}
			if (isset($seenItemIds[$itemId]))
			{
				// A repeated id would break the one-row-per-item answer map.
				$this->addError(new Error('Duplicate itemId in batch', 'DISK_DOCKED_BATCH_DUPLICATE_ITEM'));

				return null;
			}
			$seenItemIds[$itemId] = true;

			$requestedStatus = self::parseRequestedStatus($row['status'] ?? null);
			if ($requestedStatus === false)
			{
				// Refuses the whole request, the way an unreadable itemId already does:
				// a row nobody can act on must not travel as "no status change".
				$this->addError(self::unreadableStatusError());

				return null;
			}

			$mutations[] = new ItemMutation(
				itemId: $itemId,
				tempToken: self::stringOrNull($row['tempToken'] ?? null),
				status: $requestedStatus,
				ownerClientId: self::stringOrNull($row['ownerClientId'] ?? null),
				knownStaleClientId: self::stringOrNull($row['knownStaleClientId'] ?? null),
				releaseOwnership: ($row['ownerAction'] ?? null) === self::ACTION_RELEASE,
				bizProcParameters: is_array($row['bizProcParameters'] ?? null) ? $row['bizProcParameters'] : [],
				bizProcParametersAreSigned: false,
				transportError: is_array($row['transportError'] ?? null) ? $row['transportError'] : null,
			);
		}

		$userId = (int)$currentUser->getId();
		if (!ItemMutation::isKeepaliveBatch($mutations) && $this->hitMutationRateLimit($userId, count($mutations)))
		{
			$this->addError(new Error('Rate limit exceeded', 'DISK_DOCKED_RATE_LIMIT'));

			return null;
		}

		return $this->runCommand(new \Bitrix\Disk\Public\Command\DockedUpload\UpdateItemsCommand(
			userId: $userId,
			mutations: $mutations,
		));
	}

	private static function stringOrNull(mixed $value): ?string
	{
		return is_scalar($value) ? (string)$value : null;
	}

	/**
	 * Keeps "no status sent" apart from "a status was sent and cannot be read". Merged,
	 * the second becomes the first - the mutation applier treats a null request as "no
	 * status change" and answers 200 with the untouched row, so the commit-transition
	 * the client believed it made silently never happened. A non-scalar value is a sent
	 * status as well, so it reads as unreadable and never as "nothing was sent" - the
	 * batch surface takes rows straight off the wire, where an array can arrive.
	 *
	 * @return ItemStatus|null|false false for an unreadable value, null when nothing was sent
	 */
	private static function parseRequestedStatus(mixed $status): ItemStatus|null|false
	{
		if ($status === null)
		{
			return null;
		}
		if (!is_scalar($status))
		{
			return false;
		}

		return ItemStatus::tryFrom((string)$status) ?? false;
	}

	/**
	 * Reuses the transition code the applier already refuses illegal transitions with: it
	 * is terminal on the client, so an unreadable status is not retried to the escalation.
	 */
	private static function unreadableStatusError(): Error
	{
		return new Error('Unknown item status', 'DISK_DOCKED_ITEM_TRANSITION_INVALID');
	}

	/**
	 * @param array|null $itemIds additive CONTRACT-API-06 field: the batch form of the
	 *   single-item branch - one call closes every named sub-tree instead of one call per
	 *   folder, which is what the cleanup after a refused createFolderTree needs (it has
	 *   up to a folder per unbuilt node to close and one lifecycle budget to do it in).
	 *   Mutually exclusive with itemId AND with queueId: a queueId already means "cancel
	 *   the whole queue", so a combined address is refused rather than guessed - a target
	 *   read the wrong way closes rows the user never pointed at. The values arrive
	 *   form-encoded as strings; non-scalar entries are dropped rather than coerced.
	 */
	public function cancelAction(
		CurrentUser $currentUser,
		?int $queueId = null,
		?int $itemId = null,
		?array $itemIds = null
	): ?array
	{
		if ($itemIds !== null && ($itemId !== null || $queueId !== null))
		{
			$this->addError(new Error('Ambiguous cancel target', 'DISK_DOCKED_CANCEL_TARGET_AMBIGUOUS'));

			return null;
		}
		if ($queueId === null && $itemId === null && $itemIds === null)
		{
			$this->addError(new Error('queueId, itemId or itemIds is required', 'DISK_DOCKED_MISSING_TARGET'));

			return null;
		}
		if ($itemIds !== null && count($itemIds) > $this->getMaxBatchSize())
		{
			$this->addError(new Error('Batch size limit exceeded', 'DISK_DOCKED_LIMIT_BATCH'));

			return null;
		}

		$userId = (int)$currentUser->getId();
		// A cancel that drains a queue closes it, which frees the whole active-item budget
		// of its rows at once - so it is throttled just like the enqueue that filled it.
		// The batch form spends the same single call: by construction it closes sub-trees
		// of ONE queue, so a pack is no more of a give-back than the queueId form is.
		if ($this->hitLifecycleRateLimit($userId))
		{
			$this->addError(new Error('Rate limit exceeded', 'DISK_DOCKED_RATE_LIMIT'));

			return null;
		}

		return $this->runCommand(new CancelCommand(
			userId: $userId,
			queueId: $queueId,
			itemId: $itemId,
			itemIds: $itemIds !== null
				? array_map(static fn($id) => is_scalar($id) ? (int)$id : 0, $itemIds)
				: null,
		));
	}

	/**
	 * Confirmed close of the whole widget (SC-015). It takes NO target: the request is
	 * addressed to the widget and applies to every queue of the user, so a queueId sent by
	 * an older client is simply not read - the close is global either way, which is the
	 * point of the rev. 3 contract. The confirmation itself belongs to the client
	 * (AC-080/087); the server neither asks it nor refuses a close over a running transfer.
	 */
	public function dismissAction(CurrentUser $currentUser): ?array
	{
		$userId = (int)$currentUser->getId();
		// The close empties the active-item budget of the whole widget in one call, so it
		// carries the same throttle as the cancel it generalises.
		if ($this->hitLifecycleRateLimit($userId))
		{
			$this->addError(new Error('Rate limit exceeded', 'DISK_DOCKED_RATE_LIMIT'));

			return null;
		}

		return $this->runCommand(new DismissCommand(
			userId: $userId,
		));
	}

	/**
	 * Restores a queue stopped for lack of space (API-07, AC-054/094). An explicit user
	 * action only: nothing on the server calls it, and it re-checks no quota on the way in.
	 */
	public function resumeAction(int $queueId, CurrentUser $currentUser): ?array
	{
		return $this->runCommand(new ResumeCommand(
			userId: (int)$currentUser->getId(),
			queueId: $queueId,
		));
	}

	/**
	 * $queueId narrows a scope:failed batch to one queue - the retry-all affordance is
	 * rendered per queue (AC-076). Omitted, the batch spans every active queue.
	 *
	 * @param array|null $itemIds additive CONTRACT-API-06 field: the batch form of the
	 *   single-item branch - the same gates per named id, the unfit ids silently
	 *   skipped. Mutually exclusive with itemId/scope (the command refuses combined
	 *   addressing). The values arrive form-encoded as strings; non-scalar entries are
	 *   dropped rather than coerced.
	 */
	public function retryAction(
		CurrentUser $currentUser,
		?int $itemId = null,
		?string $scope = null,
		?int $queueId = null,
		?array $itemIds = null
	): ?array
	{
		if ($itemIds !== null && count($itemIds) > $this->getMaxBatchSize())
		{
			$this->addError(new Error('Batch size limit exceeded', 'DISK_DOCKED_LIMIT_BATCH'));

			return null;
		}

		return $this->runCommand(new RetryCommand(
			userId: (int)$currentUser->getId(),
			itemId: $itemId,
			scope: $scope,
			queueId: $queueId,
			itemIds: $itemIds !== null
				? array_map(static fn($id) => is_scalar($id) ? (int)$id : 0, $itemIds)
				: null,
		));
	}

	/**
	 * @param array|null $bizProcParameters additive SC-012 rework #2 field: the workflow
	 *   parameters collected on the client for a `replace` whose commit runs the Edit-event
	 *   autostart (same flat composite-key shape as updateItem). Absent means the prior
	 *   behaviour (commit with no parameters).
	 */
	public function resolveConflictAction(
		int $itemId,
		string $action,
		CurrentUser $currentUser,
		?array $bizProcParameters = null
	): ?array
	{
		return $this->runCommand(new ResolveConflictCommand(
			userId: (int)$currentUser->getId(),
			itemId: $itemId,
			action: $action,
			bizProcParameters: $bizProcParameters ?? [],
			// #14: this is a public endpoint with no server-side signature check, so the
			// "parameters are signed" flag must NEVER be taken from the client - a forged
			// true would switch off BizProcDocument::validateParameters. Server validation
			// stays on unconditionally; the flag is hardcoded false (same rule as updateItem).
			bizProcParametersAreSigned: false,
		));
	}

	/**
	 * Restores all actionable queues of the current user (API-02). Read-path,
	 * no mutations: temp reconciliation is computed in memory only.
	 */
	public function restoreAction(CurrentUser $currentUser): array
	{
		$provider = \Bitrix\Main\DI\ServiceLocator::getInstance()->get('disk.dockedUpload.queueRestoreProvider');
		$queues = $provider->getRestorableQueues((int)$currentUser->getId());

		return [
			'queues' => array_map(static fn($queue) => $queue->toArray(), $queues),
		];
	}

	/**
	 * Batch-creates the folder tree for a docked folder upload and binds the
	 * queued file items to it (API-04).
	 *
	 * @param string[] $relativePaths
	 */
	public function createFolderTreeAction(
		int $queueId,
		int $rootFolderId,
		int $folderItemId,
		array $relativePaths,
		CurrentUser $currentUser,
		?string $conflictPolicy = null
	): ?array
	{
		$userId = (int)$currentUser->getId();

		if (!$this->validatePathShapes($relativePaths))
		{
			$this->addError(new Error('Path limit exceeded', 'DISK_DOCKED_LIMIT_PATH'));

			return null;
		}
		// The cost of a folder-tree request is the number of distinct nodes it
		// materialises (a synchronous folder create per level), not the number of
		// paths - a single deep path can create many folders - so the batch ceiling is
		// enforced against the unique node count.
		$nodeCount = FolderTreePathAnalyzer::countUniqueNodes($relativePaths);
		if ($nodeCount > $this->getMaxBatchSize())
		{
			$this->addError(new Error('Batch size limit exceeded', 'DISK_DOCKED_LIMIT_BATCH'));

			return null;
		}
		// createFolderTree is an enqueue-class operation (it grows the materialized
		// tree), so it keeps the anti-flood throttle; commit-transitions do not. It
		// spends the same node count the ceiling above is taken over.
		if ($this->hitEnqueueRateLimit($userId, $nodeCount))
		{
			$this->addError(new Error('Rate limit exceeded', 'DISK_DOCKED_RATE_LIMIT'));

			return null;
		}

		return $this->runCommand(new \Bitrix\Disk\Public\Command\DockedUpload\CreateFolderTreeCommand(
			userId: $userId,
			queueId: $queueId,
			rootFolderId: $rootFolderId,
			folderItemId: $folderItemId,
			relativePaths: $relativePaths,
			// Same soft degradation to the safe default as createAction (see there).
			conflictPolicy: $conflictPolicy !== null ? ConflictPolicy::tryFrom($conflictPolicy) : null,
		));
	}

	/**
	 * Contract extension #2: each enqueued element may carry two optional stable
	 * client identifiers - `clientId` (unique within the request, present on folders
	 * and possibly on every element) and `parentClientId` (the parent folder's
	 * clientId for a nested file/folder, null/absent for roots). The controller only
	 * parses them and forwards them verbatim to CreateQueueCommand under the same
	 * keys; the two-phase insert and PARENT_ITEM_ID wiring happen inside the command.
	 *
	 * Missing fields default to null so an older client that never sends them keeps
	 * working (backward compatibility); provided values are passed through unchanged.
	 *
	 * @param array<int, mixed> $items
	 * @return array<int, mixed>
	 */
	private function withClientIds(array $items): array
	{
		foreach ($items as &$item)
		{
			if (!is_array($item))
			{
				continue;
			}

			$item['clientId'] = $item['clientId'] ?? null;
			$item['parentClientId'] = $item['parentClientId'] ?? null;
		}
		unset($item);

		return $items;
	}

	/**
	 * Validates the shape of every createFolderTree path (Q-6 surface): total length,
	 * depth and per-segment length. One malformed path (thousands of segments) would
	 * otherwise create a folder per level synchronously, and the stepper selection
	 * would still see it as a single "small" path.
	 *
	 * @param string[] $relativePaths
	 */
	private function validatePathShapes(array $relativePaths): bool
	{
		foreach ($relativePaths as $path)
		{
			$path = (string)$path;
			if (mb_strlen($path) > self::MAX_PATH_LENGTH)
			{
				return false;
			}

			$segments = FolderTreePathAnalyzer::segments($path);
			if (count($segments) > self::MAX_PATH_DEPTH)
			{
				return false;
			}

			foreach ($segments as $segment)
			{
				if (mb_strlen($segment) > self::MAX_PATH_SEGMENT_LENGTH)
				{
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Bounds how deep one create may nest folder-items through parentClientId, under the
	 * very cap validatePathShapes() applies to a createFolderTree relativePath: both
	 * measure the same thing - folder levels one enqueue asks for - so a tree legal on one
	 * surface stays legal on the other. Without it a single batch (500 elements) could
	 * enqueue a 499-folder chain, and every later mutation of that queue would pay a
	 * quadratic roll-up in {@see \Bitrix\Disk\Internal\Dto\DockedUpload\QueueDto} while
	 * updateItem/updateItems carry no throttle of their own.
	 *
	 * The batch alone is the whole tree: create opens a fresh queue every time and is the
	 * sole writer of PARENT_ITEM_ID, so a chain can never span two requests and no stored
	 * element has to be read to measure one.
	 *
	 * Only folder elements are measured, because only they can carry a chain: a
	 * parentClientId is resolved against the folder clientIds of the same batch
	 * ({@see \Bitrix\Disk\Public\Command\DockedUpload\CreateQueueCommand::insertFolderItems()}),
	 * so a leaf never extends one. The layering below mirrors that command's: a folder
	 * whose parent is missing or sits in a cycle is unreachable from any root and is
	 * inserted as a root there, so it is left unmeasured here rather than refused - a
	 * malformed batch is answered by the checks that own it, never by a hang.
	 *
	 * A folder clientId may name one folder only, and a batch that repeats one is refused
	 * before the depth is measured at all. The measurement reads the keys as a set, while
	 * the command resolves parentClientId against a clientId => inserted id map it rewrites
	 * row by row: under a repeated key every later folder is nested under the previously
	 * inserted one, so a batch measured as two levels is stored as a chain as long as the
	 * batch itself. A repeated key is broken either way - that map keeps only the last
	 * folder carrying it - so it is answered here instead of resolved silently.
	 *
	 * @param array<int, mixed> $items
	 */
	private function validateParentChainDepth(array $items): bool
	{
		$clientIds = [];
		$parentClientIds = [];
		$folderClientIds = [];
		foreach ($items as $item)
		{
			$rawType = is_array($item) && is_scalar($item['type'] ?? null) ? (string)$item['type'] : '';
			if (ItemType::tryFrom($rawType) !== ItemType::Folder)
			{
				continue;
			}

			$clientId = self::normalizeClientKey($item['clientId'] ?? null);
			if ($clientId !== null && isset($folderClientIds[$clientId]))
			{
				return false;
			}

			$clientIds[] = $clientId;
			$parentClientIds[] = self::normalizeClientKey($item['parentClientId'] ?? null);
			if ($clientId !== null)
			{
				$folderClientIds[$clientId] = true;
			}
		}

		// Roots are the folders with no in-batch folder parent; every other folder is
		// indexed under the parent it names, so each edge is walked exactly once.
		$childIndexesByParent = [];
		$depthByIndex = [];
		$pending = [];
		foreach ($parentClientIds as $index => $parentClientId)
		{
			if ($parentClientId !== null && isset($folderClientIds[$parentClientId]))
			{
				$childIndexesByParent[$parentClientId][] = $index;
			}
			else
			{
				$depthByIndex[$index] = 1;
				$pending[] = $index;
			}
		}

		// Breadth-first over the layers: a folder is measured once (the memo is the guard
		// that makes a cycle terminate) and its depth is its parent's plus one.
		for ($head = 0; isset($pending[$head]); $head++)
		{
			$index = $pending[$head];
			$clientId = $clientIds[$index];
			if ($clientId === null)
			{
				continue;
			}

			$childDepth = $depthByIndex[$index] + 1;
			foreach ($childIndexesByParent[$clientId] ?? [] as $childIndex)
			{
				if (isset($depthByIndex[$childIndex]))
				{
					continue;
				}
				if ($childDepth > self::MAX_PATH_DEPTH)
				{
					return false;
				}

				$depthByIndex[$childIndex] = $childDepth;
				$pending[] = $childIndex;
			}
		}

		return true;
	}

	/**
	 * The client tree key as CreateQueueCommand will read it: anything but a non-empty
	 * string or an int is no key at all, so an element carrying one cannot be named as a
	 * parent there and must not be measured as one here.
	 */
	private static function normalizeClientKey(mixed $value): ?string
	{
		if (!is_string($value) && !is_int($value))
		{
			return null;
		}

		return $value === '' ? null : (string)$value;
	}

	/**
	 * Runs a command and folds its Result into the controller error envelope.
	 */
	private function runCommand(CommandInterface $command): ?array
	{
		try
		{
			$result = $command->run();
		}
		catch (\Throwable $exception)
		{
			$this->addError(UnexpectedErrorReporter::report($exception, 'controller'));

			return null;
		}

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData();
	}

	/**
	 * Enqueue throttle - create and createFolderTree (Q-6), spent in queue elements:
	 * $cost is what the call asks the server to enqueue, so a batch of 500 costs 500.
	 * Commit-transition/ownership updateItem calls do not spend it, so folder uploads of
	 * many files are not capped by it; they spend the row-mutation budget instead
	 * ({@see self::hitMutationRateLimit()}).
	 */
	private function hitEnqueueRateLimit(int $userId, int $cost): bool
	{
		return $this->hitRateLimit(
			QueueRateLimiter::BUCKET_ENQUEUE,
			$userId,
			$cost,
			$this->getRateLimitItems(),
		);
	}

	/**
	 * Throttle of the actions that CLOSE queues - cancel and dismiss (Q-6), spent in
	 * calls. They free the active-item budget of every row they close while freeing no
	 * row, so without a throttle of their own they turn the enqueue ceiling into a loop.
	 */
	private function hitLifecycleRateLimit(int $userId): bool
	{
		return $this->hitRateLimit(
			QueueRateLimiter::BUCKET_LIFECYCLE,
			$userId,
			1,
			$this->getRateLimitLifecycleCalls(),
		);
	}

	/**
	 * Row-mutation throttle - updateItem and updateItems share it, spent in rows: $rows is
	 * the number of queue rows the call asks to change. A keepalive volley never reaches
	 * it (updateItems skips the call), so heartbeats keep the rows owned at the ceiling.
	 */
	private function hitMutationRateLimit(int $userId, int $rows): bool
	{
		return $this->hitRateLimit(
			QueueRateLimiter::BUCKET_MUTATION,
			$userId,
			$rows,
			$this->getRateLimitMutationRows(),
		);
	}

	/**
	 * Per-user sliding window over the persistent storage ({@see QueueRateLimiter}),
	 * NOT over the PHP session: the session carrying this request is not the one
	 * carrying the authorization, so a session-bound window handed a fresh budget to
	 * anyone willing to drop a cookie.
	 *
	 * A call the limiter cannot account for - no lock, unreadable or unwritable storage -
	 * is refused rather than let through: the budget is only a bound if being unable to
	 * measure it costs the caller, and the storage is the database the queue itself
	 * lives in.
	 */
	private function hitRateLimit(string $bucket, int $userId, int $cost, int $limit): bool
	{
		/** @var QueueRateLimiter $limiter */
		$limiter = ServiceLocator::getInstance()->get('disk.dockedUpload.rateLimiter');

		return !$limiter->tryConsume($bucket, $userId, $cost, $limit, $this->getRateWindowSeconds());
	}

	/** Max elements in a single enqueue batch (Q-6 option). */
	private function getMaxBatchSize(): int
	{
		return max(1, (int)Option::get('disk', self::OPTION_MAX_BATCH_SIZE, (string)self::MAX_BATCH_SIZE));
	}

	/** Cumulative per-queue item ceiling (Q-6 option). */
	private function getMaxQueueItems(): int
	{
		return max(1, (int)Option::get('disk', self::OPTION_MAX_QUEUE_ITEMS, (string)self::MAX_QUEUE_ITEMS));
	}

	/** Cumulative per-user active-item ceiling (Q-6 option). */
	private function getMaxUserItems(): int
	{
		return max(1, (int)Option::get('disk', self::OPTION_MAX_USER_ITEMS, (string)self::MAX_USER_ITEMS));
	}

	/** Cumulative per-user retained-row ceiling (Q-6 option). */
	private function getMaxUserRetainedItems(): int
	{
		return max(1, (int)Option::get(
			'disk',
			self::OPTION_MAX_USER_RETAINED_ITEMS,
			(string)self::MAX_USER_RETAINED_ITEMS,
		));
	}

	/** Enqueue rate limit: max queue elements per window per user (Q-6 option). */
	private function getRateLimitItems(): int
	{
		return max(1, (int)Option::get('disk', self::OPTION_RATE_LIMIT_ITEMS, (string)self::RATE_LIMIT_ITEMS));
	}

	/** Queue-closing rate limit: max cancel/dismiss calls per window per user (Q-6 option). */
	private function getRateLimitLifecycleCalls(): int
	{
		return max(1, (int)Option::get(
			'disk',
			self::OPTION_RATE_LIMIT_LIFECYCLE_CALLS,
			(string)self::RATE_LIMIT_LIFECYCLE_CALLS,
		));
	}

	/**
	 * Row-mutation rate limit: max updateItem/updateItems rows per window per user (option).
	 * A value that is not a positive integer falls back to the default: clamping it to one row
	 * would refuse nearly every commit, and the client retries a refusal silently.
	 */
	private function getRateLimitMutationRows(): int
	{
		$value = Option::get('disk', self::OPTION_RATE_LIMIT_MUTATION_ROWS, (string)self::RATE_LIMIT_MUTATION_ROWS);

		return preg_match('/^[0-9]+$/D', $value) === 1 && (int)$value > 0
			? (int)$value
			: self::RATE_LIMIT_MUTATION_ROWS
		;
	}

	/** Rate-limit window in seconds (Q-6 option). */
	private function getRateWindowSeconds(): int
	{
		return max(1, (int)Option::get('disk', self::OPTION_RATE_WINDOW_SECONDS, (string)self::RATE_WINDOW_SECONDS));
	}
}
