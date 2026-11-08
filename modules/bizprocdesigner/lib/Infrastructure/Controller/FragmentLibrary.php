<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Controller;

use Bitrix\Bizproc\Api\Data\TemplateFragmentService\Fragment;
use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\Api\Request\TemplateFragmentService\CreateFragmentRequest;
use Bitrix\Bizproc\Api\Request\TemplateFragmentService\DeleteFragmentRequest;
use Bitrix\Bizproc\Api\Request\TemplateFragmentService\GetFragmentsRequest;
use Bitrix\Bizproc\Api\Request\TemplateFragmentService\PrepareInsertRequest;
use Bitrix\Bizproc\Api\Request\TemplateFragmentService\UpdateFragmentRequest;
use Bitrix\Bizproc\Api\Response\TemplateFragmentService\CreateFragmentResponse;
use Bitrix\Bizproc\Api\Response\TemplateFragmentService\GetFragmentsResponse;
use Bitrix\Bizproc\Api\Response\TemplateFragmentService\PrepareInsertResponse;
use Bitrix\Bizproc\Api\Response\TemplateFragmentService\UpdateFragmentResponse;
use Bitrix\Bizproc\Api\Service\TemplateFragmentService;
use Bitrix\Bizproc\Internal\Service\Feature\BpDesignerFeature;
use Bitrix\Bizproc\Public\Service\Storage\StorageAuthorizationContext;
use Bitrix\Bizproc\Public\Service\TemplateAccessService;
use Bitrix\Bizproc\Workflow\Template\Fragment\Limits;
use Bitrix\BizprocDesigner\Infrastructure\Dto\FragmentLibrary\FragmentListItemDto;
use Bitrix\BizprocDesigner\Infrastructure\Dto\FragmentLibrary\PreparedInsertDto;
use Bitrix\BizprocDesigner\Internal\Config\Feature;
use Bitrix\BizprocDesigner\Internal\Entity\DocumentDescription;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Enum\RequestSource;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\ValidationContextFactory;
use Bitrix\BizprocDesigner\Internal\Service\Container;
use Bitrix\BizprocDesigner\Internal\Service\TemplateEditAccess;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Engine\JsonController;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;

/**
 * The library of the reusable assemblies of the scheme (API-01 ... API-05). Every action is a thin wrapper:
 * it answers for the form of the request and for the gates of the surface, hands the work to the single
 * service of the fragments and shapes a success into the structure of the contract (DTO-01). No right to a
 * FRAGMENT is counted here - those are the rules of the service, and a copy of them would drift apart from
 * it. The right to the template the insertion is prepared for is the one exception: the recipient is a
 * template of the editor, and the editor is where its ACL is asked, exactly as every other action of the
 * editor asks it.
 *
 * Two refusals of the whole surface are deliberately indistinguishable: a fragment nobody may reach and a
 * fragment that is not there both answer ACCESS_DENIED, so the code of the answer never tells that the
 * personal fragment of somebody else exists.
 *
 * Saving writes no template and reads nothing of its graph: the nodes and the links arrive with the request.
 * The row of the source template is read for one field, its document type, by the same rule the recipient of
 * an insertion is read by - a type arriving with the request would let the caller name a source the fragment
 * was never cut from - and the right to cut a fragment out of it stays with the service.
 */
class FragmentLibrary extends JsonController
{
	private ?TemplateFragmentService $fragmentService = null;
	private ?BpDesignerFeature $bpDesignerFeature = null;
	private ?TemplateEditAccess $editAccess = null;
	private ?TemplateAccessService $accessService = null;

	protected function init(): void
	{
		parent::init();

		Loader::requireModule('bizproc');
	}

	/**
	 * Every action, the reading one included, is narrowed to POST: the transport of the editor sends them
	 * all that way, while the default filters allow GET as well.
	 */
	public function configureActions(): array
	{
		$postOnly = [
			'+prefilters' => [new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST])],
		];

		return [
			'list' => $postOnly,
			'save' => $postOnly,
			'update' => $postOnly,
			'delete' => $postOnly,
			'prepareInsert' => $postOnly,
		];
	}

	/**
	 * API-01. One page of the library, the newest fragments first. The document type takes no part in it:
	 * the library is one per portal and is narrowed by the rights of the reader alone.
	 */
	public function listAction(int $limit, int $offset = 0): ?array
	{
		if ($this->refuseUnavailableSurface() || !$this->validatePage($limit, $offset))
		{
			return null;
		}

		return $this->answer(
			$this->fragmentService()->getFragments(
				new GetFragmentsRequest($this->getUserId(), $limit, $offset),
			),
			static fn(GetFragmentsResponse $response): array => [
				'items' => array_map(
					static fn(Fragment $fragment): FragmentListItemDto => FragmentListItemDto::fromFragment($fragment),
					$response->getFragments(),
				),
				'hasMore' => $response->hasMore(),
			],
		);
	}

	/**
	 * API-02. The selection of the scheme becomes a fragment of the library.
	 *
	 * @param string $documentTypeSigned the type of a scheme that is not saved yet; an existing template
	 *        answers with the type it carries itself, and the signed one is not read at all
	 */
	public function saveAction(
		string $name,
		bool $isShared,
		int $templateId,
		string $documentTypeSigned,
		array $blocks,
		array $connections,
	): ?array
	{
		if ($this->refuseUnavailableSurface() || !$this->validateName($name))
		{
			return null;
		}

		$documentType = $this->resolveSourceDocumentType($templateId, $documentTypeSigned);
		if ($documentType === null)
		{
			return null;
		}

		return $this->answer(
			$this->fragmentService()->createFragment(new CreateFragmentRequest(
				userId: $this->getUserId(),
				name: $name,
				isShared: $isShared,
				templateId: $templateId,
				documentType: $documentType,
				blocks: $blocks,
				connections: $connections,
			)),
			static fn(CreateFragmentResponse $response): array => [
				'item' => FragmentListItemDto::fromFragment($response->getFragment()),
			],
		);
	}

	/**
	 * API-03. The name and the visibility of a fragment; a field left out of the request is not changed.
	 */
	public function updateAction(int $id, ?string $name = null, ?bool $isShared = null): ?array
	{
		if ($this->refuseUnavailableSurface())
		{
			return null;
		}

		if ($name !== null && !$this->validateName($name))
		{
			return null;
		}

		return $this->answer(
			$this->fragmentService()->updateFragment(new UpdateFragmentRequest(
				fragmentId: $id,
				userId: $this->getUserId(),
				name: $name,
				isShared: $isShared,
			)),
			static fn(UpdateFragmentResponse $response): array => [
				'item' => FragmentListItemDto::fromFragment($response->getFragment()),
			],
		);
	}

	/**
	 * API-04. Removing a fragment leaves the copies already inserted into schemes untouched: a fragment
	 * names no template and no template names it.
	 */
	public function deleteAction(int $id): ?array
	{
		if ($this->refuseUnavailableSurface())
		{
			return null;
		}

		return $this->answer(
			$this->fragmentService()->deleteFragment(new DeleteFragmentRequest($id, $this->getUserId())),
			static fn(): array => ['id' => $id],
		);
	}

	/**
	 * API-05. A fragment turned into blocks and connections the canvas of the recipient can take, plus the
	 * two lists the user is told about: what was skipped and what is left to tune by hand.
	 *
	 * The action writes nothing at all - not the template, not its draft, not the library - so neither the
	 * lease of the editing nor the revision of the draft takes part in it. The first write of the graph goes
	 * through the existing autosave and meets TEMPLATE_LOCKED there if the template is busy.
	 *
	 * @param string $documentTypeSigned the type of a template that is not saved yet; an existing template
	 *        answers with the type it carries itself, and the signed one is not read at all
	 */
	public function prepareInsertAction(int $id, int $templateId, string $documentTypeSigned): ?array
	{
		if ($this->refuseUnavailableSurface())
		{
			return null;
		}

		$documentType = $this->resolveTargetDocumentType($templateId, $documentTypeSigned);
		if ($documentType === null)
		{
			return null;
		}

		return $this->answer(
			$this->fragmentService()->prepareInsert(new PrepareInsertRequest(
				fragmentId: $id,
				userId: $this->getUserId(),
				targetDocumentType: $documentType,
				// the baseline of the recipient travels as a promise and not as a value: the service reads and
				// authorises the fragment first, and the barrier asks for the baseline only at a node that
				// really consumes storage
				storageContext: fn(): StorageAuthorizationContext
					=> $this->storageBaseline($templateId, $documentType),
				complexNodeConnectionsAvailable: Feature::instance()->areComplexNodeConnectionsAvailable(),
			)),
			static fn(PrepareInsertResponse $response): array
				=> PreparedInsertDto::fromResponse($response)->jsonSerialize(),
		);
	}

	/**
	 * The document type written into the envelope of a fragment, taken from the source template by the very
	 * rule the recipient of an insertion follows below. The type of the source gates nothing - it goes into
	 * the text of a warning (DTO-02) - but a signed type may be picked up on any other page of the portal and
	 * sent here, and then somebody inserting the fragment is shown a source it was never cut from.
	 *
	 * The signed one is read for a scheme that has never been saved: it has no row to take a type from, and
	 * the right to cut a fragment out of it is asked in that very type by the service.
	 *
	 * A template that is not there answers with the code an unreachable fragment answers with, the way it does
	 * on every other action of the surface.
	 */
	private function resolveSourceDocumentType(int $templateId, string $documentTypeSigned): ?array
	{
		if ($templateId > 0)
		{
			$documentType = $this->editAccess()->resolve($templateId)?->getDocumentComplexType();
			if (empty($documentType))
			{
				$this->addError(ErrorMessage::ACCESS_DENIED->getCodedError());

				return null;
			}

			return $documentType;
		}

		$documentType = \CBPDocument::unSignDocumentType($documentTypeSigned);
		if ($documentType === null)
		{
			$this->refuseInvalidParam('documentTypeSigned', $documentTypeSigned);

			return null;
		}

		return $documentType;
	}

	/**
	 * The document type the fragment is judged against, taken from the template itself: a type arriving with
	 * the request would let the caller pick the context an insertion is measured in. The signed one is read
	 * for a template that is not saved yet, which has no row to take a type from and is gated by the right
	 * to create instead.
	 *
	 * A template that is not there, one of somebody else and one this person may not edit answer alike, and
	 * with the very code an unreachable fragment answers with.
	 */
	private function resolveTargetDocumentType(int $templateId, string $documentTypeSigned): ?array
	{
		$userId = $this->getUserId();

		if ($templateId > 0)
		{
			$tpl = $this->editAccess()->resolve($templateId);
			$documentType = $tpl?->getDocumentComplexType();

			if ($tpl === null || empty($documentType) || !$this->editAccess()->canEdit($tpl, $userId))
			{
				$this->addError(ErrorMessage::ACCESS_DENIED->getCodedError());

				return null;
			}

			return $documentType;
		}

		$documentType = \CBPDocument::unSignDocumentType($documentTypeSigned);
		if ($documentType === null)
		{
			$this->refuseInvalidParam('documentTypeSigned', $documentTypeSigned);

			return null;
		}

		if (!$this->accessService()->canCreate($userId, $documentType))
		{
			$this->addError(ErrorMessage::ACCESS_DENIED->getCodedError());

			return null;
		}

		return $documentType;
	}

	/**
	 * The published baseline of the RECIPIENT, in the neutral shape the guard of the storage references
	 * reads. Without it the barrier of the settings is fail-closed and every node of a fragment that touches
	 * storage would be skipped, so the baseline is assembled here - and by the very collector the reverse
	 * path of the external agent uses, so what one of them calls the declared storage of a template the
	 * other cannot call something else.
	 *
	 * A template with no published version has no baseline at all and the rule stays shut over it. That is
	 * the honest answer and not a gap: a reference is authorised against what the recipient HAS published,
	 * never against what it might.
	 *
	 * Assembling it is not cheap - the published template is read, converted whole and fingerprinted block by
	 * block - and the single rule that reads the result only reaches it for a node consuming storage, so the
	 * action hands it over as a callable. Protected for the same reason the service and the tariff are: a test
	 * of the transport counts how often the recipient is really read.
	 */
	protected function storageBaseline(int $templateId, array $documentType): StorageAuthorizationContext
	{
		if ($templateId <= 0)
		{
			return new StorageAuthorizationContext(0, []);
		}

		$context = ValidationContextFactory::fromBaselineTemplate(
			Container::getAgentWorkflowResolverService()->getPublishedAgentTemplate($templateId),
			new DocumentDescription(
				(string)($documentType[0] ?? ''),
				(string)($documentType[1] ?? ''),
				(string)($documentType[2] ?? ''),
			),
			$templateId,
			RequestSource::Rest,
			$this->getUserId(),
		);

		return new StorageAuthorizationContext(
			templateId: $templateId,
			baselineStaticNodeFingerprints: $context->baselineStaticNodeFingerprints,
			baselineStorageCodes: $context->baselineStorageCodes,
			// the fragment brings no node that creates storage - the barrier skips those - so what this
			// template owns is what its own published version creates, and nothing else
			ownedStorageCodes: $context->baselineCreatorStorageCodes,
		);
	}

	/**
	 * The coded refusals of the service travel to the client as they are; only the shape of a success is
	 * left to the action.
	 *
	 * @param callable(Result): array $present the body of a success
	 */
	private function answer(Result $response, callable $present): ?array
	{
		if (!$response->isSuccess())
		{
			$this->addErrors($response->getErrors());

			return null;
		}

		return $present($response);
	}

	/**
	 * Both gates of the surface, asked before the payload is read so that a closed library answers the
	 * same way to every request. The tariff of the designer is asked in every action of the library and not
	 * only on the way into the editor: a new surface must not inherit the asymmetry "the screen is closed,
	 * its actions are open".
	 *
	 * @return bool whether the refusal has been answered, and the action is over with it
	 */
	private function refuseUnavailableSurface(): bool
	{
		if (!Feature::instance()->isComponentLibraryAvailable())
		{
			$this->addError(ErrorMessage::FEATURE_DISABLED->getCodedError());

			return true;
		}

		if (!$this->isAvailableByTariff())
		{
			$this->addError($this->bpDesignerFeature()->makeUnavailableByTariffError());

			return true;
		}

		return false;
	}

	/**
	 * The name is held to the width of its column here and not by the service: the lower bound is a refusal
	 * of the service with a code of its own, while the upper one belongs to the validator of the entity,
	 * and a refusal of a validator would reach the author as an unknown error.
	 */
	private function validateName(string $name): bool
	{
		$length = mb_strlen(trim($name));

		if ($length >= 1 && $length <= Limits::MAX_NAME_LENGTH)
		{
			return true;
		}

		$this->refuseInvalidParam('name', mb_substr(trim($name), 0, Limits::MAX_NAME_LENGTH));

		return false;
	}

	/**
	 * Both bounds of a page belong to the form of the request, and the service holds them again on its own
	 * layer: a page asked outside them is a request the client got wrong and never a page to trim silently.
	 */
	private function validatePage(int $limit, int $offset): bool
	{
		if ($limit < 1 || $limit > Limits::MAX_PAGE_SIZE)
		{
			$this->refuseInvalidParam('limit', (string)$limit);

			return false;
		}

		if ($offset < 0 || $offset > Limits::MAX_OFFSET)
		{
			$this->refuseInvalidParam('offset', (string)$offset);

			return false;
		}

		return true;
	}

	private function refuseInvalidParam(string $param, string $value): void
	{
		$this->addError(ErrorMessage::INVALID_PARAM_ARG->getCodedError([
			'#PARAM#' => $param,
			'#VALUE#' => $value,
		]));
	}

	/**
	 * The single point where the library is reached, so that a test can put a service with settled rights in
	 * its place: the rights themselves are the rules of the service and are asserted on it, while the
	 * subject here is the transport.
	 */
	protected function fragmentService(): TemplateFragmentService
	{
		return $this->fragmentService ??= new TemplateFragmentService();
	}

	/**
	 * The tariff of the designer, asked through a seam of its own: the class of the feature is final, so a
	 * test substitutes the answer about the portal and not the object answering it.
	 */
	protected function isAvailableByTariff(): bool
	{
		return $this->bpDesignerFeature()->isAvailable();
	}

	/**
	 * The fork "a Nodes template against a legacy one" the whole editor applies to a template it is about to
	 * write, asked here for the recipient of an insertion.
	 */
	private function editAccess(): TemplateEditAccess
	{
		return $this->editAccess ??= new TemplateEditAccess($this->accessService());
	}

	protected function accessService(): TemplateAccessService
	{
		return $this->accessService ??= new TemplateAccessService();
	}

	private function bpDesignerFeature(): BpDesignerFeature
	{
		return $this->bpDesignerFeature ??= ServiceLocator::getInstance()->get(BpDesignerFeature::class);
	}

	private function getUserId(): int
	{
		return (int)CurrentUser::get()->getId();
	}
}
