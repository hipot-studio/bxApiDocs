<?php

namespace Bitrix\StaffTrack\Controller;

use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\CloseSession as CloseSessionAttribute;
use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\HttpMethod;
use Bitrix\Main\Engine\ActionFilter\CloseSession;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\StaffTrack\Controller\Trait\IntranetUserTrait;
use Bitrix\StaffTrack\Dictionary\OfficeEmployeeErrorCode;
use Bitrix\StaffTrack\Dictionary\OfficeErrorCode;
use Bitrix\StaffTrack\Infrastructure\ActionFilters\OfficeUnavailableFilter;
use Bitrix\StaffTrack\Internal\Service\Office\PhotoService;
use Bitrix\StaffTrack\Internal\Service\Office\StaffPlanService;
use Bitrix\StaffTrack\Public\Command;
use Bitrix\StaffTrack\Public\Command\Office\OfficeDto;
use Bitrix\StaffTrack\Public\Provider\OfficeProvider;
use Bitrix\StaffTrack\Public\Provider\OfficeStaffPreviewProvider;

/**
 * Every action that changes state is restricted to POST by an attribute rather than by an override of
 * getDefaultPreFilters(): the attribute is an EnablePrefilter, so it is appended to the defaults of the
 * base controller (Authentication, Csrf), while an override would replace them.
 *
 * The refusal of the controller is not the answer on rights: the mandatory check lives in the command,
 * or in the provider for the staff of an office, and the controller only carries its errors back to the
 * client. Every reading is done for the caller: whom the staff is shown to is decided there by the viewer.
 */
class Office extends Controller
{
	use IntranetUserTrait;

	/** The code the released `get` has always answered with, kept for the clients that read it. */
	private const LEGACY_OFFICE_NOT_FOUND = 'OFFICE_NOT_FOUND';

	private const EXTERNAL_USER = 'STAFFTRACK_OFFICE_EXTERNAL_USER';

	/**
	 * Every spelling a caller confirms the overlap of the zones in, lowercased. The payload of an action
	 * travels as text and the engine casts no scalar parameter, so a `bool` would read the text `false`
	 * of a client as consent. Consent is read off this list instead, and a value it has never heard of
	 * is not consent: the check of the zones stays on unless the caller plainly took the overlap.
	 */
	private const GEO_OVERLAP_CONFIRMATIONS = ['y', '1', 'true'];
	private const ADDRESS_EDITED_CONFIRMATIONS = ['y', '1', 'true'];

	private int $userId;
	private OfficeProvider $officeProvider;
	private PhotoService $photoService;

	public function getAutoWiredParameters(): array
	{
		return [
			new ExactParameter(
				OfficeDto::class,
				'officeDto',
				fn (string $className, array $fields): OfficeDto => OfficeDto::createFromArray($fields),
			),
		];
	}

	protected function getDefaultPreFilters(): array
	{
		return [
			...parent::getDefaultPreFilters(),
			new OfficeUnavailableFilter(),
		];
	}

	protected function init(): void
	{
		parent::init();

		$this->userId = (int)CurrentUser::get()->getId();
		$this->officeProvider = $this->getOfficeProvider();
		$this->photoService = new PhotoService();
	}

	public function configureActions(): array
	{
		return [
			'get' => [
				'+prefilters' => [
					new CloseSession(),
				],
			],
			'list' => [
				'+prefilters' => [
					new CloseSession(),
				],
			],
			'listEmployees' => [
				'+prefilters' => [
					new CloseSession(),
				],
			],
			'getPermissions' => [
				'+prefilters' => [
					new CloseSession(),
				],
			],
			'searchEmployees' => [
				'+prefilters' => [
					new CloseSession(),
				],
			],
		];
	}

	protected function getOfficeProvider(): OfficeProvider
	{
		return new OfficeProvider();
	}

	public function getAction(int $officeId): ?array
	{
		if (!$this->isCallerIntranetUser())
		{
			return null;
		}

		return $this->buildOfficeResponse($officeId, self::LEGACY_OFFICE_NOT_FOUND);
	}

	public function listAction(): ?array
	{
		if (!$this->isCallerIntranetUser())
		{
			return null;
		}

		return [
			'offices' => $this->officeProvider->getList($this->userId)->toArray(),
			'canCreate' => $this->officeProvider->canCreateOffice($this->userId),
		];
	}

	public function getPermissionsAction(): ?array
	{
		if (!$this->isCallerIntranetUser())
		{
			return null;
		}

		return [
			'canCreate' => $this->officeProvider->canCreateOffice($this->userId),
		];
	}

	/**
	 * The staff of an office page by page, apart from the card of it: the card is released without a
	 * cursor, so what it carries is the first page to draw and this action is what a list is continued
	 * with. The card itself is not rebuilt here - the links of its photos cost a reach into the disk
	 * and no name on the page needs them.
	 *
	 * Where the staff ends is told by `hasMore` and where it goes on by `nextAfterUserId`, both counted
	 * over the rows the storage read: a page whose people were not all assembled is shorter than the
	 * rows behind it, and judging by its length would call it the last one.
	 *
	 * @param int $afterUserId The cursor of the previous page; zero starts the list.
	 * @param string $query Optional: narrows the staff by name. A query too short or too long once
	 *                      normalized answers with an empty page standing at the cursor.
	 */
	public function listEmployeesAction(int $officeId, int $afterUserId = 0, string $query = ''): ?array
	{
		if (!$this->isCallerIntranetUser())
		{
			return null;
		}

		if (!$this->officeProvider->exists($officeId))
		{
			$this->addError(new Error('Office not found', OfficeErrorCode::NOT_FOUND));

			return null;
		}

		$result = $this->officeProvider->getEmployeesPage($officeId, $this->userId, $afterUserId, $query);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		$page = $result->getData();

		return [
			'employees' => $page['employees']->toArray(),
			'nextAfterUserId' => $page['nextAfterUserId'],
			'hasMore' => $page['hasMore'],
		];
	}

	public function searchEmployeesAction(string $query): ?array
	{
		if (!$this->isCallerIntranetUser())
		{
			return null;
		}

		return $this->officeProvider->searchEmployees($query, $this->userId)->toArray();
	}

	/**
	 * The office is created whatever became of its photos, so what went wrong with them rides in the
	 * successful answer beside the card: the surface has no channel for a warning, and a refusal would
	 * send the client into a second attempt leaving two offices behind.
	 *
	 * The office may be created with its staff, and the session is let go for the same reason as in
	 * addEmployeesAction(): expanding a department is heavy. The photos hung after the card only read the
	 * session - the tokens of the uploader are signed with its identifier - and never write it.
	 *
	 * @param string[] $photoTokens Optional: photos may be added to a saved card through addPhoto.
	 * @param string $confirmGeoOverlap The overlap of the zones was shown to the caller and taken by it,
	 *                                  so it is not asked about a second time.
	 * @param array $entities Optional codes of the entity selector: without them the office has no staff.
	 * @param int[] $excludedUserIds Movers the caller took off the staff: they stay in their offices.
	 * @param string $movesFingerprint The fingerprint of the moves last shown to the caller.
	 */
	#[HttpMethod([ActionFilter\HttpMethod::METHOD_POST])]
	#[CloseSessionAttribute]
	public function addAction(
		OfficeDto $officeDto,
		array $photoTokens = [],
		string $confirmGeoOverlap = 'N',
		array $entities = [],
		array $excludedUserIds = [],
		string $movesFingerprint = '',
		string $addressEdited = 'N',
	): ?array
	{
		if (!$this->isCallerIntranetUser())
		{
			return null;
		}

		$staff = null;
		if ($entities !== [])
		{
			$excludedUserIds = $this->readExcludedUserIds($excludedUserIds);
			if ($excludedUserIds === null)
			{
				return null;
			}

			$staff = new Command\Office\OfficeStaffRequest($entities, $excludedUserIds, $movesFingerprint);
		}

		$result = (new Command\Office\Add($this->userId))->execute(
			$officeDto,
			$photoTokens,
			$this->isGeoOverlapConfirmed($confirmGeoOverlap),
			$staff,
			$this->isAddressEdited($addressEdited),
		);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		// the card is written and committed by the time the answer is assembled, so no shape of the data
		// may cost the caller its answer: a missing identifier is refused as a card that is not there,
		// and photos nobody complained about are an empty list
		$data = $result->getData();

		$response = $this->buildOfficeResponse(
			(int)($data['id'] ?? 0),
			Command\Office\AbstractCommand::ERROR_NOT_FOUND,
		);
		if ($response === null)
		{
			return null;
		}

		return [
			'office' => $response['office'],
			'assigned' => (int)($data['assigned'] ?? 0),
			'photoErrors' => $this->describeErrors($data['photoErrors'] ?? []),
		];
	}

	/**
	 * The session is let go as in addAction(): removing photos does not write it either.
	 *
	 * @param string $version The version of the card the caller read and edited.
	 * @param string $saveAttemptKey The key every request of one edit carries, checked by the command:
	 *                               a repeat of an edit already saved answers with `alreadySaved`.
	 * @param string[] $photoTokens Optional, and never a rewrite of the set: what is already hanging on
	 *                              the office stays there.
	 * @param array $removedPhotoIds Photos of the office to remove, by the identifiers the card carries.
	 * @param string $confirmGeoOverlap Read as {@see self::addAction()} reads it.
	 * @param array $entities Optional codes of the entity selector: the people and departments to add.
	 * @param int[] $removedUserIds People to take off the office, stronger than a department adding them.
	 * @param int[] $excludedUserIds Movers the caller took off the added: they stay in their offices.
	 * @param string $movesFingerprint The fingerprint of the moves last shown to the caller.
	 */
	#[HttpMethod([ActionFilter\HttpMethod::METHOD_POST])]
	#[CloseSessionAttribute]
	public function updateAction(
		OfficeDto $officeDto,
		string $version,
		string $saveAttemptKey,
		array $photoTokens = [],
		array $removedPhotoIds = [],
		string $confirmGeoOverlap = 'N',
		array $entities = [],
		array $removedUserIds = [],
		array $excludedUserIds = [],
		string $movesFingerprint = '',
		string $addressEdited = 'N',
	): ?array
	{
		if (!$this->isCallerIntranetUser())
		{
			return null;
		}

		$staff = null;
		if ($entities !== [] || $removedUserIds !== [])
		{
			$excludedUserIds = $this->readExcludedUserIds($excludedUserIds);
			if ($excludedUserIds === null)
			{
				return null;
			}

			$removedUserIds = $this->readRemovedUserIds($removedUserIds);
			if ($removedUserIds === null)
			{
				return null;
			}

			$staff = new Command\Office\OfficeStaffChange(
				$entities,
				$removedUserIds,
				$excludedUserIds,
				$movesFingerprint,
			);
		}

		$result = (new Command\Office\Update($this->userId))->execute(
			$officeDto,
			$version,
			$saveAttemptKey,
			$photoTokens,
			$removedPhotoIds,
			$this->isGeoOverlapConfirmed($confirmGeoOverlap),
			$staff,
			$this->isAddressEdited($addressEdited),
		);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		$data = $result->getData();

		$response = $this->buildOfficeResponse(
			(int)($data['id'] ?? 0),
			Command\Office\AbstractCommand::ERROR_NOT_FOUND,
		);
		if ($response === null)
		{
			return null;
		}

		return [
			'office' => $response['office'],
			'photoErrors' => $this->describeErrors($data['photoErrors'] ?? []),
			'alreadySaved' => (bool)($data['alreadySaved'] ?? false),
		];
	}

	/**
	 * The answer tells where the staff of the removed office went and how many working employees moved
	 * there; the files the command named for the storage stay on the server.
	 *
	 * @param int $targetOfficeId Optional: the office the whole staff moves to; zero leaves the staff
	 *                            without an office.
	 */
	#[HttpMethod([ActionFilter\HttpMethod::METHOD_POST])]
	public function deleteAction(int $id, int $targetOfficeId = 0): ?array
	{
		if (!$this->isCallerIntranetUser())
		{
			return null;
		}

		$officeDto = OfficeDto::createFromArray(['id' => $id, 'targetOfficeId' => $targetOfficeId]);

		$result = (new Command\Office\Delete($this->userId))->execute($officeDto);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		$data = $result->getData();

		return [
			'officeId' => $data['id'],
			'targetOfficeId' => $data['targetOfficeId'],
			'movedCount' => $data['movedCount'],
		];
	}

	/**
	 * A request of the caller to see the staff of an office. The answer is the card of the office as the
	 * caller reads it now, with the request pending on it.
	 */
	#[HttpMethod([ActionFilter\HttpMethod::METHOD_POST])]
	public function requestAccessAction(int $officeId): ?array
	{
		if (!$this->isCallerIntranetUser())
		{
			return null;
		}

		$officeDto = OfficeDto::createFromArray(['id' => $officeId]);

		$result = (new Command\Office\RequestAccess($this->userId))->execute($officeDto);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $this->buildOfficeResponse(
			(int)($result->getData()['officeId'] ?? 0),
			Command\Office\AbstractCommand::ERROR_NOT_FOUND,
		);
	}

	/**
	 * The staff of an office is managed by the difference a client sends - whom it added and whom it took
	 * off - and never by the whole set it wants the office to end up with, so there is no action putting
	 * a staff as one piece. A single person is a set of one code and needs no action of their own either.
	 *
	 * The report of the assignment travels to the client as the command built it: how many were assigned,
	 * which offices people were taken from and who was not accepted.
	 *
	 * The session is let go before the assignment runs: assigning every employee walks the structure page
	 * by page and writes the set chunk by chunk, and every other request of the same operator would wait
	 * on the session for all of it. Nothing on the path reads or writes it. The reading actions declare
	 * the same filter in configureActions(), which this one cannot: an action configured by a line there
	 * and by an attribute at once is refused by the engine.
	 *
	 * @param array $entities codes of the entity selector: a person, a department, every employee
	 */
	#[HttpMethod([ActionFilter\HttpMethod::METHOD_POST])]
	#[CloseSessionAttribute]
	public function addEmployeesAction(int $officeId, array $entities = []): ?array
	{
		if (!$this->isCallerIntranetUser())
		{
			return null;
		}

		$officeDto = OfficeDto::createFromArray(['id' => $officeId]);

		$result = (new Command\Office\AddEmployees($this->userId))->execute($officeDto, $entities);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData();
	}

	/**
	 * The staff a new office would get, shown before it is created, or the change of the staff of an
	 * office shown before its edit is saved: POST because the codes may be many, and the session is let
	 * go for the same reason as in addEmployeesAction().
	 *
	 * @param array $entities codes of the entity selector: a person, a node of the structure, a department
	 * @param int[] $excludedUserIds Movers the caller took off the staff: only echoed back.
	 * @param int $officeId The edited office, and the plan is relative to it; zero for a new office.
	 * @param int[] $removedUserIds People the edit takes off the office; not read for a new office.
	 */
	#[HttpMethod([ActionFilter\HttpMethod::METHOD_POST])]
	#[CloseSessionAttribute]
	public function previewEmployeesAction(
		array $entities = [],
		array $excludedUserIds = [],
		int $officeId = 0,
		array $removedUserIds = [],
	): ?array
	{
		if (!$this->isCallerIntranetUser())
		{
			return null;
		}

		$excludedUserIds = $this->readExcludedUserIds($excludedUserIds);
		if ($excludedUserIds === null)
		{
			return null;
		}

		$removedUserIds = $this->readRemovedUserIds($removedUserIds);
		if ($removedUserIds === null)
		{
			return null;
		}

		$result = (new OfficeStaffPreviewProvider())->preview(
			$this->userId,
			$entities,
			$excludedUserIds,
			$officeId,
			$removedUserIds,
		);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData();
	}

	/**
	 * The movers of the same staff page by page, in the shape a mobile StatefulList reads: the header of
	 * the preview, the rows and `hasMore` side by side. The page comes as `nav=page-N-size-M` in the query
	 * string and is bound by the engine; the `extra` the list sends along is not a parameter here.
	 *
	 * @param array $entities codes of the entity selector, as in previewEmployeesAction()
	 * @param int[] $excludedUserIds Movers the caller took off the staff: only echoed back.
	 * @param int $officeId The edited office, as in previewEmployeesAction().
	 * @param int[] $removedUserIds People the edit takes off the office, as in previewEmployeesAction().
	 */
	#[HttpMethod([ActionFilter\HttpMethod::METHOD_POST])]
	#[CloseSessionAttribute]
	public function previewMoversAction(
		PageNavigation $pageNavigation,
		array $entities = [],
		array $excludedUserIds = [],
		int $officeId = 0,
		array $removedUserIds = [],
	): ?array
	{
		if (!$this->isCallerIntranetUser())
		{
			return null;
		}

		$excludedUserIds = $this->readExcludedUserIds($excludedUserIds);
		if ($excludedUserIds === null)
		{
			return null;
		}

		$removedUserIds = $this->readRemovedUserIds($removedUserIds);
		if ($removedUserIds === null)
		{
			return null;
		}

		$result = (new OfficeStaffPreviewProvider())->previewMovers(
			$this->userId,
			$entities,
			$excludedUserIds,
			$pageNavigation->getOffset(),
			$pageNavigation->getLimit(),
			$officeId,
			$removedUserIds,
		);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData();
	}

	/**
	 * People are taken off the staff by their own identifiers and not by codes of the selector: a client
	 * removes out of the staff it has already been shown.
	 *
	 * @param int[] $userIds
	 */
	#[HttpMethod([ActionFilter\HttpMethod::METHOD_POST])]
	public function removeEmployeesAction(int $officeId, array $userIds = []): ?array
	{
		if (!$this->isCallerIntranetUser())
		{
			return null;
		}

		$officeDto = OfficeDto::createFromArray(['id' => $officeId]);

		$result = (new Command\Office\RemoveEmployees($this->userId))->execute($officeDto, $userIds);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return [];
	}

	/**
	 * Photos are added to an office that already exists, so the files travel apart from the card: a
	 * client uploads them to the uploader of the module, gets tokens and sends the tokens here.
	 *
	 * @param string[] $photoTokens
	 */
	#[HttpMethod([ActionFilter\HttpMethod::METHOD_POST])]
	public function addPhotoAction(int $officeId, array $photoTokens = []): ?array
	{
		if (!$this->isCallerIntranetUser())
		{
			return null;
		}

		$result = $this->photoService->addPhotos($officeId, $photoTokens, $this->userId);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return [
			'photos' => $result->getData()['photos']->toArray(),
		];
	}

	/**
	 * A photo is removed one by one and within its office: the set of photos is never sent back whole.
	 */
	#[HttpMethod([ActionFilter\HttpMethod::METHOD_POST])]
	public function deletePhotoAction(int $officeId, int $photoId): ?array
	{
		if (!$this->isCallerIntranetUser())
		{
			return null;
		}

		$result = $this->photoService->deletePhoto($officeId, $photoId, $this->userId);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return [];
	}

	/**
	 * The directory is internal information of the company, so the barrier stands on every action of
	 * the controller and not on the reading ones alone.
	 */
	private function isCallerIntranetUser(): bool
	{
		if ($this->isIntranetUser($this->userId))
		{
			return true;
		}

		$this->addError(new Error('Access to the office directory denied', self::EXTERNAL_USER));

		return false;
	}

	/**
	 * @return int[]|null the normalised identifiers, or null once the refusal is added
	 */
	private function readExcludedUserIds(array $excludedUserIds): ?array
	{
		return $this->readLimitedUserIds(
			$excludedUserIds,
			new Error(
				'Too many excluded employees',
				OfficeEmployeeErrorCode::TOO_MANY,
				['limit' => StaffPlanService::STAFF_LIMIT],
			),
		);
	}

	/**
	 * @return int[]|null the normalised identifiers, or null once the refusal is added
	 */
	private function readRemovedUserIds(array $removedUserIds): ?array
	{
		return $this->readLimitedUserIds(
			$removedUserIds,
			new Error(
				'Too many employees to take off',
				OfficeEmployeeErrorCode::TOO_MANY_USER_IDS,
				['limit' => StaffPlanService::STAFF_LIMIT],
			),
		);
	}

	/**
	 * The raw list is counted before it is normalised, so an oversized one is refused without a walk.
	 *
	 * @return int[]|null the normalised identifiers, or null once the refusal is added
	 */
	private function readLimitedUserIds(array $userIds, Error $refusal): ?array
	{
		if (count($userIds) > StaffPlanService::STAFF_LIMIT)
		{
			$this->addError($refusal);

			return null;
		}

		return $this->normalizeUserIds($userIds);
	}

	/**
	 * @return int[] positive identifiers, each once
	 */
	private function normalizeUserIds(array $userIds): array
	{
		$normalized = [];

		foreach ($userIds as $userId)
		{
			$userId = is_scalar($userId) ? (int)$userId : 0;
			if ($userId > 0)
			{
				$normalized[$userId] = $userId;
			}
		}

		return array_values($normalized);
	}

	private function isGeoOverlapConfirmed(string $confirmGeoOverlap): bool
	{
		return in_array(mb_strtolower($confirmGeoOverlap), self::GEO_OVERLAP_CONFIRMATIONS, true);
	}

	private function isAddressEdited(string $addressEdited): bool
	{
		return in_array(mb_strtolower($addressEdited), self::ADDRESS_EDITED_CONFIRMATIONS, true);
	}

	/**
	 * Errors travelling inside data rather than in the errors of the answer are laid out by hand: the
	 * envelope of the engine serialises the ones it carries itself and nothing else.
	 *
	 * @param Error[] $errors
	 * @return array<int, array{code: string, message: string}>
	 */
	private function describeErrors(array $errors): array
	{
		return array_map(
			static fn (Error $error): array => [
				'code' => (string)$error->getCode(),
				'message' => $error->getMessage(),
			],
			$errors,
		);
	}

	/**
	 * The code of a missing office is not the same on every action: `get` was released answering with
	 * the legacy one and its clients read exactly that, while the actions added since answer with the
	 * code of the domain - the same one their commands refuse with.
	 */
	private function buildOfficeResponse(int $officeId, string $notFoundCode): ?array
	{
		$office = $this->officeProvider->getById($officeId, $this->userId);
		if ($office === null)
		{
			$this->addError(new Error('Office not found', $notFoundCode));

			return null;
		}

		return [
			'office' => $office->toArray(),
		];
	}
}
