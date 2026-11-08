<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Controller\Form;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form\Dictionary\ScenarioDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Form\FormDictionaryDtoMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Form\ListFormDictionaryRequest;
use Bitrix\Crm\V2\Internal\Exception\WebForm;
use Bitrix\Crm\V2\Internal\Service\WebForm\FormDictionaryReader;
use Bitrix\Main\Request;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;

#[DtoType(ScenarioDto::class)]
final class Scenario extends RestController
{
	private ?FormDictionaryReader $reader = null;

	public function __construct(?Request $request = null, ?FormDictionaryReader $reader = null)
	{
		parent::__construct($request);

		$this->reader = $reader;
	}

	public function listAction(ListFormDictionaryRequest $request): ListResponse
	{
		try
		{
			$scenarios = $this->getReader()->listScenarios((int)$this->getCurrentUser()->getId());
		}
		catch (WebForm\FormAccessDeniedException)
		{
			throw new AccessDeniedException();
		}

		return new ListResponse((new FormDictionaryDtoMapper())->getScenarioCollection($scenarios));
	}

	private function getReader(): FormDictionaryReader
	{
		return $this->reader ??= new FormDictionaryReader();
	}
}
