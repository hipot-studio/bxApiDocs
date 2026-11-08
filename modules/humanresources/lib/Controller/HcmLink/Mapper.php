<?php

namespace Bitrix\HumanResources\Controller\HcmLink;

use Bitrix\HumanResources\Contract\Service\HcmLink\JobService;
use Bitrix\HumanResources\Engine\HcmLinkController;
use Bitrix\HumanResources\Exception\UpdateFailedException;
use Bitrix\HumanResources\Item\Collection\HcmLink\PersonCollection;
use Bitrix\HumanResources\Item\HcmLink\Person;
use Bitrix\HumanResources\Result\Service\HcmLink\FilterNotMappedUserIdsResult;
use Bitrix\HumanResources\Result\Service\HcmLink\JobServiceResult;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Service\HcmLink\ExclusionService;
use Bitrix\HumanResources\Type\HcmLink\JobStatus;
use Bitrix\HumanResources\Type\HcmLink\JobType;
use Bitrix\Intranet;
use Bitrix\Main;
use Bitrix\Main\Localization\Loc;

class Mapper extends HcmLinkController
{
	private const MODE_DIRECT = 'direct';
	private const MODE_REVERSE = 'reverse';

	private const ERROR_COMPANY_REQUIRED = 'COMPANY_ID_REQUIRED';
	private const ERROR_INVALID_MAPPING_IDS = 'INVALID_MAPPING_IDS';

	private const LIMIT_USERS_FOR_MAPPER = 60;
	private const LIMIT_PERSONS_FOR_MAPPER = 10;

	public function getDefaultPreFilters(): array
	{
		return [
			new Main\Engine\ActionFilter\ContentType([Main\Engine\ActionFilter\ContentType::JSON]),
			new Main\Engine\ActionFilter\Authentication(),
			new Main\Engine\ActionFilter\HttpMethod(
				[Main\Engine\ActionFilter\HttpMethod::METHOD_POST],
			),
			new Intranet\ActionFilter\IntranetUser(),
		];
	}

	/**
	 * @param array<int> $userIds participants of the document, meaningful in the mode "from participants"
	 *
	 * @return array the response of the mode "from 1C" is left as it was; the mode "from participants"
	 *               carries three keys more, see collectDocumentPreparationFields()
	 */
	public function loadAction(int $companyId, array $userIds, string $mode, ?string $searchName = null): array
	{
		if (!$this->checkAccess())
		{
			return [];
		}

		$items = [];
		$mappedUserIds = [];
		$documentPreparationFields = [];

		if ($mode === self::MODE_DIRECT)
		{
			$notMappedUserIds = $userIds;

			$result = Container::getHcmLinkMapperService()->filterNotMappedUserIds($companyId, ...$userIds);
			if ($result instanceof FilterNotMappedUserIdsResult)
			{
				$notMappedUserIds = $result->userIds;

				$items = Container::getHcmLinkUserRepository()
					->getMappingEntityCollectionByUserIds(
						$notMappedUserIds,
						self::LIMIT_USERS_FOR_MAPPER,
						0,
						$searchName,
					)
					->getValues()
				;
				$items = Container::getHcmLinkMapperService()->getSuggestForUsers($companyId, $items)->items;
			}

			$countMappedPersons =
				Container::getHcmLinkPersonRepository()->getCountMappedPersonsByUserIds($companyId, $userIds);

			$countUnmappedPersons = count($userIds) - $countMappedPersons;

			$documentPreparationFields =
				$this->collectDocumentPreparationFields($companyId, $userIds, $notMappedUserIds);
		}
		else
		{
			$personRepo = Container::getHcmLinkPersonRepository();

			$mappedUserIds = $personRepo->getMappedUserIdsByCompanyId($companyId);
			$countMappedPersons = count($mappedUserIds);

			$personCollection = $personRepo->getUnmappedPersonsByCompanyId($companyId, self::LIMIT_PERSONS_FOR_MAPPER, $searchName);

			if ($personCollection->count() > 0)
			{
				$result = Container::getHcmLinkMapperService()->getMappingEntitiesForUnmappedPersons($personCollection);
				$items = $result->collection->getValues();
				$items = Container::getHcmLinkMapperService()->getSuggestForPeople($items, $mappedUserIds)->items;

				usort($items, static fn($a, $b) => $a->name <=> $b->name);

				foreach ($personCollection as $person)
				{
					$personRepo->updateCounter($person->id);
				}
			}
			$countUnmappedPersons = Container::getHcmLinkPersonRepository()->countUnmappedPersons($companyId);
		}

		$userId = Main\Engine\CurrentUser::get()->getId();
		$isHideInfoAlert = \CUserOptions::GetOption('humanresources', 'hcmlink-mapper-hide-info-alert', false, $userId);

		return compact(
			'items',
			'countMappedPersons',
			'countUnmappedPersons',
			'isHideInfoAlert',
			'mappedUserIds',
		) + $documentPreparationFields;
	}

	/**
	 * What the window of document preparation needs beside the rows and cannot work out on its own. The
	 * two sets come as the subsets of the passed participants they hold for: a row of the window is an
	 * item shared with the mode "from 1C", whose response stays as it is.
	 *
	 * @param array<int> $userIds participants as they came from the client
	 * @param array<int> $notMappedUserIds those of them without a person of this company
	 *
	 * @return array{excludedUserIds: array<int>, matchedUserIds: array<int>, canManageExclusions: bool}
	 */
	private function collectDocumentPreparationFields(
		int $companyId,
		array $userIds,
		array $notMappedUserIds,
	): array
	{
		$exclusionService = Container::getHcmLinkExclusionService();

		// One reading for the whole set: the head case of the feature is a document of 300+ participants.
		$excludedUserIds = $exclusionService->filterExcludedUserIds($companyId, $userIds);

		// The matching of the company is already read to build the rows, and reading it a second time
		// would double that query on the whole set.
		$matchedUserIds = array_map('intval', array_diff($userIds, $notMappedUserIds));
		$matchedUserIds = array_values(array_unique($matchedUserIds));

		// The window opens from the banner of a document, and preparing one does not imply access to the
		// integration section: the action of exclusion is not for everybody who gets here. The release
		// state of sign belongs to the same answer: the client has to learn whether the action is
		// available at all, not to work it out from a right and a module version of its own.
		$canManageExclusions = Container::getHcmLinkAccessService()->canManageExclusions()
			&& $exclusionService->isWriteSupportedBySign()
		;

		return compact('excludedUserIds', 'matchedUserIds', 'canManageExclusions');
	}

	public function saveAction(array $collection, int $companyId): array
	{
		if (!$this->checkAccess())
		{
			return [];
		}

		if (!$this->checkCompanyExists($companyId))
		{
			return [];
		}

		$personRepository = Container::getHcmLinkPersonRepository();
		$map = [];
		foreach ($collection as $item)
		{
			if (empty($item['personId']) || empty($item['userId']))
			{
				continue;
			}

			$map[(int)$item['personId']] = (int)$item['userId'];
		}

		$personCollection = $personRepository->getByIdsExcludeMapped(array_keys($map), $companyId);
		$mappedUserIds = $personRepository->getMappedUserIdsByCompanyId($companyId);
		/** @var string[] $failedMappedPersons */
		$failedPersonsTitles = [];
		$matchedUserIds = [];
		if (!empty($map))
		{
			foreach ($personCollection as $person)
			{
				$person->userId = $map[$person->id] ?? 0;
				if (
					in_array($person->userId, $mappedUserIds, true)
					|| $person->userId === 0
				)
				{
					$failedPersonsTitles[] = $person->title;
					continue;
				}

				try
				{
					$personRepository->update($person);
					$mappedUserIds[] = $person->userId;
					$matchedUserIds[] = $person->userId;
				}
				catch (UpdateFailedException $e)
				{
					$failedPersonsTitles[] = $person->title;
					continue;
				}
			}
		}

		$this->dropExclusionsOfMatchedUsers($companyId, $matchedUserIds);

		if (!empty($failedPersonsTitles))
		{
			$failedPersons = implode(', ', $failedPersonsTitles);
			$this->addError(
				new Main\Error(
					Loc::getMessage(
						'HUMANRESOURCES_HCMLINK_FAILED_SAVED',
						[
							'#FAILED_PERSONS_LIST#' => $failedPersons,
						]
					)
				)
			);
		}

		if (($personRepository->countNotMappedAndGroupByCompanyId([$companyId])[$companyId] ?? 0) === 0)
		{
			Container::getHcmLinkCompanyCounterService()->update();
		}

		if (!$personCollection->empty())
		{
			Container::getHcmLinkJobService()->completeMapping($companyId);
		}

		return [];
	}

	/**
	 * The identifier space of persons is shared by all connected companies, and the set of records
	 * arrives from the client: the action answers only for the records of the company it is called
	 * for, and a set reaching beyond it is refused as a whole rather than trimmed.
	 *
	 * @param array<mixed> $mappingIds identifiers as they came from the client
	 *
	 * @return array<int, int> identifiers of the records the link was really removed from
	 */
	public function deleteAction(?int $companyId = null, array $mappingIds = []): array
	{
		if (!$this->checkAccess())
		{
			return [];
		}

		// A client that knows nothing of the company is a page opened before this action started
		// asking for one: it is refused, because working on by the old contract means unlinking
		// records of a company the screen does not show.
		if ($companyId === null || $companyId <= 0)
		{
			$this->addError(new Main\Error(
				Loc::getMessage('HUMANRESOURCES_HCMLINK_MAPPER_COMPANY_REQUIRED'),
				self::ERROR_COMPANY_REQUIRED,
			));

			return [];
		}

		if (!$this->checkCompanyExists($companyId))
		{
			return [];
		}

		$mappingIds = $this->normalizeMappingIds($mappingIds);
		if ($mappingIds === null)
		{
			return [];
		}

		$collection = Container::getHcmLinkPersonRepository()->getListByIds($mappingIds);
		if ($collection->count() === 0 || !$this->belongsToCompany($collection, $companyId))
		{
			$this->addError($this->makeInvalidMappingIdsError());

			return [];
		}

		$newCollection = Container::getHcmLinkPersonRepository()->deleteLink($collection);

		if ($newCollection->count() !== $collection->count())
		{
			$this->addError(new Main\Error(Loc::getMessage('HUMANRESOURCES_HCMLINK_FAILED_DELETE')));
		}

		if (!$newCollection->empty())
		{
			// Unlinking returns persons to the unmapped ones, and the menu counter groups the whole person
			// table of the portal: it is recounted once per operation, never once per record.
			Container::getHcmLinkCompanyCounterService()->update();
			Container::getHcmLinkJobService()->completeMapping($companyId);
		}

		return array_map(fn(Person $person) => $person->id, $newCollection->getItemMap());
	}

	public function startAction(int $companyId, bool $isForced): array
	{
		if (!$this->checkAccess())
		{
			return [];
		}

		$result = Container::getHcmLinkJobService()->requestEmployeeList($companyId, $isForced);
		if ($result instanceof JobServiceResult)
		{
			$jobId = $result->job->id;
			$status = $result->job->status->value;
			$finishedAt = $result->job->finishedAt;

			return compact('jobId', 'status', 'finishedAt');
		}

		$this->addJobServiceErrors($result->getErrors());

		return [];
	}

	public function endAction(int $companyId): array
	{
		if (!$this->checkAccess())
		{
			return [];
		}

		$result = Container::getHcmLinkJobService()->completeMapping($companyId);
		if ($result instanceof JobServiceResult)
		{
			$jobId = $result->job->id;

			return compact('jobId');
		}

		$this->addJobServiceErrors($result->getErrors());

		return [];
	}

	public function closeInfoAlertAction(): array
	{
		if (!$this->checkAccess())
		{
			return [];
		}

		$userId = Main\Engine\CurrentUser::get()->getId();
		if ($userId !== null)
		{
			\CUserOptions::SetOption('humanresources', 'hcmlink-mapper-hide-info-alert', true, false, (int)$userId);
		}

		return [];
	}

	public function getJobStatusAction(int $jobId): array
	{
		if (!$this->checkAccess())
		{
			return [];
		}

		$job = Container::getHcmLinkJobRepository()->getById($jobId);
		if ($job === null)
		{
			return [];
		}

		$params = [
			'jobId' 	=> $job->id,
			'status' 	=> $job->status->value,
			'finishedAt'=> $job->finishedAt,
		];

		return compact('params');
	}

	public function getLastJobAction(int $companyId): array
	{
		if (!$this->checkAccess())
		{
			return [];
		}

		$job = Container::getHcmLinkJobService()->getLastUserListJob(
			null,
			$companyId,
			[JobStatus::DONE->value]
		);
		if ($job === null)
		{
			return [];
		}

		return [
			'jobId' => $job->id,
			'status' => $job->status->value,
			'finishedAt'=> $job->finishedAt,
		];
	}

	public function cancelJobAction(int $jobId): array
	{
		if (!$this->checkAccess())
		{
			return [];
		}

		$job = Container::getHcmLinkJobRepository()->getById($jobId);
		if ($job !== null && $job->status !== JobStatus::DONE && $job->type === JobType::USER_LIST)
		{
			Container::getHcmLinkJobRepository()->updateStatusByIds([$jobId], JobStatus::CANCELED);
		}

		return [];
	}

	/**
	 * A manual match wins over the exclusion: the person is matched again, so the user stops being
	 * excluded. The removal covers the whole saved set at once and is split by the limit of a single
	 * call, because the set arrives from the client.
	 *
	 * @param array<int> $userIds
	 */
	private function dropExclusionsOfMatchedUsers(int $companyId, array $userIds): void
	{
		if ($userIds === [])
		{
			return;
		}

		$exclusionService = Container::getHcmLinkExclusionService();
		$actorId = (int)Main\Engine\CurrentUser::get()->getId();

		foreach (array_chunk($userIds, ExclusionService::USER_IDS_LIMIT) as $userIdsChunk)
		{
			$exclusionService->removeExclusions($companyId, $userIdsChunk, $actorId);
		}
	}

	/**
	 * The set is refused as a whole instead of being silently trimmed: a mass operation applied to a
	 * part of the selection is harder to notice than a refused one.
	 *
	 * @param array<mixed> $mappingIds
	 *
	 * @return array<int>|null unique positive identifiers, null when an error is already reported
	 */
	private function normalizeMappingIds(array $mappingIds): ?array
	{
		$normalized = [];
		foreach ($mappingIds as $mappingId)
		{
			if (!is_int($mappingId) && !(is_string($mappingId) && preg_match('/^[0-9]+$/D', $mappingId) === 1))
			{
				$this->addError($this->makeInvalidMappingIdsError());

				return null;
			}

			$mappingId = (int)$mappingId;
			if ($mappingId <= 0)
			{
				$this->addError($this->makeInvalidMappingIdsError());

				return null;
			}

			$normalized[$mappingId] = $mappingId;
		}

		if ($normalized === [])
		{
			$this->addError($this->makeInvalidMappingIdsError());

			return null;
		}

		return array_values($normalized);
	}

	private function makeInvalidMappingIdsError(): Main\Error
	{
		return new Main\Error(
			Loc::getMessage('HUMANRESOURCES_COMPANY_DATA_IS_INCORRECT'),
			self::ERROR_INVALID_MAPPING_IDS,
		);
	}

	private function belongsToCompany(PersonCollection $collection, int $companyId): bool
	{
		foreach ($collection as $person)
		{
			if ($person->companyId !== $companyId)
			{
				return false;
			}
		}

		return true;
	}

	private function checkAccess(): bool
	{
		if (!Container::getHcmLinkAccessService()->canRead())
		{
			$this->addError($this->makeAccessDeniedError());

			return false;
		}

		return true;
	}

	/**
	 * @param Main\Error[] $errors
	 */
	private function addJobServiceErrors(array $errors): void
	{
		foreach ($errors as $error)
		{
			if ($error->getCode() === JobService::ERROR_COMPANY_NOT_FOUND)
			{
				$this->addError(
					new Main\Error(Loc::getMessage('HUMANRESOURCES_COMPANY_LINK_NOT_FOUND'))
				);

				continue;
			}

			$this->addError($error);
		}
	}

	private function checkCompanyExists(int $companyId): bool
	{
		if (Container::getHcmLinkCompanyRepository()->getById($companyId) === null)
		{
			$this->addError(
				new Main\Error(Loc::getMessage('HUMANRESOURCES_COMPANY_LINK_NOT_FOUND'))
			);

			return false;
		}

		return true;
	}
}
