<?php

namespace Bitrix\Main\Infrastructure\Rest\Controller\User;

use Bitrix\Main\Error;
use Bitrix\Main\Infrastructure\Rest\Controller\AbstractController;
use Bitrix\Main\Infrastructure\Rest\Dto\ConsentDto;
use Bitrix\Main\Infrastructure\Rest\Service\ConsentService;
use Bitrix\Main\UserConsent\ConsentUrl;
use Bitrix\Rest\RestException as LegacyRestException;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Attribute\RequiredGroup;
use Bitrix\Rest\V3\Attribute\Scope;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Interaction\Request\AddRequest;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;

#[DtoType(ConsentDto::class)]
class Consent extends AbstractController
{
	#[Scope('userconsent')]
	public function addAction(AddRequest $request, ConsentService $consentService): GetResponse
	{
		$dto = $request->fields->convertToDto(RequiredGroup::Add->value);

		try
		{
			$id = $consentService->add($this->dtoToFields($dto));
		}
		catch (LegacyRestException $exception)
		{
			throw new RequestValidationException([new Error($exception->getMessage())]);
		}

		return new GetResponse($this->getDtoMapper()->mapOne([
			'ID' => $id,
			'AGREEMENT_ID' => $dto->agreementId,
			'USER_ID' => $this->getInitializedDtoValue($dto, 'userId'),
			'IP' => $dto->ip,
			// the stored value is the one the entity rule let through, not the one that was sent
			'URL' => ConsentUrl::nullifyDisallowed($this->getInitializedDtoValue($dto, 'url')),
			'ORIGIN_ID' => $this->getInitializedDtoValue($dto, 'originId'),
			'ORIGINATOR_ID' => $this->getInitializedDtoValue($dto, 'originatorId'),
		]));
	}

	private function dtoToFields(ConsentDto $dto): array
	{
		$params = [
			'AGREEMENT_ID' => $dto->agreementId,
			'IP' => $dto->ip,
		];

		$userId = $this->getInitializedDtoValue($dto, 'userId');
		if ($userId !== null)
		{
			$params['USER_ID'] = $userId;
		}

		$url = $this->getInitializedDtoValue($dto, 'url');
		if ($url !== null)
		{
			$params['URL'] = $url;
		}

		$originId = $this->getInitializedDtoValue($dto, 'originId');
		if ($originId !== null)
		{
			$params['ORIGIN_ID'] = $originId;
		}

		$originatorId = $this->getInitializedDtoValue($dto, 'originatorId');
		if ($originatorId !== null)
		{
			$params['ORIGINATOR_ID'] = $originatorId;
		}

		return $params;
	}

	private function getInitializedDtoValue(ConsentDto $dto, string $property): mixed
	{
		$propertyReflection = new \ReflectionProperty($dto, $property);
		if (!$propertyReflection->isInitialized($dto))
		{
			return null;
		}

		return $dto->{$property};
	}
}
