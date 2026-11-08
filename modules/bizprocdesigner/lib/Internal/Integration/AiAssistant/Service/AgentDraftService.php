<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service;

use Bitrix\Bizproc\Api\Data\WorkflowTemplateHistoryService\TemplateChangeAttribution;
use Bitrix\Bizproc\Api\Enum\Template\TemplateChangeRequestSource;
use Bitrix\Bizproc\Api\Request\WorkflowTemplateService\SaveTemplateDraftRequest;
use Bitrix\Bizproc\Api\Response\WorkflowTemplateService\SaveTemplateDraftResponse;
use Bitrix\Bizproc\Api\Service\WorkflowTemplateService;
use Bitrix\Bizproc\Public\Entity\Activity\SetupTemplateActivity\SetupConstantDeclaration;
use Bitrix\Bizproc\Workflow\Template\Converter\NodesToTemplate;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\BizprocDesigner\Internal\Config\Feature;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentBlockCollection;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentConnectionCollection;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentTemplateMetadata;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\Draft;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\WorkflowTemplateIdentifier;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Enum\RequestSource;
use Bitrix\BizprocDesigner\Internal\Service\Container;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DI\Exception\CircularDependencyException;
use Bitrix\Main\DI\Exception\ServiceNotFoundException;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class AgentDraftService
{
	private const CONSTANTS_FIELD = 'CONSTANTS';

	private const META_FIELDS = [
		'NAME',
		'DESCRIPTION',
		'PARAMETERS',
		'VARIABLES',
		self::CONSTANTS_FIELD,
		'TEMPLATE_SETTINGS',
		'AUTO_EXECUTE',
	];

	/** The value of one constant, the key both halves of the source split below are about. */
	private const CONSTANT_DEFAULT = 'Default';

	/**
	 * The keys a wizard form owns and therefore overwrites on an inherited constant. Requiredness and the value
	 * are absent on purpose: the form always produces `Required: 0` and a value collected from the wizard owner
	 * long after the form was authored, so writing either back would take away what the owner set (ALG-02).
	 */
	private const FORM_OWNED_FIELDS = ['Name', 'Description', 'Type', 'Multiple', 'Options', 'Settings'];

	/**
	 * The channel of {@see SetupTemplateNodePolicy}: a refusal of a wizard node and the application of what an
	 * admitted one declares are two outcomes for one node, and splitting them would make an incident be read in
	 * two places.
	 */
	private const LOGGER_ID = 'bizprocdesigner.aiassistant.setup_template_policy';

	/** Longest agent-authored value a diagnostic record keeps, as the node policies keep theirs. */
	private const LOG_VALUE_MAX_LENGTH = 128;

	/**
	 * Codes one list of a record names. The template may carry up to
	 * {@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator\AgentBlocksValidator::MAX_SETUP_DECLARATIONS}
	 * of them, and a record growing with that bound would push the neighbouring records out of the channel; the
	 * count of what was cut stays in, so a truncated list never reads as the whole of it.
	 */
	private const LOG_CODES_MAX_COUNT = 16;

	private readonly AiAssistantDraftConverterService $draftConverterService;
	private readonly AiAssistantDraftCreatorService $draftCreatorService;

	/**
	 * The reader of the bound draft, shared with the validation context of the same entry: both need the
	 * TEMPLATE_DATA blob of one draft, and the resolver is where that read is memoised.
	 */
	private readonly AgentWorkflowResolverService $resolverService;

	private ?LoggerInterface $logger;

	/**
	 * @throws NotFoundExceptionInterface
	 * @throws CircularDependencyException
	 * @throws ObjectNotFoundException
	 * @throws ServiceNotFoundException
	 */
	public function __construct(
		?AiAssistantDraftConverterService $draftConverterService = null,
		?AiAssistantDraftCreatorService $draftCreatorService = null,
		?LoggerInterface $logger = null,
		?AgentWorkflowResolverService $resolverService = null,
	)
	{
		$this->draftConverterService = $draftConverterService
			?? Container::getAiAssistantDraftConverterService();
		$this->draftCreatorService = $draftCreatorService
			?? Container::getAiAssistantDraftCreatorService();
		$this->resolverService = $resolverService ?? Container::getAgentWorkflowResolverService();
		$this->logger = $logger;
	}

	/**
	 * Persists validated agent graph as template draft and pushes it to the open editor via pull.
	 *
	 * Holding an {@see AuthorizedTemplateSubject} already means the push entry authorized this template for this
	 * user, so no document-access check is repeated here.
	 *
	 * The source is required rather than defaulted: it decides which REST-only branches of the conversion run,
	 * so a caller that forgets it must not silently get the wider one.
	 *
	 * @param list<SetupConstantDeclaration> $setupConstantDeclarations constants the setup wizard forms of the
	 *        graph declare, as the validator read them (TPL-03). Empty by default, and an empty set leaves this
	 *        method behaving exactly as it did before the parameter existed - an entry that carries no wizard node
	 *        outside the published baseline writes the constants it always wrote.
	 * @param AgentTemplateMetadata $templateMetadata validated fields submitted with the graph
	 */
	public function saveAndPush(
		AuthorizedTemplateSubject $subject,
		AgentBlockCollection $blocks,
		AgentConnectionCollection $connections,
		RequestSource $source,
		array $setupConstantDeclarations = [],
		AgentTemplateMetadata $templateMetadata = new AgentTemplateMetadata(),
	): Result
	{
		$identifier = $subject->toIdentifier();
		$userId = $subject->userId;

		$draft = $this->draftConverterService->covertFromAgentInput(
			draftId: $identifier->draftId ?? 0,
			templateId: $identifier->templateId ?? 0,
			userId: $userId,
			blocks: $blocks,
			connections: $connections,
			documentType: $identifier->documentDescription->toBizprocComplexType(),
			source: $source,
			boundBlocks: $this->resolverService->getBoundBlocks($identifier),
		);

		$persistResult = $this->persist(
			$identifier,
			$userId,
			$draft,
			$source,
			$setupConstantDeclarations,
			$templateMetadata,
		);
		if (!$persistResult->isSuccess())
		{
			return $persistResult;
		}

		$persistedDraftId = $persistResult->getTemplateDraftId();

		// The blob the fields of this write were assembled from has just been replaced.
		$this->resolverService->forgetDraftTemplateData($persistedDraftId);

		if ($persistedDraftId !== $draft->draftId)
		{
			$draft = new Draft(
				$persistedDraftId,
				$draft->templateId,
				$draft->userId,
				$draft->blocks,
				$draft->connections,
			);
		}

		$this->draftCreatorService->pushDraft($draft, $persistResult->getDraftRevision(), $templateMetadata);

		return $persistResult;
	}

	/**
	 * @param list<SetupConstantDeclaration> $setupConstantDeclarations
	 */
	private function persist(
		WorkflowTemplateIdentifier $identifier,
		int $userId,
		Draft $draft,
		RequestSource $source,
		array $setupConstantDeclarations,
		AgentTemplateMetadata $templateMetadata,
	): SaveTemplateDraftResponse
	{
		$connections = array_map(
			static fn(array $c): array => $c + ['createdAt' => null],
			$draft->connections->toArray(),
		);

		$converter = new NodesToTemplate($draft->blocks->toArray(), $connections);

		$fields = $this->prepareFields(
			$identifier,
			$userId,
			$source,
			$setupConstantDeclarations,
			$templateMetadata,
		);
		$fields['DOCUMENT_TYPE'] = $identifier->documentDescription->toBizprocComplexType();
		$fields['TEMPLATE'] = $converter->convert();

		$request = new SaveTemplateDraftRequest(
			templateId: (int)($identifier->templateId ?? 0),
			parameters: [],
			fields: $fields,
			user: new \CBPWorkflowTemplateUser($userId),
			checkAccess: false,
			draftId: (int)($identifier->draftId ?? 0),
			attribution: TemplateChangeAttribution::forAi(
				$source->value,
				$userId,
				self::toChangeRequestSource($source),
			),
			useEditConflictGuard: Feature::instance()->isTemplateEditLockAvailable(),
			fieldsToClaim: $templateMetadata->claimedFields(),
		);

		return (new WorkflowTemplateService())->saveTemplateDraft($request);
	}

	private static function toChangeRequestSource(RequestSource $source): TemplateChangeRequestSource
	{
		return match ($source)
		{
			RequestSource::Rest => TemplateChangeRequestSource::ExternalAgent,
			RequestSource::Marta => TemplateChangeRequestSource::Marta,
		};
	}

	/**
	 * @param list<SetupConstantDeclaration> $setupConstantDeclarations
	 *
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function prepareFields(
		WorkflowTemplateIdentifier $identifier,
		int $userId,
		RequestSource $source,
		array $setupConstantDeclarations,
		AgentTemplateMetadata $templateMetadata,
	): array
	{
		$fields = [
			'NAME' => '',
			'DESCRIPTION' => '',
			'PARAMETERS' => [],
			'VARIABLES' => [],
			self::CONSTANTS_FIELD => [],
			'TEMPLATE_SETTINGS' => [],
			'AUTO_EXECUTE' => \CBPDocumentEventType::None,
		];

		$published = $this->getPublishedTemplateFields((int)($identifier->templateId ?? 0));
		$draftData = $this->resolverService->getDraftTemplateData((int)($identifier->draftId ?? 0));
		$submitted = $templateMetadata->toTemplateFields();

		$inheritedMetadata = $this->resolverService->getEffectiveMetadata($identifier);

		foreach (self::META_FIELDS as $field)
		{
			if (isset($submitted[$field]))
			{
				$fields[$field] = $submitted[$field];
			}
			elseif (array_key_exists($field, $inheritedMetadata))
			{
				$fields[$field] = $inheritedMetadata[$field];
			}
			elseif (isset($draftData[$field]))
			{
				$fields[$field] = $draftData[$field];
			}
			elseif (isset($published[$field]))
			{
				$fields[$field] = $published[$field];
			}
		}

		if ($setupConstantDeclarations !== [])
		{
			$publishedConstants = $published[self::CONSTANTS_FIELD] ?? [];
			$fields[self::CONSTANTS_FIELD] = $this->applySetupDeclarations(
				is_array($fields[self::CONSTANTS_FIELD]) ? $fields[self::CONSTANTS_FIELD] : [],
				is_array($publishedConstants) ? $publishedConstants : [],
				$setupConstantDeclarations,
				$identifier,
				$userId,
				$source,
			);
		}

		return $fields;
	}

	/**
	 * Applies what the wizard forms of the graph declare to the constants this write carries (ALG-02).
	 *
	 * One-way by design: a code the forms declare is added or updated, and one they no longer mention is left
	 * alone - the agent sees none of its consequences, while a constant is read by the expressions of the template
	 * and by the scheduler alike, so the cost of a wrong deletion is nothing like the cost of a stale declaration.
	 * The owner clears those in the designer, where deletion is visible.
	 *
	 * An inherited constant is MERGED field by field rather than replaced: a declaration is richer than a form -
	 * its owner completed it in the designer - and replacing it wholesale would drop what the form has no notion
	 * of.
	 *
	 * The definition comes from the source the agent updates and the value from the published template, which is
	 * the rule this write borrows rather than invents: the process engine holds it at system import
	 * ({@see \CBPWorkflowTemplateLoader::importTemplateFromArray()}) and when a launched agent copy is upgraded
	 * ({@see \Bitrix\Bizproc\Internal\Service\AiAgentGrid\Upgrade\ConstantMergeService}). Without it the ban on
	 * overwriting a value would buy nothing: a draft freezes the constants as they stood when it was created, and
	 * this very method prefers the draft to the published version, so publishing a long-lived draft would drop
	 * every value the wizard collected after it.
	 *
	 * The published value is taken whether or not this write already carries the code: a code the published wizard
	 * declares and the draft snapshot predates is INHERITED here rather than authored anew, or the value its owner
	 * has collected since would be replaced by the one the agent put in the form. A published declaration carrying
	 * no value key at all leaves the value alone - it says the value was never declared, not that it was cleared.
	 *
	 * @param array<string, mixed> $constants constants of this write - the draft's, or the published ones
	 * @param array<string, mixed> $publishedConstants constants of the published template, the source of a value
	 * @param list<SetupConstantDeclaration> $declarations
	 *
	 * @return array<string, mixed>
	 */
	private function applySetupDeclarations(
		array $constants,
		array $publishedConstants,
		array $declarations,
		WorkflowTemplateIdentifier $identifier,
		int $userId,
		RequestSource $source,
	): array
	{
		$added = [];
		$updated = [];

		foreach ($declarations as $declaration)
		{
			$code = $declaration->code;
			$formFields = self::declarationFields($declaration);
			$publishedDeclaration = is_array($publishedConstants[$code] ?? null)
				? $publishedConstants[$code]
				: null
			;

			if (!array_key_exists($code, $constants))
			{
				$constants[$code] = $publishedDeclaration === null
					? $formFields
					: self::withFormOwnedFields($publishedDeclaration, $formFields)
				;
				$added[] = $code;

				continue;
			}

			$current = is_array($constants[$code]) ? $constants[$code] : [];
			$target = self::withFormOwnedFields($current, $formFields);

			if ($publishedDeclaration !== null && array_key_exists(self::CONSTANT_DEFAULT, $publishedDeclaration))
			{
				$target[self::CONSTANT_DEFAULT] = $publishedDeclaration[self::CONSTANT_DEFAULT];
			}

			if ($target !== $current)
			{
				$updated[] = $code;
			}

			$constants[$code] = $target;
		}

		if ($added !== [] || $updated !== [])
		{
			$this->reportDeclarationsApplied(
				$identifier,
				$userId,
				$source,
				$added,
				$updated,
				self::countWizardCodes($constants, $declarations),
			);
		}

		return $constants;
	}

	/**
	 * An inherited declaration with the fields a form owns written over it, the merge both branches above share.
	 * What is not listed in {@see self::FORM_OWNED_FIELDS} stays as the inherited declaration carries it.
	 *
	 * @param array<string, mixed> $inherited
	 * @param array<string, mixed> $formFields
	 *
	 * @return array<string, mixed>
	 */
	private static function withFormOwnedFields(array $inherited, array $formFields): array
	{
		foreach (self::FORM_OWNED_FIELDS as $field)
		{
			$inherited[$field] = $formFields[$field];
		}

		return $inherited;
	}

	/**
	 * One constant exactly as the wizard editor writes it (bizproc setup-template-activity: utils.js,
	 * convertConstants), so a constant an agent declares and a constant its owner declares through the same form
	 * are one and the same declaration.
	 *
	 * @return array<string, mixed>
	 */
	private static function declarationFields(SetupConstantDeclaration $declaration): array
	{
		return [
			'Name' => $declaration->name,
			'Description' => $declaration->description,
			'Type' => $declaration->type,
			'Required' => 0,
			'Multiple' => $declaration->multiple ? 1 : 0,
			'Options' => $declaration->options,
			self::CONSTANT_DEFAULT => $declaration->default,
			'Settings' => $declaration->settings,
		];
	}

	/**
	 * How many constants of the template a setup wizard is behind, once this write is applied. The number is what
	 * the record carries instead of the whole code list: it is the value the declaration ceiling of the reverse
	 * entry is measured against, so a record shows how close the template stands to it without growing with it.
	 *
	 * A code counts when this write declares it or when it carries the code form the wizard editor produces
	 * ({@see SetupTemplateNodePolicy::isEditorAuthoredCode()}) - the second half is what keeps the constants of the
	 * wizard nodes the write did not touch in the count. The form is asked for by the very predicate the ceiling is
	 * measured with, or the number a record carries would not be the number a refusal was made on.
	 *
	 * @param array<string, mixed> $constants
	 * @param list<SetupConstantDeclaration> $declarations
	 */
	private static function countWizardCodes(array $constants, array $declarations): int
	{
		$declared = [];
		foreach ($declarations as $declaration)
		{
			$declared[$declaration->code] = true;
		}

		$count = 0;
		foreach (array_keys($constants) as $code)
		{
			$code = (string)$code;
			if (isset($declared[$code]) || SetupTemplateNodePolicy::isEditorAuthoredCode($code))
			{
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Records an applied change in the diagnostic channel, so an edit of the constants made by an agent - which its
	 * owner does not watch happen - stays readable afterwards. A write that changed neither the composition nor the
	 * metadata says nothing: a record per entry would bury the ones that mean something.
	 *
	 * Codes alone travel into the channel. A name, a description and a value are the owner's data, and a diagnostic
	 * record is no place for them; the codes are agent-authored and bounded by nothing, so they are cut both in
	 * length and in number.
	 *
	 * @param list<string> $added
	 * @param list<string> $updated
	 */
	private function reportDeclarationsApplied(
		WorkflowTemplateIdentifier $identifier,
		int $userId,
		RequestSource $source,
		array $added,
		array $updated,
		int $wizardCodeCount,
	): void
	{
		$this->getLogger()->info(
			'AI reverse entry: setup wizard constants applied',
			[
				'templateId' => $identifier->templateId,
				'draftId' => $identifier->draftId,
				'source' => $source->value,
				'userId' => $userId,
				'added' => self::codesForLog($added),
				'updated' => self::codesForLog($updated),
				'wizardConstants' => $wizardCodeCount,
			],
		);
	}

	/**
	 * @param list<string> $codes
	 *
	 * @return list<string>
	 */
	private static function codesForLog(array $codes): array
	{
		$kept = array_map(self::forLog(...), array_slice($codes, 0, self::LOG_CODES_MAX_COUNT));

		$cut = count($codes) - count($kept);
		if ($cut > 0)
		{
			$kept[] = "... and {$cut} more";
		}

		return $kept;
	}

	/**
	 * A constant code is agent-authored and bounded by nothing: written as it came, a code with newlines would
	 * forge records of its own inside the channel and a kilobyte-long one would push the real records out of it.
	 *
	 * A null is PCRE giving up, not a cleaned value: casting it to a string would leave the entry naming nothing,
	 * so the code is logged as it stands - a record that reads awkwardly still beats a record that says nothing.
	 */
	private static function forLog(string $value): string
	{
		$singleLine = preg_replace('/[[:cntrl:]]+/', ' ', $value) ?? $value;

		return mb_strlen($singleLine) > self::LOG_VALUE_MAX_LENGTH
			? mb_substr($singleLine, 0, self::LOG_VALUE_MAX_LENGTH) . '...'
			: $singleLine;
	}

	private function getLogger(): LoggerInterface
	{
		return $this->logger ??= (new LoggerFactory())->createById(self::LOGGER_ID) ?? new NullLogger();
	}

	/**
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	private function getPublishedTemplateFields(int $templateId): array
	{
		if ($templateId <= 0)
		{
			return [];
		}

		$row = WorkflowTemplateTable::query()
			->where('ID', $templateId)
			->setSelect(['NAME', 'DESCRIPTION', 'PARAMETERS', 'VARIABLES', 'CONSTANTS', 'AUTO_EXECUTE'])
			->setLimit(1)
			->fetch()
		;

		return is_array($row) ? $row : [];
	}
}
