<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Lead;

use Bitrix\Crm\EntityAddressType;
use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\AbstractController;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Address\Lead\AddressDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Address\Lead\LeadAddressMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Address\Lead\DeleteRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Address\Lead\GetRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Address\Lead\SetRequest;
use Bitrix\Crm\V2\Public\Command\Address\DeleteAddressCommand;
use Bitrix\Crm\V2\Public\Command\Address\SaveAddressCommand;
use Bitrix\Crm\V2\Public\Entity\Address\AddressIdentifier;
use Bitrix\Crm\V2\Public\Entity\Address\AddressOwner;
use Bitrix\Crm\V2\Public\Provider\Address\EntityAddressProvider;
use Bitrix\Main\Error;
use Bitrix\Main\Engine\Action;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Interaction\Response\DeleteResponse;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\UpdateResponse;

#[DtoType(AddressDto::class)]
final class Address extends AbstractController
{
	private ?LeadAddressMapper $addressMapper = null;

	protected function processBeforeAction(Action $action): bool
	{
		if (!parent::processBeforeAction($action))
		{
			return false;
		}

		$this->checkLeadAvailability();

		return true;
	}

	public function getAction(GetRequest $request, CurrentUser $currentUser): GetResponse
	{
		try
		{
			$address = (new EntityAddressProvider())->get(
				$this->createAddressIdentifier($request->id),
				$this->createAddressOwner($request->id),
				(int)$currentUser->getId(),
			);
		}
		catch (ObjectNotFoundException)
		{
			throw new EntityNotFoundException($request->id);
		}

		return new GetResponse($this->getAddressMapper()->mapToDto($address));
	}

	public function setAction(SetRequest $request, CurrentUser $currentUser): UpdateResponse
	{
		if ($request->fields->getItems() === [])
		{
			throw new RequestValidationException([new Error('The fields property must not be empty.', 'fields')]);
		}

		/** @var AddressDto $dto */
		$dto = $request->fields->convertToDto('set');

		try
		{
			$result = (new SaveAddressCommand(
				$this->createAddressIdentifier($request->id),
				$this->createAddressOwner($request->id),
				$this->getAddressMapper()->mapToChanges($dto, $request->fields->getItems()),
				(int)$currentUser->getId(),
			))->run();
		}
		catch (ObjectNotFoundException)
		{
			throw new EntityNotFoundException($request->id);
		}

		if (!$result->isSuccess())
		{
			throw new RequestValidationException($result->getErrors());
		}

		return new UpdateResponse();
	}

	public function deleteAction(DeleteRequest $request, CurrentUser $currentUser): DeleteResponse
	{
		try
		{
			$result = (new DeleteAddressCommand(
				$this->createAddressIdentifier($request->id),
				$this->createAddressOwner($request->id),
				(int)$currentUser->getId(),
			))->run();
		}
		catch (ObjectNotFoundException)
		{
			throw new EntityNotFoundException($request->id);
		}

		if (!$result->isSuccess())
		{
			throw new RequestValidationException($result->getErrors());
		}

		return new DeleteResponse();
	}

	private function getAddressMapper(): LeadAddressMapper
	{
		return $this->addressMapper ??= new LeadAddressMapper();
	}

	private function createAddressIdentifier(int $leadId): AddressIdentifier
	{
		return new AddressIdentifier(
			\CCrmOwnerType::Lead,
			$leadId,
			EntityAddressType::Primary,
		);
	}

	private function createAddressOwner(int $leadId): AddressOwner
	{
		return new AddressOwner(\CCrmOwnerType::Lead, $leadId);
	}
}
