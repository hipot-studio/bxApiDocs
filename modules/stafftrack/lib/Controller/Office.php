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
use Bitrix\StaffTrack\Controller\Trait\IntranetUserTrait;
use Bitrix\StaffTrack\Dictionary\OfficeErrorCode;
use Bitrix\StaffTrack\Infrastructure\ActionFilters\OfficeUnavailableFilter;
use Bitrix\StaffTrack\Internal\Service\Office\PhotoService;
use Bitrix\StaffTrack\Public\Command;
use Bitrix\StaffTrack\Public\Command\Office\OfficeDto;
use Bitrix\StaffTrack\Public\Provider\OfficeProvider;

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
		$this->officeProvider = new OfficeProvider();
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
		];
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
	 */
	public function listEmployeesAction(int $officeId, int $afterUserId = 0): ?array
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

		$result = $this->officeProvider->getEmployeesPage($officeId, $this->userId, $afterUserId);
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

	/**
	 * @param string[] $photoTokens Optional: photos may be added to a saved card through addPhoto.
	 */
	#[HttpMethod([ActionFilter\HttpMethod::METHOD_POST])]
	public function addAction(OfficeDto $officeDto, array $photoTokens = []): ?array
	{
		if (!$this->isCallerIntranetUser())
		{
			return null;
		}

		$result = (new Command\Office\Add($this->userId))->execute($officeDto, $photoTokens);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $this->buildOfficeResponse(
			(int)$result->getData()['id'],
			Command\Office\AbstractCommand::ERROR_NOT_FOUND,
		);
	}

	/**
	 * @param string[] $photoTokens Optional, and never a rewrite of the set: what is already hanging on
	 *                              the office stays there.
	 */
	#[HttpMethod([ActionFilter\HttpMethod::METHOD_POST])]
	public function updateAction(OfficeDto $officeDto, array $photoTokens = []): ?array
	{
		if (!$this->isCallerIntranetUser())
		{
			return null;
		}

		$result = (new Command\Office\Update($this->userId))->execute($officeDto, $photoTokens);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $this->buildOfficeResponse(
			(int)$result->getData()['id'],
			Command\Office\AbstractCommand::ERROR_NOT_FOUND,
		);
	}

	#[HttpMethod([ActionFilter\HttpMethod::METHOD_POST])]
	public function deleteAction(int $id): ?array
	{
		if (!$this->isCallerIntranetUser())
		{
			return null;
		}

		$officeDto = OfficeDto::createFromArray(['id' => $id]);

		$result = (new Command\Office\Delete($this->userId))->execute($officeDto);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return [];
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
