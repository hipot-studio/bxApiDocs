<?php

namespace Bitrix\StaffTrack\Public\Provider;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\StaffTrack\Access\Model\OfficeModel;
use Bitrix\StaffTrack\Access\OfficeAccessController;
use Bitrix\StaffTrack\Access\OfficeAction;
use Bitrix\StaffTrack\Dictionary\OfficeEmployeeErrorCode;
use Bitrix\StaffTrack\Dictionary\OfficeErrorCode;
use Bitrix\StaffTrack\Internal\Integration\HumanResources\SelectorEntityNormalizer;
use Bitrix\StaffTrack\Internal\Repository\OfficeRepository;
use Bitrix\StaffTrack\Internal\Service\Office\StaffPlanService;
use Bitrix\StaffTrack\Item\Collection\UserCollection;
use Bitrix\StaffTrack\Item\OfficeStaffPlan;
use Bitrix\StaffTrack\Item\OfficeStaffSelection;
use Bitrix\StaffTrack\Provider\UserProvider;

/**
 * The staff a new office would get from the codes of the selector, shown before the office is created:
 * how many people it holds and how many of them would be moved from another office (DTO-01), and the
 * movers themselves page by page (DTO-02). Given an office, the same is shown for an edit of its staff:
 * the plan is relative to that office, and the removed are echoed while they are still on it.
 *
 * The people are picked from the whole structure: whoever may create or edit an office may staff it with
 * anyone.
 */
class OfficeStaffPreviewProvider
{
	private SelectorEntityNormalizer $selectorEntityNormalizer;
	private StaffPlanService $staffPlanService;
	private OfficeRepository $officeRepository;

	public function __construct(
		?SelectorEntityNormalizer $selectorEntityNormalizer = null,
		?StaffPlanService $staffPlanService = null,
		?OfficeRepository $officeRepository = null,
	)
	{
		$this->selectorEntityNormalizer = $selectorEntityNormalizer ?? new SelectorEntityNormalizer();
		$this->staffPlanService = $staffPlanService ?? new StaffPlanService();
		$this->officeRepository = $officeRepository ?? new OfficeRepository();
	}

	/**
	 * @param int[] $excludedUserIds movers the caller took off the staff: they change the echo alone
	 * @param int $officeId the edited office, 0 for a new one
	 * @param int[] $removedUserIds taken off the edited office; ignored for a new one
	 * @return Result data of a success: DTO-01, see {@see self::buildPreview()}
	 */
	public function preview(
		int $userId,
		array $entities,
		array $excludedUserIds = [],
		int $officeId = 0,
		array $removedUserIds = [],
	): Result
	{
		$planResult = $this->plan($userId, $entities, $officeId, $removedUserIds);
		if (!$planResult->isSuccess())
		{
			return $planResult;
		}

		return (new Result())->setData($this->buildPreview($planResult->getData()['plan'], $excludedUserIds));
	}

	/**
	 * The plan is built anew for every page: nothing is kept between the pages, and the fingerprint in
	 * the header is what tells the caller the pages belong to one plan.
	 *
	 * @param int[] $excludedUserIds movers the caller took off the staff: they change the echo alone
	 * @param int $officeId the edited office, 0 for a new one
	 * @param int[] $removedUserIds taken off the edited office; ignored for a new one
	 * @return Result data of a success: DTO-02, see {@see self::buildMoversPage()}
	 */
	public function previewMovers(
		int $userId,
		array $entities,
		array $excludedUserIds,
		int $offset,
		int $limit,
		int $officeId = 0,
		array $removedUserIds = [],
	): Result
	{
		$planResult = $this->plan($userId, $entities, $officeId, $removedUserIds);
		if (!$planResult->isSuccess())
		{
			return $planResult;
		}

		return (new Result())->setData(
			$this->buildMoversPage($planResult->getData()['plan'], $excludedUserIds, $offset, $limit),
		);
	}

	/**
	 * The raw codes are counted before the parse, and a selection with any code the module cannot take
	 * is refused whole: nothing the caller asked for is dropped without a word.
	 *
	 * @return Result data of a success: {selection: OfficeStaffSelection}
	 */
	public function parseSelection(array $entities): Result
	{
		$result = new Result();

		if (count($entities) > SelectorEntityNormalizer::MAX_SELECTOR_ENTITIES)
		{
			return $result->addError(
				new Error('Too many codes of the selector', OfficeEmployeeErrorCode::TOO_MANY_CODES),
			);
		}

		$selection = $this->selectorEntityNormalizer->parseStrict($entities);
		if ($selection->hasUnsupportedCodes())
		{
			return $result->addError($this->getUnsupportedCodeError($selection));
		}

		return $result->setData(['selection' => $selection]);
	}

	/**
	 * DTO-01. Neither names nor offices nor avatars are read: the movers are listed page by page apart.
	 * The excluded do not change the counts or the fingerprint, and only those of them who are movers
	 * of the plan are echoed back. The limit is the one of the added.
	 *
	 * @param int[] $excludedUserIds
	 * @return array{
	 *     total: int,
	 *     limit: int,
	 *     addedCount: int,
	 *     moversCount: int,
	 *     movesFingerprint: string,
	 *     excludedUserIds: int[],
	 *     removedUserIds: int[],
	 * }
	 */
	public function buildPreview(OfficeStaffPlan $plan, array $excludedUserIds = []): array
	{
		$excludedMovers = array_keys(
			array_intersect_key($plan->moves, array_flip(array_map('intval', $excludedUserIds))),
		);
		sort($excludedMovers);

		$removedMembers = $plan->removedMemberIds;
		sort($removedMembers);

		return [
			'total' => $plan->total,
			'limit' => StaffPlanService::STAFF_LIMIT,
			'addedCount' => count($plan->addedUserIds),
			'moversCount' => count($plan->moves),
			'movesFingerprint' => $plan->fingerprint,
			'excludedUserIds' => $excludedMovers,
			'removedUserIds' => $removedMembers,
		];
	}

	/**
	 * DTO-02: the header of DTO-01 and one page of the movers ordered by name, then by identifier. The
	 * names of all the movers are read to order them, while the records and the offices are read for
	 * the page alone. The excluded stay on the pages: a page depends on the plan and the navigation only.
	 *
	 * @param int[] $excludedUserIds
	 * @return array{
	 *     total: int,
	 *     limit: int,
	 *     addedCount: int,
	 *     moversCount: int,
	 *     movesFingerprint: string,
	 *     excludedUserIds: int[],
	 *     removedUserIds: int[],
	 *     items: array<int, array{
	 *         userId: int,
	 *         name: string,
	 *         avatar: string|null,
	 *         fromOfficeId: int,
	 *         fromOfficeTitle: string,
	 *     }>,
	 *     hasMore: bool,
	 * }
	 */
	public function buildMoversPage(OfficeStaffPlan $plan, array $excludedUserIds, int $offset, int $limit): array
	{
		$header = $this->buildPreview($plan, $excludedUserIds);
		$offset = max(0, $offset);

		$items = [];
		if ($plan->moves !== [])
		{
			$names = $this->loadNames(array_keys($plan->moves));
			$pageUserIds = array_slice($this->orderByName($plan->moves, $names), $offset, max(0, $limit));
			$items = $this->buildMovers($plan->moves, $pageUserIds, $names);
		}

		return $header + [
			'items' => $items,
			'hasMore' => $offset + count($items) < $header['moversCount'],
		];
	}

	protected function hasAccess(int $userId, string $action, int $officeId): bool
	{
		return OfficeAccessController::getInstance($userId)
			->check($action, OfficeModel::createFromId($officeId))
		;
	}

	/**
	 * @param int[] $userIds
	 * @return array<int, string> user id => formatted name
	 */
	protected function loadNames(array $userIds): array
	{
		return UserProvider::getInstance()->getNames($userIds);
	}

	/**
	 * @param int[] $userIds
	 */
	protected function loadUsers(array $userIds): UserCollection
	{
		return UserProvider::getInstance()->getUsers($userIds);
	}

	/**
	 * The right first, the codes second and the plan last, each refusing before the next one is asked.
	 * An edit may come without codes: its removals alone are a plan too.
	 *
	 * @param int[] $removedUserIds
	 * @return Result data of a success: {plan: OfficeStaffPlan}
	 */
	private function plan(int $userId, array $entities, int $officeId, array $removedUserIds): Result
	{
		$result = new Result();

		$isEdit = $officeId > 0;
		$hasAccess = $isEdit
			? $this->hasAccess($userId, OfficeAction::UPDATE, $officeId)
			: $this->hasAccess($userId, OfficeAction::ADD, 0)
		;

		if (!$hasAccess)
		{
			return $result->addError(
				new Error('Access to the office directory denied', OfficeErrorCode::ACCESS_DENIED),
			);
		}

		$selectionResult = $this->parseSelection($entities);
		if (!$selectionResult->isSuccess())
		{
			return $result->addErrors($selectionResult->getErrors());
		}

		$selection = $selectionResult->getData()['selection'];

		if ($isEdit)
		{
			return $this->staffPlanService->buildForOffice($selection, $officeId, $removedUserIds);
		}

		return $this->staffPlanService->build($selection);
	}

	/**
	 * @param array<int, int> $moves user id => the office they are assigned to right now
	 * @param array<int, string> $names
	 * @return int[]
	 */
	private function orderByName(array $moves, array $names): array
	{
		$userIds = array_keys($moves);

		usort($userIds, static function (int $left, int $right) use ($names): int {
			return [mb_strtolower($names[$left] ?? ''), $left] <=> [mb_strtolower($names[$right] ?? ''), $right];
		});

		return $userIds;
	}

	/**
	 * A mover whose record could not be read is still a row: the fingerprint covers them too.
	 *
	 * @param array<int, int> $moves user id => the office they are assigned to right now
	 * @param int[] $pageUserIds the movers of the page in the order of the page
	 * @param array<int, string> $names
	 */
	private function buildMovers(array $moves, array $pageUserIds, array $names): array
	{
		if ($pageUserIds === [])
		{
			return [];
		}

		$avatars = [];
		foreach ($this->loadUsers($pageUserIds) as $user)
		{
			$avatars[$user->id] = $user->avatar;
		}

		$pageMoves = array_intersect_key($moves, array_flip($pageUserIds));
		$officeTitles = $this->officeRepository->getTitlesByIds(array_values(array_unique($pageMoves)));

		$movers = [];
		foreach ($pageUserIds as $userId)
		{
			$officeId = $pageMoves[$userId];

			$movers[] = [
				'userId' => $userId,
				'name' => $names[$userId] ?? '',
				'avatar' => ($avatars[$userId] ?? '') !== '' ? $avatars[$userId] : null,
				'fromOfficeId' => $officeId,
				'fromOfficeTitle' => $officeTitles[$officeId] ?? '',
			];
		}

		return $movers;
	}

	private function getUnsupportedCodeError(OfficeStaffSelection $selection): Error
	{
		return new Error(
			'The selection holds codes the staff of an office cannot be built from',
			OfficeEmployeeErrorCode::UNSUPPORTED_CODE,
			['codes' => $selection->unsupportedCodes],
		);
	}
}
