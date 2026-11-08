<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Controller;

use Bitrix\Crm\V2\Infrastructure\Rest\Controller\Availability\ActivityEnabledProvider;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Activity\ActivityDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Activity\ActivityCollectionMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Activity\ActivityDtoMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Activity\GetActivityRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\ResponseMapper\Activity\ActivityReadingMapper;
use Bitrix\Crm\V2\Public\Provider\Activity\ActivityProvider;
use Bitrix\Main\Engine\Action;
use Bitrix\Main\Request;
use Bitrix\Main\Result;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Schema\CheckEnabledProvider;

/**
 * The activities of CRM addressed by their own id, whatever they are bound to.
 *
 * Transport only: the rights, the reading and the masking belong to {@see ActivityProvider} and the fields of
 * the answer to the two mappers of the contract.
 */
#[DtoType(ActivityDto::class)]
final class Activity extends RestController
{
	private CheckEnabledProvider $availability;

	/** @var \Closure(int, int, string[]): Result */
	private \Closure $reader;

	/**
	 * @param CheckEnabledProvider|null $availability whether the methods are published, the flag of the wave by
	 *        default.
	 * @param (\Closure(int $userId, int $id, string[] $select): Result)|null $reader reads one activity for a user
	 *        and answers the way {@see ActivityProvider::getById()} does, which is what it is by default.
	 */
	public function __construct(
		?Request $request = null,
		?CheckEnabledProvider $availability = null,
		?\Closure $reader = null,
	)
	{
		parent::__construct($request);

		$this->availability = $availability ?? new ActivityEnabledProvider();
		$this->reader = $reader
			?? static fn(int $userId, int $id, array $select): Result => (new ActivityProvider($userId))
				->getById($id, $select)
		;
	}

	/**
	 * `crm.activity.get`: one activity, as the user of the request may read it.
	 *
	 * An activity that does not exist and one the user may not read get the same answer, the standard not found
	 * of REST.
	 */
	public function getAction(GetActivityRequest $request): GetResponse
	{
		$select = ActivityReadingMapper::selectedNames($request->select);
		$nested = ActivityReadingMapper::nestedNames($request->select);
		$result = ($this->reader)((int)$this->getCurrentUser()?->getId(), $request->id, $select);
		if (!$result->isSuccess())
		{
			throw ActivityReadingMapper::refusalOf($result, $request->id);
		}

		$data = $result->getData();
		$activity = $data[ActivityProvider::DATA_ACTIVITY];

		$dto = (new ActivityDtoMapper())->map($activity, $select);
		(new ActivityCollectionMapper())->fill($dto, $activity, $data[ActivityProvider::DATA_FILES], $select, $nested);

		return new GetResponse($dto);
	}

	/**
	 * An unpublished method is refused before its request is parsed, with the answer REST gives a method it
	 * knows as disabled. The check lives here because the `Enabled` attribute of REST does not accept a
	 * provider yet.
	 */
	protected function processBeforeAction(Action $action): bool
	{
		if (!$this->availability->isEnabled())
		{
			throw new AccessDeniedException();
		}

		return parent::processBeforeAction($action) === true;
	}
}
