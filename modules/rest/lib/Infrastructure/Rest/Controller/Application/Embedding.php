<?php

declare(strict_types=1);

namespace Bitrix\Rest\Infrastructure\Rest\Controller\Application;

use Bitrix\Main;
use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Rest\Infrastructure\Rest\Controller\CommandRunner;
use Bitrix\Rest\Infrastructure\Rest\Dto\Application\Embedding\EmbeddingDto;
use Bitrix\Rest\Infrastructure\Rest\Dto\Application\Embedding\EmbeddingDtoMapper;
use Bitrix\Rest\Infrastructure\Rest\Exception\ApplicationNotFoundException;
use Bitrix\Rest\Infrastructure\Rest\Request\Application\Embedding\AddEmbeddingRequest;
use Bitrix\Rest\Infrastructure\Rest\Request\Application\Embedding\DeleteEmbeddingRequest;
use Bitrix\Rest\Infrastructure\Rest\Request\Application\Embedding\ListEmbeddingRequest;
use Bitrix\Rest\Infrastructure\Rest\Scopes\RestDeveloper;
use Bitrix\Rest\Internal\Access\AppAccessChecker;
use Bitrix\Rest\Internal\Entity\Application\App;
use Bitrix\Rest\Public\Command\Application\Embedding\AddEmbeddingCommand;
use Bitrix\Rest\Public\Command\Application\Embedding\DeleteEmbeddingCommand;
use Bitrix\Rest\Public\Provider\ApplicationProvider;
use Bitrix\Rest\Public\Provider\EmbeddingProvider;
use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Attribute\Scope;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Interaction\Response\ArrayResponse;
use Bitrix\Rest\V3\Interaction\Response\BooleanResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;

#[DtoType(EmbeddingDto::class)]
final class Embedding extends RestController
{
	use CommandRunner;

	private ?AppAccessChecker $accessChecker = null;

	#[Scope(RestDeveloper::SCOPE)]
	#[Title(new LocalizableMessage(code: 'REST_INFRASTRUCTURE_REST_CONTROLLER_APPLICATION_EMBEDDING_LISTACTION_TITLE', phraseSrcFile: __FILE__))]
	#[Description('
		Request example:
		{
			"clientId": "local.69b9196bdbd793.03286491"
		}
	')]
	public function listAction(
		ListEmbeddingRequest $request,
		EmbeddingProvider $embeddingProvider,
		ApplicationProvider $appProvider,
	): ListResponse
	{
		$app = $this->getAppByClientId($request->clientId, $appProvider);

		$this->ensureCanViewEmbeddingList($app);

		$collection = $embeddingProvider->getListByClientId(
			$request->clientId,
			$request->pagination?->getLimit() ?? 50,
			$request->pagination?->getOffset() ?? 0
		);

		return new ListResponse($this->getMapper()->toDtoCollection($collection));
	}

	#[Scope(RestDeveloper::SCOPE)]
	#[Title(new LocalizableMessage(code: 'REST_INFRASTRUCTURE_REST_CONTROLLER_APPLICATION_EMBEDDING_ADDACTION_TITLE', phraseSrcFile: __FILE__))]
	#[Description('
		Request example:
		{
			"clientId": "local.69b9196bdbd793.03286491",
			"placement": "IM_CONTEXT_MENU",
			"handler": "https://example.com/embed"
		}
	')]
	public function addAction(
		AddEmbeddingRequest $request,
		ApplicationProvider $appProvider,
	): BooleanResponse
	{
		$app = $this->getAppByClientId($request->clientId, $appProvider);

		$this->ensureCanInstallEmbedding($app);

		$this->runCommand(
			new AddEmbeddingCommand(
				app: $app,
				currentUserId: $this->getCurrentUserId(),
				placement: $request->placement,
				handler: $request->handler,
				userId: $request->userId,
				title: $request->title,
				description: $request->description,
				groupName: $request->groupName,
				options: $request->settings,
				languages: $request->languages,
			),
		);

		return new BooleanResponse(true);
	}

	#[Scope(RestDeveloper::SCOPE)]
	#[Title(new LocalizableMessage(code: 'REST_INFRASTRUCTURE_REST_CONTROLLER_APPLICATION_EMBEDDING_DELETEACTION_TITLE', phraseSrcFile: __FILE__))]
	#[Description('
		If `handler` is provided, only the embedding with that handler will be deleted.
		If `userId` is provided, only the embedding for that user will be deleted.
		Request example:
		{
			"clientId": "local.69b9196bdbd793.03286491",
			"placement":"IM_CONTEXT_MENU"
		}
	')]
	public function deleteAction(
		DeleteEmbeddingRequest $request,
		ApplicationProvider $appProvider,
	): ArrayResponse
	{
		$app = $this->getAppByClientId($request->clientId, $appProvider);

		$this->ensureCanUninstallEmbedding($app);

		$result = $this->runCommand(
			new DeleteEmbeddingCommand(
				app: $app,
				currentUserId: $this->getCurrentUserId(),
				placement: $request->placement,
				handler: $request->handler,
				userId: $request->userId,
			),
		);

		return new ArrayResponse($result->getData());
	}

	private function getMapper(): EmbeddingDtoMapper
	{
		return Main\DI\ServiceLocator::getInstance()->get(EmbeddingDtoMapper::class);
	}

	private function getAccessChecker(): AppAccessChecker
	{
		return $this->accessChecker ??= new AppAccessChecker($this->getCurrentUserId());
	}

	private function getAppByClientId(string $clientId, ApplicationProvider $appProvider): App
	{
		$app = $appProvider->getByClientId($this->getCurrentUserId(), $clientId);
		if (!$app)
		{
			throw new ApplicationNotFoundException($clientId);
		}

		return $app;
	}

	private function getCurrentUserId(): int
	{
		return (int)$this->getCurrentUser()?->getId();
	}

	private function ensureCanViewEmbeddingList(App $app): void
	{
		if (!$this->getAccessChecker()->canViewEmbeddingList($app))
		{
			$this->denyAccess();
		}
	}

	private function ensureCanInstallEmbedding(App $app): void
	{
		if (!$this->getAccessChecker()->canInstallEmbedding($app))
		{
			$this->denyAccess();
		}
	}

	private function ensureCanUninstallEmbedding(App $app): void
	{
		if (!$this->getAccessChecker()->canUninstallEmbedding($app))
		{
			$this->denyAccess();
		}
	}

	private function denyAccess(): void
	{
		throw new AccessDeniedException();
	}
}
