<?php

declare(strict_types=1);

namespace Bitrix\Mail\Infrastructure\Rest\Controller;

use Bitrix\Mail\Infrastructure\Rest\Dto\RecipientDto;
use Bitrix\Mail\Infrastructure\Rest\RequestParams;
use Bitrix\Mail\Helper\RecipientHelper;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Interaction\Request\ListRequest;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;

/**
 * REST controller for mail recipients.
 */
#[DtoType(RecipientDto::class)]
class Recipient extends RestController
{
	private const DEFAULT_LIMIT = 50;
	private const MAX_LIMIT = 200;

	/**
	 * Endpoint: mail.recipient.listContacts
	 *
	 * Searches user's mail contacts (address book).
	 * If query is empty, returns recent contacts.
	 *
	 * @restMethod mail.recipient.listContacts
	 * @param ListRequest $request filter: query (partial match by name or email)
	 * @return ListResponse {result: RecipientDto[]}
	 */
	public function listContactsAction(ListRequest $request): ListResponse
	{
		$userId = (int)CurrentUser::get()->getId();
		$params = new RequestParams($this->getRequest()->getJsonList());
		$query = $params->getString('query', '');

		$limit = min($request->pagination?->getLimit() ?? self::DEFAULT_LIMIT, self::MAX_LIMIT);
		$offset = max(0, $request->pagination?->getOffset() ?? 0);

		$provider = new RecipientHelper();
		$recipients = $provider->searchRecipients($query, $userId, $limit, $offset);

		$collection = new DtoCollection(RecipientDto::class);

		foreach ($recipients as $recipient)
		{
			$dto = new RecipientDto();
			$dto->id = $recipient['id'];
			$dto->email = $recipient['email'];
			$dto->name = $recipient['name'];

			$collection->add($dto);
		}

		return new ListResponse($collection);
	}

	/**
	 * Endpoint: mail.recipient.listEmployees
	 *
	 * Searches portal employee email addresses by name or email.
	 *
	 * @restMethod mail.recipient.listEmployees
	 * @param ListRequest $request filter: query* (min 1 char, search by name or email)
	 * @return ListResponse {result: RecipientDto[]}
	 */
	public function listEmployeesAction(ListRequest $request): ListResponse
	{
		$userId = (int)CurrentUser::get()->getId();
		$params = new RequestParams($this->getRequest()->getJsonList());
		$query = $params->requireString('query');

		$limit = min($request->pagination?->getLimit() ?? self::DEFAULT_LIMIT, self::MAX_LIMIT);
		$offset = max(0, $request->pagination?->getOffset() ?? 0);

		$provider = new RecipientHelper();
		$employees = $provider->searchEmployees($query, $userId, $limit, $offset);

		$collection = new DtoCollection(RecipientDto::class);

		foreach ($employees as $employee)
		{
			$dto = new RecipientDto();
			$dto->id = $employee['id'];
			$dto->email = $employee['email'];
			$dto->name = $employee['name'];

			$collection->add($dto);
		}

		return new ListResponse($collection);
	}
}
