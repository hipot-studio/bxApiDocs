<?php

declare(strict_types=1);

namespace Bitrix\Rest\Infrastructure\Rest\Controller;

use Bitrix\Main;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Rest\Enum\APAuth\PasswordType;
use Bitrix\Rest\Infrastructure\Rest\Dto\IncomingWebhook\IncomingWebhookDto;
use Bitrix\Rest\Infrastructure\Rest\Dto\IncomingWebhook\IncomingWebhookDtoMapper;
use Bitrix\Rest\Infrastructure\Rest\Request\AddIncomingWebhookRequest;
use Bitrix\Rest\Infrastructure\Rest\Request\DeleteIncomingWebhookRequest;
use Bitrix\Rest\Infrastructure\Rest\Request\UpdateIncomingWebhookRequest;
use Bitrix\Rest\Infrastructure\Rest\Scopes\RestDeveloper;
use Bitrix\Rest\Public\Command\IncomingWebhook\CreateIncomingWebhookCommand;
use Bitrix\Rest\Public\Command\IncomingWebhook\UpdateIncomingWebhookCommand;
use Bitrix\Rest\Public\Command\IncomingWebhook\DeleteIncomingWebhookCommand;
use Bitrix\Rest\Public\Provider\IncomingWebhookProvider;
use Bitrix\Rest\Public\Provider\Params\IncomingWebhookFilter;
use Bitrix\Rest\Public\Provider\Params\IncomingWebhookParams;
use Bitrix\Rest\Public\Provider\Params\IncomingWebhookSort;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Attribute\Scope;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;
use Bitrix\Rest\V3\Interaction\Request\ListRequest;
use Bitrix\Rest\V3\Interaction\Response\DeleteResponse;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;
use Bitrix\Rest\V3\Interaction\Response\UpdateResponse;

#[DtoType(IncomingWebhookDto::class)]
final class IncomingWebhook extends RestController
{
	use CommandRunner;

	private IncomingWebhookDtoMapper $mapper;

	public function __construct(?Main\Request $request = null)
	{
		parent::__construct($request);
		$this->mapper = new IncomingWebhookDtoMapper();
	}

	#[Scope(RestDeveloper::SCOPE)]
	public function addAction(
		AddIncomingWebhookRequest $request,
	): GetResponse
	{
		$result = $this->runCommand(new CreateIncomingWebhookCommand(
			userId: (int)$this->getCurrentUser()->getId(),
			title: $request->title,
			scopes: $request->scopes,
			attributes: $request->attributes,
		));

		if (!$result->isSuccess())
		{
			return new GetResponse($this->mapper->nullDto());
		}

		$webhookData = reset($result->getData());

		return new GetResponse($this->mapper->toDto($webhookData));
	}

	#[Scope(RestDeveloper::SCOPE)]
	public function updateAction(
		UpdateIncomingWebhookRequest $request,
	): UpdateResponse
	{
		$request->fields->convertToDto();
		$items = $request->fields->getItems();

		$result = $this->runCommand(new UpdateIncomingWebhookCommand(
			userId: (int)$this->getCurrentUser()->getId(),
			webHookPassword: $request->getWebhookPassword(),
			title: $items['title'] ?? null,
			scopes: $items['scopes'] ?? null,
		));

		return new UpdateResponse($result->isSuccess());
	}

	#[Scope(RestDeveloper::SCOPE)]
	public function deleteAction(
		DeleteIncomingWebhookRequest $request,
	): DeleteResponse
	{
		$this->runCommand(new DeleteIncomingWebhookCommand(
			userId: (int)$this->getCurrentUser()->getId(),
			webHookPassword: $request->getWebhookPassword(),
		));

		return new DeleteResponse();
	}

	#[Scope(RestDeveloper::SCOPE)]
	public function listAction(
		ListRequest $request,
		IncomingWebhookProvider $incomingWebhookProvider,
	): ListResponse
	{
		$webhookList = $incomingWebhookProvider->getList(
			new IncomingWebhookParams(
				webhookFilter: new IncomingWebhookFilter(
					userId: (int)$this->getCurrentUser()->getId(),
					type: PasswordType::User,
				),
				pager: new Pager(
					limit: $request->pagination?->getLimit() ?? 50,
					offset: $request->pagination?->getOffset() ?? 0,
				),
				sort: new IncomingWebhookSort(['ID' => 'ASC']),
			),
		);

		return new ListResponse($this->mapper->toDtoCollection($webhookList));
	}
}
