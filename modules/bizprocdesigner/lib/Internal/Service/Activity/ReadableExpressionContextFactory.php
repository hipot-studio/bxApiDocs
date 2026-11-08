<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Service\Activity;

use Bitrix\Bizproc\Activity\Dto\ContentBlockContext;
use Bitrix\Bizproc\Activity\Dto\ExpressionLabelDictionary;
use Bitrix\Bizproc\Activity\ExpressionLabelDictionaryBuilder;
use Bitrix\Bizproc\Activity\ReadableExpressionResolverProvider;
use Bitrix\Bizproc\Public\Service\WorkflowTemplate\PilotEditorStateService;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Bizproc\Workflow\Template\WorkflowTemplateDraftTable;
use Bitrix\BizprocDesigner\Internal\Config\Feature;
use Bitrix\BizprocDesigner\Internal\Service\TemplateEditAccess;
use Bitrix\Main\Application;
use Bitrix\Main\Engine\CurrentUser;

/**
 * The single place the editor builds the resolution context of the readable form of an expression in: the
 * one place that asks the rollout option of the feature, and the one place that reads the schema its label
 * dictionary is built from.
 *
 * One instance belongs to one request: the read of the schema and the dictionary built on it are shared by
 * every construction of that request, so the instance lives in a field of the controller and not in a static.
 * A schema is read only under a reference that really occurred.
 *
 * Fail-closed: a feature that is off, a refused access and any raise inside answer with no context at all,
 * which leaves the consumer showing exactly what it showed before the feature existed.
 *
 * Not final on purpose: {@see self::resolveAuthorizedDocumentType()}, {@see self::readActingSchema()} and
 * the three reads it composes are the seams a test replaces.
 */
class ReadableExpressionContextFactory
{
	/** Blocks of a template row the dictionary of labels and the index of steps are read from. */
	private const SCHEMA_FIELDS = ['TEMPLATE', 'PARAMETERS', 'VARIABLES', 'CONSTANTS'];

	private readonly int $userId;

	/** @var array<string, ?ContentBlockContext> template id and document type => context of that pair */
	private array $contexts = [];

	/** @var array<string, ?array> template id and document type => authorized document type of that pair */
	private array $authorizedDocumentTypes = [];

	/** @var array<int, array> template id => its acting schema, read at most once per request */
	private array $schemas = [];

	/**
	 * Builder of every dictionary of this request: it carries the descriptors of the steps it has already
	 * described, so the two schemas of one template describe a step they both carry once.
	 */
	private ?ExpressionLabelDictionaryBuilder $dictionaryBuilder = null;

	public function __construct(?int $userId = null)
	{
		$this->userId = $userId ?? (int)(CurrentUser::get()->getId() ?? 0);
	}

	/**
	 * Context for a schema the caller has already read, and for a document type the caller has already
	 * authorized: unlike {@see self::createForTemplate()}, this method checks no rights of its own.
	 *
	 * Not memoized on purpose: one request converts more than one schema (the acting one and the published
	 * one of the same template), and each of them names its own steps. The descriptors of those steps are
	 * shared all the same - they belong to the builder of this request and not to one dictionary.
	 *
	 * @param array $schema Template row, or the same shape assembled by the caller: TEMPLATE with the tree
	 *     of the activities, PARAMETERS, VARIABLES and CONSTANTS with the declarations.
	 * @param array $documentType Document type the fields of that schema are named in. Access to it is a
	 *     precondition of the call: the fields of that type are read with no check of rights here.
	 */
	public function createForAuthorizedSchema(array $schema, array $documentType): ?ContentBlockContext
	{
		try
		{
			if (!$this->isAvailable())
			{
				return null;
			}

			return $this->createContext($documentType, $this->buildDeferredLabels(static fn(): array => $schema));
		}
		catch (\Throwable $e)
		{
			return $this->refuse($e);
		}
	}

	/**
	 * Context for a template named by its id alone - the paths of the settings panel, which hold no schema.
	 * Access is checked here, and the acting schema is read once per template per request and only under a
	 * reference that really occurred: the row and the draft are the two heaviest columns of the module.
	 *
	 * A template that does not exist yet gets a context with an empty dictionary: a reference to one of its
	 * declarations stays unresolved, while the fields of its document type are named as everywhere else.
	 *
	 * @param array $documentType Document type of the request. Empty is admitted for a saved template alone,
	 *     whose row names its own type.
	 */
	public function createForTemplate(int $templateId, array $documentType): ?ContentBlockContext
	{
		try
		{
			if (!$this->isAvailable())
			{
				return null;
			}

			$cacheKey = self::makeTemplateKey($templateId, $documentType);
			if (!array_key_exists($cacheKey, $this->contexts))
			{
				$this->contexts[$cacheKey] = $this->buildForTemplate($templateId, $documentType);
			}

			return $this->contexts[$cacheKey];
		}
		catch (\Throwable $e)
		{
			return $this->refuse($e);
		}
	}

	/**
	 * The answer {@see self::resolveAuthorizedDocumentType()} would give, told by a path that has just made
	 * that very check itself
	 * ({@see \Bitrix\BizprocDesigner\Infrastructure\Controller\Activity\Complex::canLoadRelationAutofill()}),
	 * so the rights of one template are not walked twice in one request.
	 *
	 * @param array $authorizedDocumentType Document type the checked access answered with, null when refused.
	 */
	public function declareAuthorizedDocumentType(
		int $templateId,
		array $documentType,
		?array $authorizedDocumentType,
	): void
	{
		$this->authorizedDocumentTypes[self::makeTemplateKey($templateId, $documentType)] = $authorizedDocumentType;
	}

	/**
	 * Whether the current user may read the acting schema of that template, and the document type its fields
	 * are named in - checked as the panel paths already check it: the ACL of a saved template, the right to
	 * create a workflow of the document type of one that does not exist yet. Null means refused.
	 *
	 * The type of a saved template is answered by its own row, so the schema and the names of its fields
	 * come from one record and a request naming another type is refused.
	 */
	protected function resolveAuthorizedDocumentType(int $templateId, array $documentType): ?array
	{
		if ($templateId <= 0)
		{
			// With no schema and no document type there is nothing to name at all.
			return $documentType !== [] && $this->canCreateWorkflow($documentType) ? $documentType : null;
		}

		$access = new TemplateEditAccess();
		$template = $access->resolve($templateId);
		if ($template === null || !$access->canEdit($template, $this->userId))
		{
			return null;
		}

		$templateDocumentType = $template->getDocumentComplexType();
		if ($documentType !== [] && !$this->isSameDocumentType($documentType, $templateDocumentType))
		{
			return null;
		}

		return $templateDocumentType;
	}

	/**
	 * The acting schema of a saved template: the freshest draft, failing that the version the pilot of the
	 * template runs, failing that the published row - block by block and in the very order the canvas is
	 * built in ({@see \Bitrix\BizprocDesigner\Infrastructure\Controller\Diagram::getTemplateData()}), so
	 * the panel names a declaration exactly as the canvas names it. A declaration renamed in a live pilot is
	 * therefore not named by the row that pilot was published from.
	 *
	 * @return array Blocks of the schema: TEMPLATE, PARAMETERS, VARIABLES, CONSTANTS.
	 */
	protected function readActingSchema(int $templateId): array
	{
		$overlay = $this->readDraftOverlay($templateId) ?? $this->readPilotOverlay($templateId) ?? [];

		return array_intersect_key($overlay, array_flip(self::SCHEMA_FIELDS))
			+ $this->readTemplateRow($templateId)
		;
	}

	/** @return array Blocks of the published row, empty when the template carries none. */
	protected function readTemplateRow(int $templateId): array
	{
		$row = WorkflowTemplateTable::query()
			->where('ID', $templateId)
			->setSelect(self::SCHEMA_FIELDS)
			->setLimit(1)
			->fetch()
		;

		return is_array($row) ? $row : [];
	}

	/**
	 * Blocks of the freshest draft of the template, null when it has no draft at all. A draft that exists
	 * wins even when it carries no block: the canvas chooses its overlay by the very same condition.
	 */
	protected function readDraftOverlay(int $templateId): ?array
	{
		$draft = WorkflowTemplateDraftTable::getLatestDraftWithDataByTemplateId($templateId);
		if ($draft === null)
		{
			return null;
		}

		return is_array($draft['TEMPLATE_DATA'] ?? null) ? $draft['TEMPLATE_DATA'] : [];
	}

	/**
	 * Blocks of the version the pilot of the template runs, null when no pilot acts on it - and null on
	 * every portal the pilot publications are off on, which the state answers with no query of its own.
	 */
	protected function readPilotOverlay(int $templateId): ?array
	{
		return (new PilotEditorStateService())->getEditorState($templateId)->executableFields;
	}

	private function buildForTemplate(int $templateId, array $documentType): ?ContentBlockContext
	{
		$authorizedDocumentType = $this->authorizedDocumentType($templateId, $documentType);
		if ($authorizedDocumentType === null)
		{
			return null;
		}

		if ($templateId <= 0)
		{
			return $this->createContext($authorizedDocumentType, new ExpressionLabelDictionary());
		}

		$readSchema = fn(): array => $this->schemas[$templateId] ??= $this->readActingSchema($templateId);

		return $this->createContext(
			$authorizedDocumentType,
			$this->buildDeferredLabels($readSchema),
		);
	}

	/** The access of one pair, checked at most once per request: told by the request path when it knows it. */
	private function authorizedDocumentType(int $templateId, array $documentType): ?array
	{
		$key = self::makeTemplateKey($templateId, $documentType);
		if (!array_key_exists($key, $this->authorizedDocumentTypes))
		{
			$this->authorizedDocumentTypes[$key] = $this->resolveAuthorizedDocumentType($templateId, $documentType);
		}

		return $this->authorizedDocumentTypes[$key];
	}

	private static function makeTemplateKey(int $templateId, array $documentType): string
	{
		return $templateId . ':' . implode('|', $documentType);
	}

	private function createContext(array $documentType, ExpressionLabelDictionary $labels): ContentBlockContext
	{
		// A node of the schema may work in a document type of its own, so the context carries the resolvers of
		// the request by type and not one resolver alone. A foreign type reaches the very gate the panel paths
		// check it by, asked at most once per type; the type authorized here needs no second check.
		$provider = new ReadableExpressionResolverProvider(
			$documentType,
			$labels,
			$this->canCreateWorkflow(...),
		);

		// The scope stays the business of the pass that walks the activities and is not assembled here.
		return new ContentBlockContext(
			readableExpressionResolver: $provider->getResolver(),
			readableExpressionResolverProvider: $provider,
		);
	}

	/**
	 * The dictionary of one schema, the read of that schema included: the provider is called on the first
	 * reference that really occurred, so a value referencing neither a declaration nor a step output costs
	 * neither the read of the schema nor the walk of its tree.
	 *
	 * @param callable(): array $readSchema Blocks of the schema: TEMPLATE, PARAMETERS, VARIABLES, CONSTANTS.
	 */
	private function buildDeferredLabels(callable $readSchema): ExpressionLabelDictionary
	{
		return $this->dictionaryBuilder()->buildDeferredDictionary(function () use ($readSchema): array {
			try
			{
				$schema = $readSchema();

				return [
					$schema,
					iterator_to_array($this->walkActivities((array)($schema['TEMPLATE'] ?? [])), false),
				];
			}
			catch (\Throwable $e)
			{
				// Fail-closed: an unreadable schema costs its labels and never the answer of the request.
				$this->logRefusal($e);

				return [[], []];
			}
		});
	}

	private function dictionaryBuilder(): ExpressionLabelDictionaryBuilder
	{
		return $this->dictionaryBuilder ??= new ExpressionLabelDictionaryBuilder();
	}

	/**
	 * Every activity of the tree at any depth: a step of a nested branch is referenced exactly as a step of
	 * the root one.
	 *
	 * @return \Generator<array>
	 */
	private function walkActivities(array $activities): \Generator
	{
		foreach ($activities as $activity)
		{
			if (!is_array($activity))
			{
				continue;
			}

			yield $activity;

			if (is_array($activity['Children'] ?? null))
			{
				yield from $this->walkActivities($activity['Children']);
			}
		}
	}

	private function isAvailable(): bool
	{
		return Feature::instance()->isReadableExpressionsAvailable();
	}

	/**
	 * Not private on purpose: it is the gate the resolvers of foreign document types are admitted by, and a
	 * test replaces it the way it replaces the other seams of this factory.
	 */
	protected function canCreateWorkflow(array $documentType): bool
	{
		return \CBPDocument::CanUserOperateDocumentType(
			\CBPCanUserOperateOperation::CreateWorkflow,
			$this->userId,
			$documentType,
		);
	}

	/** The very normalisation the panel paths compare a submitted document type with a stored one by. */
	private function isSameDocumentType(array $documentType, array $otherDocumentType): bool
	{
		return array_map(strval(...), array_values($documentType))
			=== array_map(strval(...), array_values($otherDocumentType))
		;
	}

	/** Fail-closed: a context that could not be built must not turn into an error of the request. */
	private function refuse(\Throwable $e): ?ContentBlockContext
	{
		$this->logRefusal($e);

		return null;
	}

	private function logRefusal(\Throwable $e): void
	{
		Application::getInstance()->getExceptionHandler()->writeToLog($e);
	}
}
