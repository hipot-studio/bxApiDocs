<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Controller;

use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\Api\Enum\Template\WorkflowTemplateType;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Internal\Service\ProcessData\DataElementKeyValidator;
use Bitrix\Bizproc\Internal\Service\ProcessData\DataElementTypeValidator;
use Bitrix\Bizproc\Public\ProcessData\Dto\ProcessDataCatalog;
use Bitrix\Bizproc\Public\ProcessData\Dto\ProcessDataElement;
use Bitrix\Bizproc\Public\ProcessData\Enum\DataElementPurpose;
use Bitrix\Bizproc\Public\ProcessData\Enum\SharedValueUsage;
use Bitrix\Bizproc\Public\ProcessData\Service\ProcessDataCatalogService;
use Bitrix\Bizproc\Public\ProcessData\Service\ProcessDataMechanicsResolver;
use Bitrix\Bizproc\Public\ProcessData\Service\ProcessDataWriteService;
use Bitrix\Bizproc\Public\ProcessData\Service\SharedValueUsageService;
use Bitrix\Bizproc\Public\Service\TemplateAccessService;
use Bitrix\Bizproc\Workflow\Entity\WorkflowInstanceTable;
use Bitrix\Bizproc\Workflow\Template\Converter\NodesToTemplate;
use Bitrix\Bizproc\Workflow\Template\Converter\TemplateToNodes;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Bizproc\Workflow\Template\SourceType;
use Bitrix\Bizproc\Workflow\Template\Tpl;
use Bitrix\Bizproc\Workflow\Template\WorkflowTemplateDraftTable;
use Bitrix\Bizproc\Workflow\Type\GlobalConst;
use Bitrix\Bizproc\Workflow\Type\GlobalVar;
use Bitrix\Bizproc\Workflow\Type\GlobalsManager;
use Bitrix\BizprocDesigner\Internal\Config\Feature;
use Bitrix\BizprocDesigner\Internal\Service\Activity\ReadableExpressionContextFactory;
use Bitrix\Main\DB\DuplicateEntryException;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\JsonController;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\Type\ParameterDictionary;

/**
 * The server side of the process-data window (API-05). It keeps no state of its own and writes no template:
 * every action is handed the blocks the editor holds right now, exactly like `Diagram.publicateDraft`, and
 * answers with the new blocks and the rebuilt catalog. The client puts them into its store and saves them by
 * the usual draft autosave (SDD-backend §5.5).
 *
 * The shared values of the portal are the single exception: they are entities of the portal and not of the
 * template, so they are written at once through the existing managers (API-04).
 */
class ProcessData extends JsonController
{
	private ?TemplateAccessService $accessService = null;
	private ?ReadableExpressionContextFactory $readableExpressionContextFactory = null;

	protected function init(): void
	{
		parent::init();

		Loader::requireModule('bizproc');
	}

	/**
	 * Not a class constant on purpose: PHP evaluates constant expressions at `new`, before init() has
	 * loaded bizproc, so a reference to SourceType there is a fatal error on every ajax hit.
	 *
	 * @return array<string, class-string<GlobalVar|GlobalConst>>
	 */
	private static function sharedManagerOfSourceType(): array
	{
		return [
			SourceType::GlobalVariable => GlobalVar::class,
			SourceType::GlobalConstant => GlobalConst::class,
		];
	}

	/**
	 * The transport of the editor sends every action, the reading ones included, by POST, while the default
	 * filters would allow GET as well.
	 */
	public function configureActions(): array
	{
		$postOnly = [
			'+prefilters' => [new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST])],
		];

		return array_fill_keys(
			['get', 'create', 'update', 'rename', 'changePurpose', 'delete', 'usages', 'upsertShared', 'deleteShared'],
			$postOnly,
		);
	}

	public function getAction(): ?array
	{
		$context = $this->prepareContext();
		if ($context === null)
		{
			return null;
		}

		$catalog = $this->readCatalog($context['fields']);

		return [
			'mechanics' => $this->isNewMechanics($context) ? 'new' : 'legacy',
			'canEdit' => $context['canEdit'],
			'runningInstances' => $context['templateId'] > 0
				? WorkflowInstanceTable::getCount(['=WORKFLOW_TEMPLATE_ID' => $context['templateId']])
				: 0,
			'catalog' => $this->elementsToArray($catalog->getElements()),
			'shared' => $this->readShared($context),
		];
	}

	/**
	 * @param array $element {key: string, purpose: string, property: array}
	 */
	public function createAction(array $element): ?array
	{
		$context = $this->prepareWriteContext();
		if ($context === null)
		{
			return null;
		}

		$purposeValue = (string)($element['purpose'] ?? '');
		$purpose = DataElementPurpose::tryFrom($purposeValue);
		if ($purpose === null)
		{
			return $this->refuseInvalidParam('purpose', $purposeValue);
		}

		return $this->answerChange(
			$this->writeService($context, withTemplate: false)->create(
				(string)($element['key'] ?? ''),
				$purpose,
				(array)($element['property'] ?? []),
			),
		);
	}

	public function updateAction(string $sourceType, string $key, array $property): ?array
	{
		$context = $this->prepareWriteContext();
		if ($context === null)
		{
			return null;
		}

		return $this->answerChange(
			$this->writeService($context, withTemplate: false)->update($sourceType, $key, $property),
		);
	}

	public function renameAction(string $sourceType, string $key, string $newKey): ?array
	{
		$context = $this->prepareWriteContext();
		if ($context === null)
		{
			return null;
		}

		return $this->answerChange(
			$this->writeService($context)->rename($sourceType, $key, $newKey),
			withNodes: true,
			documentType: $context['documentType'],
		);
	}

	/**
	 * The change of the purpose carries the property along (AC-045): one move and one edit of the schema are
	 * one intent of the developer, and the shared value the move writes is created by the schema it is moved
	 * with. An empty property means the move alone.
	 *
	 * @param string $sharedSourceType The storage the form chose for the value leaving the template: the
	 *     purpose of a shared value is picked by the developer and not read off the element it came from. An
	 *     empty one is the choice of a client that does not send it yet — then the element answers for it.
	 */
	public function changePurposeAction(
		string $sourceType,
		string $key,
		string $purpose,
		array $property = [],
		string $sharedSourceType = '',
	): ?array
	{
		$context = $this->prepareWriteContext();
		if ($context === null)
		{
			return null;
		}

		$targetPurpose = DataElementPurpose::tryFrom($purpose);
		if ($targetPurpose === null)
		{
			return $this->refuseInvalidParam('purpose', $purpose);
		}

		if ($targetPurpose === DataElementPurpose::Shared)
		{
			if ($sharedSourceType !== '' && !isset(self::sharedManagerOfSourceType()[$sharedSourceType]))
			{
				return $this->refuseInvalidParam('sharedSourceType', $sharedSourceType);
			}

			return $this->answerChange(
				$this->moveToShared($context, $sourceType, $key, $property, $sharedSourceType),
				withNodes: true,
				documentType: $context['documentType'],
			);
		}

		// A shared value belongs to the portal: other templates may answer to it, and reading all of them for
		// a reference does not scale, so the move back into the template is not offered at all.
		if (isset(self::sharedManagerOfSourceType()[$sourceType]))
		{
			return $this->refuseInvalidParam('purpose', $purpose);
		}

		return $this->answerChange(
			$this->changeTemplatePurpose($context, $sourceType, $key, $targetPurpose, $property),
			withNodes: true,
			documentType: $context['documentType'],
		);
	}

	/** The edit is applied where the element still stands, and the move carries the applied property with it. */
	private function changeTemplatePurpose(
		array $context,
		string $sourceType,
		string $key,
		DataElementPurpose $purpose,
		array $property,
	): Result
	{
		if ($property === [])
		{
			return $this->writeService($context)->changePurpose($sourceType, $key, $purpose);
		}

		$updated = $this->writeService($context)->update($sourceType, $key, $property);
		if (!$updated->isSuccess())
		{
			return $updated;
		}

		return $this->writeService($context, $updated->getData())->changePurpose($sourceType, $key, $purpose);
	}

	/**
	 * Move of an element of the template into a shared value of the portal: the shared value is written under
	 * the identifier of the element and the references answer to it. The storage is the one the form chose;
	 * a client naming none leaves it to the element — a constant becomes a global constant, an input or
	 * internal element a global variable.
	 */
	private function moveToShared(
		array $context,
		string $sourceType,
		string $key,
		array $property = [],
		string $sharedSourceType = '',
	): Result
	{
		$element = $this->readCatalog($context['fields'])->findByKey($key, $sourceType);
		if ($element === null)
		{
			return (new Result())->addError(ErrorMessage::ENTITY_NOT_EXISTS->getCodedError([], ['key' => $key]));
		}

		$manager = self::sharedManagerOfSourceType()[$sharedSourceType]
			?? ($sourceType === SourceType::Constant ? GlobalConst::class : GlobalVar::class)
		;
		$sharedProperty = $property === [] ? $element->property : $property;
		$visibility = $sharedProperty['Visibility'] ?? 'GLOBAL';

		if (
			!is_string($visibility)
			|| !$manager::canUserCreate($context['documentType'], $context['userId'], $visibility)
		)
		{
			return (new Result())->addError(ErrorMessage::ACCESS_DENIED->getCodedError());
		}

		// An identifier already standing on the portal belongs to a value of somebody else: an upsert under it
		// would silently rewrite that value.
		if ($manager::getById($key) !== null)
		{
			return (new Result())->addError(
				ErrorMessage::PROCESS_DATA_SHARED_KEY_DUPLICATE->getCodedError(['#KEY#' => $key], ['key' => $key]),
			);
		}

		// The template is asked first: a refused move must leave no shared value behind it.
		$moved = $this->writeService($context)->moveToShared(
			$sourceType,
			$key,
			$manager::getObjectNameForExpressions(),
		);
		if (!$moved->isSuccess())
		{
			return $moved;
		}

		$prepared = $this->prepareSharedProperty(
			$context['documentType'],
			$sharedProperty,
			$element->property,
		);
		if (!$prepared->isSuccess())
		{
			return $prepared;
		}

		$inserted = $this->insertShared($manager, $key, $prepared->getData()['property'], $context['userId']);

		return $inserted->isSuccess() ? $moved : $inserted;
	}

	/**
	 * A shared value is written under an identifier the request judged free. Between that judgement and the
	 * write another request can take the identifier, and into a scope this document type does not see: the
	 * write is therefore an insert, and a taken identifier answers as the duplicate it is instead of being
	 * rewritten.
	 *
	 * @param class-string<GlobalsManager> $manager
	 */
	private function insertShared(string $manager, string $id, array $property, int $userId): Result
	{
		try
		{
			return $manager::insertByProperty($id, $property, $userId);
		}
		catch (DuplicateEntryException)
		{
			return (new Result())->addError(
				ErrorMessage::PROCESS_DATA_SHARED_KEY_DUPLICATE->getCodedError(['#KEY#' => $id], ['key' => $id]),
			);
		}
	}

	public function deleteAction(string $sourceType, string $key): ?array
	{
		$context = $this->prepareWriteContext();
		if ($context === null)
		{
			return null;
		}

		return $this->answerChange($this->writeService($context)->delete($sourceType, $key));
	}

	public function usagesAction(string $sourceType, string $key): ?array
	{
		$context = $this->prepareContext();
		if ($context === null)
		{
			return null;
		}

		return ['usages' => $this->findUsages($context, $sourceType, $key)];
	}

	/**
	 * A shared value belongs to the portal and is written at once (API-04): the draft of the template neither
	 * holds it nor rolls it back.
	 */
	public function upsertSharedAction(string $sourceType, string $id, array $property): ?array
	{
		$context = $this->prepareContext();
		if ($context === null)
		{
			return null;
		}

		$manager = self::sharedManagerOfSourceType()[$sourceType] ?? null;
		if ($manager === null)
		{
			return $this->refuseInvalidParam('sourceType', $sourceType);
		}

		$stored = $manager::getById($id);
		$visibility = $stored === null ? $property['Visibility'] ?? 'GLOBAL' : $stored['Visibility'] ?? null;
		if ($stored === null && is_string($visibility))
		{
			$refusal = $this->refuseUnavailableVisibility($context['documentType'], $property, null);
			if ($refusal !== null)
			{
				$this->addErrors($refusal->getErrors());

				return null;
			}
		}

		$canUpsert = $stored === null
			? is_string($visibility)
				&& $manager::canUserCreate($context['documentType'], $context['userId'], $visibility)
			: is_string($visibility)
				&& $manager::canUserUpdateByVisibility($context['documentType'], $context['userId'], $visibility)
		;
		if (!$canUpsert)
		{
			$this->addError(ErrorMessage::ACCESS_DENIED->getCodedError());

			return null;
		}

		// The identifier of a shared value is its key in the expressions ({=GlobalVar:Id}), so it is judged
		// by the rule every key of the process data is judged by.
		$keyValidation = (new DataElementKeyValidator())->validateKey($id);
		if (!$keyValidation->isSuccess())
		{
			$this->addErrors($keyValidation->getErrors());

			return null;
		}

		if ($stored !== null && ($property['Visibility'] ?? '') === '')
		{
			$property['Visibility'] = $stored['Visibility'];
		}

		$refusal = $this->guardSharedProperty($id, $property, $stored);
		if ($refusal !== null)
		{
			$this->addErrors($refusal->getErrors());

			return null;
		}

		$prepared = $this->prepareSharedProperty($context['documentType'], $property, $stored);
		if (!$prepared->isSuccess())
		{
			$this->addErrors($prepared->getErrors());

			return null;
		}

		$written = $stored === null
			? $this->insertShared($manager, $id, $prepared->getData()['property'], $context['userId'])
			: $manager::updateByPropertyIfVisibility(
				$id,
				$prepared->getData()['property'],
				$visibility,
				$context['userId'],
			)
		;
		if (!$written->isSuccess())
		{
			$this->addErrors($written->getErrors());

			return null;
		}

		$element = $this->findSharedElement($context['documentType'], $sourceType, $id);
		if ($element === null)
		{
			$this->addError(ErrorMessage::ENTITY_NOT_EXISTS->getCodedError([], ['key' => $id]));

			return null;
		}

		return ['element' => $element->toArray($this->visibilityNames($context['documentType']))];
	}

	public function deleteSharedAction(string $sourceType, string $id): ?array
	{
		$context = $this->prepareContext();
		if ($context === null)
		{
			return null;
		}

		$manager = self::sharedManagerOfSourceType()[$sourceType] ?? null;
		if ($manager === null)
		{
			return $this->refuseInvalidParam('sourceType', $sourceType);
		}

		$field = $manager::getFieldAccessDataById($id);
		$visibility = $field['Visibility'] ?? null;
		if (
			!is_string($visibility)
			|| !$manager::canUserUpdateByVisibility($context['documentType'], $context['userId'], $visibility)
		)
		{
			$this->addError(ErrorMessage::ACCESS_DENIED->getCodedError());

			return null;
		}

		// The canvas is asked first: it is fresher than its own drafts stored on the portal, and only it can
		// name the activities holding the value.
		$usages = $this->findUsages($context, $sourceType, $id);
		if ($usages !== [])
		{
			$this->addError(
				ErrorMessage::PROCESS_DATA_SHARED_IN_USE->getCodedError(
					['#KEY#' => $id],
					['key' => $id, 'activities' => $usages],
				),
			);

			return null;
		}

		// A shared value belongs to the portal: the published version of this template, another template and
		// a draft of somebody else hold it exactly as an activity of the canvas does.
		$usage = (new SharedValueUsageService())->usageOutsideCanvas($sourceType, $id, $context['draftId']);
		if ($usage !== SharedValueUsage::Free)
		{
			$error =
				$usage === SharedValueUsage::Unknown
					? ErrorMessage::PROCESS_DATA_SHARED_USAGE_UNKNOWN
					: ErrorMessage::PROCESS_DATA_SHARED_IN_USE
			;

			$this->addError($error->getCodedError(['#KEY#' => $id], ['key' => $id]));

			return null;
		}

		if (!$manager::deleteIfVisibility($id, $visibility))
		{
			$this->addError(ErrorMessage::ENTITY_NOT_EXISTS->getCodedError([], ['key' => $id]));

			return null;
		}

		return ['deleted' => true];
	}

	/**
	 * The request of every action: the template as the editor holds it now. The document type of a saved
	 * template belongs to its row and never to the request, exactly as in `Diagram::prepareDiagramData`.
	 *
	 * The tree of the canvas is built only where an action asks for it: opening the window does not need it.
	 *
	 * @return array{
	 *     templateId: int,
	 *     userId: int,
	 *     draftId: int,
	 *     documentType: array,
	 *     fields: array,
	 *     template: \Closure,
	 *     canEdit: bool,
	 * }|null
	 */
	private function prepareContext(): ?array
	{
		if (!Feature::instance()->isProcessDataAvailable())
		{
			$this->addError(ErrorMessage::FEATURE_DISABLED->getCodedError());

			return null;
		}

		$json = $this->getRequest()->getJsonList();
		$templateId = (int)$json->get('templateId');
		$user = new \CBPWorkflowTemplateUser(\CBPWorkflowTemplateUser::CurrentUser);
		$userId = $user->getId();

		$tpl = $templateId > 0 ? $this->getTpl($templateId) : null;
		$documentType = $tpl?->getDocumentComplexType()
			?? \CBPDocument::unSignDocumentType($json->get('documentTypeSigned'));

		if ($templateId > 0 && $tpl === null)
		{
			// Same anti-oracle guard as Diagram: the absence of a record must not become an answer to a
			// non-admin. It stands before the document-type check, so an empty type of the request does not
			// mask the real cause for an admin.
			if (!$user->isAdmin())
			{
				$this->addError(ErrorMessage::ACCESS_DENIED->getCodedError());

				return null;
			}

			$this->addError(ErrorMessage::TEMPLATE_NOT_FOUND->getCodedError(['#ID#' => $templateId]));

			return null;
		}

		if (\CBPHelper::isEmptyValue($documentType))
		{
			$this->addError(ErrorMessage::ACCESS_DENIED->getCodedError());

			return null;
		}

		$canEdit = $templateId > 0
			? $this->accessService()->canEdit($templateId, $userId, $documentType)
			: $this->accessService()->canCreate($userId, $documentType);

		// Reading the catalog of a saved template costs the right that opens the designer itself, and that is
		// the edit right of the template ({@see Diagram::getTemplateData}). A template that does not exist yet
		// belongs to whoever holds its canvas; only its changes are asked for the right to create.
		if ($templateId > 0 && !$canEdit)
		{
			$this->addError(ErrorMessage::ACCESS_DENIED->getCodedError());

			return null;
		}

		return [
			'templateId' => $templateId,
			'userId' => $userId,
			'draftId' => $this->resolveDraftId((int)$json->get('draftId'), $templateId, $userId),
			'documentType' => $documentType,
			'fields' => $this->prepareFields($json, $templateId, $tpl),
			'template' => static fn(): array => (new NodesToTemplate(
				(array)$json->get('blocks'),
				(array)$json->get('connections'),
			))->convert(),
			'canEdit' => $canEdit,
		];
	}

	/**
	 * @return array{
	 *     templateId: int,
	 *     userId: int,
	 *     draftId: int,
	 *     documentType: array,
	 *     fields: array,
	 *     template: \Closure,
	 *     canEdit: bool,
	 * }|null
	 */
	private function prepareWriteContext(): ?array
	{
		$context = $this->prepareContext();

		if ($context !== null && !$context['canEdit'])
		{
			$this->addError(ErrorMessage::ACCESS_DENIED->getCodedError());

			return null;
		}

		return $context;
	}

	private function prepareFields(ParameterDictionary $json, int $templateId, ?Tpl $tpl): array
	{
		$template = (array)$json->get('template');

		return [
			'PARAMETERS' => (array)($template['PARAMETERS'] ?? []),
			'VARIABLES' => (array)($template['VARIABLES'] ?? []),
			'CONSTANTS' => (array)($template['CONSTANTS'] ?? []),
			// This controller serves the node editor alone, so a template that does not exist yet is a node
			// one; a saved template answers with the type of its row.
			'TYPE' => $tpl === null ? WorkflowTemplateType::Nodes->value : (string)$tpl->getType(),
			// The marker of the request is never trusted (ALG-01): for a saved template the stored setting
			// answers, and a template that does not exist yet is created marked by its first publication.
			'TEMPLATE_SETTINGS' => $templateId > 0
				? []
				: [ProcessDataMechanicsResolver::MARKER => ProcessDataMechanicsResolver::MARKER_VALUE],
		];
	}

	/**
	 * The draft the canvas was loaded from: the only row of the portal the canvas itself answers for. An
	 * editor that names none excludes none, and a shared value it holds stays on the portal. A number the
	 * request made up is dropped the same way: a draft of another template — or, while the template is not
	 * saved yet, of another author — is somebody else's, and excluding it would free a value it still holds.
	 */
	private function resolveDraftId(int $draftId, int $templateId, int $userId): int
	{
		if ($draftId <= 0)
		{
			return 0;
		}

		$draft = WorkflowTemplateDraftTable::getRow([
			'select' => ['TEMPLATE_ID', 'USER_ID'],
			'filter' => ['=ID' => $draftId],
		]);

		// The author answers for a template that does not exist yet alone: `USER_ID` of a draft of a saved
		// template is rewritten by whoever wrote it last, and a colleague editing after one is not a stranger.
		$ownsDraft = $templateId > 0
			? (int)($draft['TEMPLATE_ID'] ?? 0) === $templateId
			: (int)($draft['TEMPLATE_ID'] ?? 0) === 0 && (int)($draft['USER_ID'] ?? 0) === $userId
		;

		return $ownsDraft ? $draftId : 0;
	}

	/**
	 * @param array $blocks Blocks already changed by an earlier operation; the rest of the fields stand as read.
	 * @param bool $withTemplate The tree of the canvas, built out of every node of the diagram: only the
	 *     operations looking for references or rewriting them read it.
	 */
	private function writeService(
		array $context,
		array $blocks = [],
		bool $withTemplate = true,
	): ProcessDataWriteService
	{
		return new ProcessDataWriteService(
			$blocks + $context['fields'] + ['TEMPLATE' => $withTemplate ? $this->readTemplate($context) : []],
			$context['templateId'],
		);
	}

	private function readTemplate(array $context): array
	{
		return ($context['template'])();
	}

	/**
	 * @return array<int, array{activityName: string, title: string}>
	 */
	private function findUsages(array $context, string $sourceType, string $key): array
	{
		$fields = $context['fields'];

		return (new ProcessDataCatalogService())->findUsages(
			$this->readTemplate($context),
			$sourceType,
			$key,
			$fields['PARAMETERS'],
			$fields['VARIABLES'],
			$fields['CONSTANTS'],
		);
	}

	/**
	 * The answer of a changing action: the new blocks of the data and the rebuilt catalog. The nodes travel
	 * back only where the references inside them were rewritten.
	 *
	 * @param array $documentType Document type of the request, its access already checked. Read only where
	 *     the nodes travel back: their labels name the fields of that very type.
	 */
	private function answerChange(Result $result, bool $withNodes = false, array $documentType = []): ?array
	{
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		$fields = $result->getData();
		$catalog = $this->readCatalog($fields);

		$answer = [
			'template' => [
				'PARAMETERS' => $fields['PARAMETERS'],
				'VARIABLES' => $fields['VARIABLES'],
				'CONSTANTS' => $fields['CONSTANTS'],
			],
			'catalog' => $this->elementsToArray($catalog->getElements()),
		];

		if ($withNodes)
		{
			[$blocks, $connections] = (new TemplateToNodes(
				$fields['TEMPLATE'],
				Feature::instance()->areComplexNodeConnectionsAvailable(),
				// Named by the scheme the write has just produced, so a renamed declaration is named in the
				// answer the way the nodes coming back reference it.
				baseContentBlockContext: $this->readableExpressionContextFactory()
					->createForAuthorizedSchema($fields, $documentType)
				,
			))->convert();

			$answer['blocks'] = $blocks;
			$answer['connections'] = $connections;
		}

		return $answer;
	}

	private function readCatalog(array $fields): ProcessDataCatalog
	{
		return (new ProcessDataCatalogService())->readByBlocks(
			$fields['PARAMETERS'],
			$fields['VARIABLES'],
			$fields['CONSTANTS'],
		);
	}

	private function isNewMechanics(array $context): bool
	{
		return (new ProcessDataMechanicsResolver())
			->isNewMechanicsForFields($context['fields'], $context['templateId'])
		;
	}

	private function readShared(array $context): array
	{
		$documentType = $context['documentType'];
		$userId = $context['userId'];
		$visibility = $this->visibilityNames($documentType);

		return [
			'elements' => GlobalVar::canUserRead($documentType, $userId)
				? $this->elementsToArray(
					(new ProcessDataCatalogService())->readShared($documentType),
					$visibility,
				)
				: [],
			'canUpsert' => GlobalVar::canUserUpsert($documentType, $userId),
			'canDelete' => GlobalVar::canUserDelete($documentType, $userId),
			'visibility' => $visibility,
		];
	}

	/**
	 * The type, the multiplicity, the requirement and the visibility of a shared value are set when it is
	 * created: `onBeforeUpdate` of the globals tables drops these fields from an UPDATE, so a request that
	 * changes them would be answered with success and leave the old schema in the database.
	 */
	private function guardSharedProperty(string $id, array $property, ?array $stored): ?Result
	{
		if ($stored === null)
		{
			return null;
		}

		$normalized = FieldType::normalizeProperty($property);
		$storedProperty = FieldType::normalizeProperty($stored);
		$normalized['Type'] ??= $storedProperty['Type'] ?? FieldType::STRING;

		$visibilityChanged =
			isset($property['Visibility'])
			&& (string)$property['Visibility'] !== (string)($stored['Visibility'] ?? '')
		;

		$schemaChanged =
			$normalized['Type'] !== $storedProperty['Type']
			|| $normalized['Multiple'] !== $storedProperty['Multiple']
			|| $normalized['Required'] !== $storedProperty['Required']
			|| $visibilityChanged
		;

		if (!$schemaChanged)
		{
			return null;
		}

		$result = new Result();
		$result->addError(
			ErrorMessage::PROCESS_DATA_SHARED_SCHEMA_LOCKED->getCodedError(
				['#KEY#' => $id],
				['key' => $id],
			)
		);

		return $result;
	}

	/**
	 * The property as the globals tables keep it: normalized, and with the default value read by the document
	 * service - a boolean, a date, a user of the request reach the table converted and checked, exactly as
	 * `\Bitrix\Bizproc\Controller\GlobalField` writes them.
	 */
	private function prepareSharedProperty(array $documentType, array $property, ?array $stored): Result
	{
		$result = new Result();

		$refusal = $this->refuseUnavailableVisibility($documentType, $property, $stored);
		if ($refusal !== null)
		{
			return $refusal;
		}

		// The property is kept as it came: `Visibility` and the rest of the fields of a shared value are
		// normalized by the manager itself, and only the type the input value is read by is filled in here.
		$storedType = $stored === null ? null : (FieldType::normalizeProperty($stored)['Type'] ?? null);
		$property['Type'] = FieldType::normalizeProperty($property)['Type'] ?? $storedType ?? FieldType::STRING;

		// Every write of a shared value passes here, the move of an element included, so the rule about a
		// type the form does not offer is judged once for both of them.
		$validation = (new DataElementTypeValidator())->validateType((string)$property['Type'], $storedType);
		if (!$validation->isSuccess())
		{
			return $validation;
		}

		$errors = [];
		$runtime = \CBPRuntime::getRuntime();
		$runtime->startRuntime();
		$property['Default'] = $runtime->getDocumentService()->getFieldInputValue(
			$documentType,
			$property,
			'Default',
			$property,
			$errors,
		) ?? '';

		if ($errors !== [])
		{
			// The document service answers with a message of its own field type, and it is the one that names
			// the real cause; the coded error stands in only when the service left the message empty.
			$message = (string)($errors[0]['message'] ?? '');

			$result->addError(
				$message === ''
					? ErrorMessage::INVALID_PARAM_ARG->getCodedError(
						['#PARAM#' => 'Default', '#VALUE#' => ''],
						['property' => 'Default'],
					)
					: new Error($message, ErrorMessage::INVALID_PARAM_ARG->value, ['property' => 'Default'])
			);
		}

		return $result->isSuccess() ? $result->setData(['property' => $property]) : $result;
	}

	private function findSharedElement(array $documentType, string $sourceType, string $id): ?ProcessDataElement
	{
		foreach ((new ProcessDataCatalogService())->readShared($documentType) as $element)
		{
			if ($element->sourceType === $sourceType && $element->key === $id)
			{
				return $element;
			}
		}

		return null;
	}

	/**
	 * The scopes the window offers are the ones a value may be written with: a scope the document type does
	 * not name would answer with success and create a value the catalog never shows again. The scope a value
	 * already stands in is left alone - it is written by the portal and not by this form.
	 */
	private function refuseUnavailableVisibility(array $documentType, array $property, ?array $stored): ?Result
	{
		$visibility = (string)($property['Visibility'] ?? '');

		if (
			$visibility === ''
			|| $visibility === (string)($stored['Visibility'] ?? '')
			|| in_array($visibility, GlobalVar::getAvailableVisibility($documentType), true)
		)
		{
			return null;
		}

		return (new Result())->addError(
			ErrorMessage::INVALID_PARAM_ARG->getCodedError(
				['#PARAM#' => 'visibility', '#VALUE#' => $visibility],
				['visibility' => $visibility],
			),
		);
	}

	/**
	 * @return array<string, string> Visibility code => its short name.
	 */
	private function visibilityNames(array $documentType): array
	{
		$names = GlobalVar::getVisibilityShortNames($documentType);
		$visibility = [];

		foreach (GlobalVar::getAvailableVisibility($documentType) as $code)
		{
			$visibility[$code] = (string)($names[$code] ?? $code);
		}

		return $visibility;
	}

	/**
	 * @param ProcessDataElement[] $elements
	 * @param array<string, string> $visibilityNames
	 */
	private function elementsToArray(array $elements, array $visibilityNames = []): array
	{
		return array_map(
			static fn(ProcessDataElement $element): array => $element->toArray($visibilityNames),
			$elements,
		);
	}

	private function refuseInvalidParam(string $param, string $value): ?array
	{
		$this->addError(
			ErrorMessage::INVALID_PARAM_ARG->getCodedError(['#PARAM#' => $param, '#VALUE#' => $value]),
		);

		return null;
	}

	private function getTpl(int $templateId): ?Tpl
	{
		return WorkflowTemplateTable::query()
			->where('ID', $templateId)
			->whereNull('SYSTEM_CODE')
			->setSelect(['ID', 'TYPE', 'MODULE_ID', 'ENTITY', 'DOCUMENT_TYPE'])
			->setLimit(1)
			->exec()
			->fetchObject()
		;
	}

	private function accessService(): TemplateAccessService
	{
		return $this->accessService ??= new TemplateAccessService();
	}

	/** One instance per request, so the labels it resolves are shared by the whole answer. */
	private function readableExpressionContextFactory(): ReadableExpressionContextFactory
	{
		return $this->readableExpressionContextFactory ??= new ReadableExpressionContextFactory();
	}
}
