<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Controller\Form;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form\Embedding\SiteButtonDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Form\FormRequestMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Form\SiteButtonDtoMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Exception\Form\FormNotFoundException;
use Bitrix\Crm\V2\Infrastructure\Rest\Exception\Form\PhoneVerificationRequiredException;
use Bitrix\Crm\V2\Infrastructure\Rest\Exception\Form\SiteButtonAccessDeniedException;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Form\ListSiteButtonRequest;
use Bitrix\Crm\V2\Internal\Exception\WebForm;
use Bitrix\Crm\V2\Internal\Service\WebForm\EmbeddingReader;
use Bitrix\Main\Request;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;

#[DtoType(SiteButtonDto::class)]
final class SiteButton extends RestController
{
	private ?EmbeddingReader $reader = null;
	private ?FormRequestMapper $requestMapper = null;

	public function __construct(?Request $request = null, ?EmbeddingReader $reader = null)
	{
		parent::__construct($request);

		$this->reader = $reader;
	}

	public function listAction(ListSiteButtonRequest $request): ListResponse
	{
		$formId = $request->getFormId();
		$requestMapper = $this->getRequestMapper();
		$pager = $requestMapper->mapPager($request->pagination);

		try
		{
			$page = $this->getReader()->listSiteButtons(
				(int)$this->getCurrentUser()->getId(),
				$formId,
				$requestMapper->mapIdOrder($request->order),
				$pager->getLimit(),
				$pager->getOffset(),
			);
		}
		catch (WebForm\FormAccessDeniedException)
		{
			throw new AccessDeniedException();
		}
		catch (WebForm\SiteButtonAccessDeniedException)
		{
			throw new SiteButtonAccessDeniedException();
		}
		catch (WebForm\PhoneNotVerifiedException)
		{
			throw new PhoneVerificationRequiredException();
		}
		catch (WebForm\FormNotFoundException)
		{
			throw new FormNotFoundException($formId);
		}

		return new ListResponse(
			(new SiteButtonDtoMapper())->getDtoCollection(
				$formId,
				$page['siteButtons'],
				$page['previewUrlById'],
			),
		);
	}

	private function getReader(): EmbeddingReader
	{
		return $this->reader ??= new EmbeddingReader();
	}

	private function getRequestMapper(): FormRequestMapper
	{
		return $this->requestMapper ??= new FormRequestMapper();
	}
}
