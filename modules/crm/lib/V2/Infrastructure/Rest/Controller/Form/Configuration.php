<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Controller\Form;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form\Configuration\ConfigurationDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Form\FormConfigurationDtoMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Form\GetFormConfigurationRequest;
use Bitrix\Crm\V2\Internal\Exception\WebForm\FormAccessDeniedException;
use Bitrix\Crm\V2\Internal\Service\WebForm\FormConfigurationReader;
use Bitrix\Main\Request;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;

#[DtoType(ConfigurationDto::class)]
final class Configuration extends RestController
{
	private ?FormConfigurationReader $reader = null;

	public function __construct(?Request $request = null, ?FormConfigurationReader $reader = null)
	{
		parent::__construct($request);

		$this->reader = $reader;
	}

	public function getAction(GetFormConfigurationRequest $request): GetResponse
	{
		try
		{
			$configuration = $this->getReader()->read((int)$this->getCurrentUser()->getId());
		}
		catch (FormAccessDeniedException)
		{
			throw new AccessDeniedException();
		}

		return new GetResponse((new FormConfigurationDtoMapper())->getDto($configuration));
	}

	private function getReader(): FormConfigurationReader
	{
		return $this->reader ??= new FormConfigurationReader();
	}
}
