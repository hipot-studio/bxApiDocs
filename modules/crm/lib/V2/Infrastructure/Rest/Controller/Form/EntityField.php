<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Controller\Form;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form\Dictionary\EntityFieldDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Form\FormDictionaryDtoMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Exception\Form\UnknownFormEntityException;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Form\ListEntityFieldRequest;
use Bitrix\Crm\V2\Internal\Exception\WebForm;
use Bitrix\Crm\V2\Internal\Service\WebForm\FormDictionaryReader;
use Bitrix\Main\Request;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;

#[DtoType(EntityFieldDto::class)]
final class EntityField extends RestController
{
	private ?FormDictionaryReader $reader = null;

	public function __construct(?Request $request = null, ?FormDictionaryReader $reader = null)
	{
		parent::__construct($request);

		$this->reader = $reader;
	}

	public function listAction(ListEntityFieldRequest $request): ListResponse
	{
		$entityNames = $request->getEntityNames();

		try
		{
			$fields = $this->getReader()->listEntityFields(
				(int)$this->getCurrentUser()->getId(),
				$entityNames,
			);
		}
		catch (WebForm\FormAccessDeniedException)
		{
			throw new AccessDeniedException();
		}
		catch (WebForm\UnknownFormEntityException $exception)
		{
			throw new UnknownFormEntityException($exception->entityNames);
		}

		return new ListResponse((new FormDictionaryDtoMapper())->getEntityFieldCollection($fields));
	}

	private function getReader(): FormDictionaryReader
	{
		return $this->reader ??= new FormDictionaryReader();
	}
}
