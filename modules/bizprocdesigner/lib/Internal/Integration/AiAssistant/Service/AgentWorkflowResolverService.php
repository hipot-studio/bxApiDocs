<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service;

use Bitrix\Bizproc\Api\Enum\Template\WorkflowTemplateType;
use Bitrix\Bizproc\Workflow\Template\AgentOwnedMetadata;
use Bitrix\Bizproc\Workflow\Template\Converter\NodesToTemplate;
use Bitrix\Bizproc\Workflow\Template\EO_WorkflowTemplateDraft;
use Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplate;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Bizproc\Workflow\Template\WorkflowTemplateDraftTable;
use Bitrix\BizprocDesigner\Internal\Config\Feature;
use Bitrix\BizprocDesigner\Internal\Entity\DocumentDescription;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentTemplate;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\WorkflowTemplateIdentifier;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Enum\RequestSource;
use Bitrix\BizprocDesigner\Internal\Service\Container;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DI\Exception\CircularDependencyException;
use Bitrix\Main\DI\Exception\ServiceNotFoundException;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Psr\Container\NotFoundExceptionInterface;

final class AgentWorkflowResolverService
{
	private readonly AiAssistantWorkflowTemplateConverterService $converterService;

	/**
	 * @var array<int, EO_WorkflowTemplate|null> request-scoped memo of the bound template row. One reverse call
	 *      reads it twice - once to resolve the identifier, once to build the enforcement baseline - and the
	 *      published row cannot change in between (push only ever writes a draft).
	 */
	private array $templateById = [];

	/** @var array<int, array{TEMPLATE_DATA: array, STATUS: int}> */
	private array $draftRowById = [];

	public function __construct(
		?AiAssistantWorkflowTemplateConverterService $converterService = null,
	)
	{
		$this->converterService = $converterService
			?? Container::getAiAssistantWorkflowTemplateConverterService();
	}

	/**
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function resolveByTemplateId(int $templateId, ?int $userId = null): ?WorkflowTemplateIdentifier
	{
		$template = $this->getTemplate($templateId);
		if ($template === null)
		{
			return null;
		}
		$draft = $this->getNewestDraft(
			$templateId,
			Feature::instance()->isTemplateEditLockAvailable() ? null : $userId,
		);

		return new WorkflowTemplateIdentifier(
			documentDescription: new DocumentDescription(
				module: $template->getModuleId(),
				entityType: $template->getEntity(),
				documentType: $template->getDocumentType(),
			),
			templateId: $template->getId(),
			draftId: $draft?->getId(),
			name: $template->getName(),
			modified: $template->getModified(),
		);
	}

	/**
	 * Read-path graph of template.get: the bound DRAFT when the identifier carries one, the published version
	 * otherwise. Deliberately not the baseline source of {@see self::getPublishedAgentTemplate()} - a reverse write
	 * lands in the draft, so a reader denied it would see neither its own last push nor the edits of the user.
	 *
	 * The two therefore diverge while the draft carries a block type the published version lacks: the node is
	 * emitted here and refused on the way back, because the EXCLUDED bypass of
	 * {@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\ActivityVisibilityFilter} demands a published
	 * baseline entry. Only the reverse-visible classes can reach that state, and their EXCLUDED tracks a switchable
	 * portal feature, so a draft written while the feature was on is a record of what was once allowed and never a
	 * licence for now - widening the baseline with draft types would hand that licence over. Serving the published
	 * version here instead would hide the draft from its own author.
	 *
	 * The reverse path reads this graph for one thing besides answering template.get: the option values it carries,
	 * which relax the option check and no barrier ({@see ValidationContextFactory}, {@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator\ValidationContext::$draftOptionValues}).
	 * That is the same reasoning read the other way round - what the agent was handed it must be able to hand back -
	 * and it stops where authorisation begins.
	 *
	 * @throws NotFoundExceptionInterface
	 * @throws ObjectNotFoundException
	 * @throws ServiceNotFoundException
	 * @throws ObjectPropertyException
	 * @throws CircularDependencyException
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	public function getAgentTemplate(WorkflowTemplateIdentifier $identifier): ?AgentTemplate
	{
		$templateData = $this->getDraftOrTemplateData($identifier);
		if (!$templateData)
		{
			return null;
		}

		// REST agent read-path: the external agent is the only reader that receives frame overlays.
		$result = $this->converterService->convertFromTemplateArrayToAgentTemplate($templateData, RequestSource::Rest);
		if (!$result->isSuccess())
		{
			Container::getDefaultLogger()->error(
				'Agent workflow resolver convert errors: ' . implode(',', $result->getErrorMessages()),
			);

			return null;
		}

		return $this->converterService->getAgentTemplate();
	}

	/**
	 * Published bound-template graph, for enforcement baselines. Loads the persisted
	 * {@see WorkflowTemplateTable} TEMPLATE (never the agent draft - {@see self::getDraftOrTemplateData} would
	 * return the draft and make the check circular) and runs the same forward converter (RequestSource::Rest)
	 * template.get uses, so a node of that version fingerprints identically on both sides. What the agent actually
	 * received on read may be the draft instead - {@see self::getAgentTemplate()} names what that costs.
	 *
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getPublishedAgentTemplate(int $templateId): ?AgentTemplate
	{
		$templateData = $this->getTemplate($templateId)?->getTemplate();
		if (!$templateData)
		{
			return null;
		}

		$result = $this->converterService->convertFromTemplateArrayToAgentTemplate($templateData, RequestSource::Rest);
		if (!$result->isSuccess())
		{
			Container::getDefaultLogger()->error(
				'Published baseline convert errors: ' . implode(',', $result->getErrorMessages()),
			);

			return null;
		}

		return $this->converterService->getAgentTemplate();
	}

	/**
	 * Every activity name the published bound template carries, at any depth of its tree, keyed as written.
	 *
	 * Read from the RAW published row rather than from the agent projection of {@see self::getPublishedAgentTemplate()}:
	 * a complex node runs its sub-actions as children of its own, the runtime resolves a reference to one of them
	 * by name ({@see \CBPWorkflow::getActivityByName()}), and the projection carries no name for such a child at
	 * all - its rules hold `{activityCode, settings}` and nothing more. The shipped system agents address exactly
	 * such a child, so without this reading an expression the server itself wrote reads as a block that does not
	 * exist ({@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator\ValidationContext::$baselinePublishedNames}).
	 *
	 * Walked by the same rule as the by-name lookup of the runtime: depth-first, a node before its children. Empty
	 * when the template has no published version.
	 *
	 * @return array<string, true>
	 *
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getPublishedTemplateActivityNames(int $templateId): array
	{
		$templateData = $this->getTemplate($templateId)?->getTemplate();

		$names = [];
		if (is_array($templateData))
		{
			self::collectActivityNames($templateData, $names);
		}

		return $names;
	}

	/**
	 * @param array<array-key, mixed> $activities
	 * @param array<string, true> $names
	 */
	private static function collectActivityNames(array $activities, array &$names): void
	{
		foreach ($activities as $activity)
		{
			if (!is_array($activity))
			{
				continue;
			}

			$name = is_scalar($activity['Name'] ?? null) ? (string)$activity['Name'] : '';
			if ($name !== '')
			{
				$names[$name] = true;
			}

			if (is_array($activity['Children'] ?? null))
			{
				self::collectActivityNames($activity['Children'], $names);
			}
		}
	}

	/**
	 * Identity and saved geometry of every block of the bound graph, keyed by block id (AGT-ALG-01). The graph
	 * is the very one {@see self::getAgentTemplate()} answers a read with - the bound draft when the identifier
	 * carries one, the published version otherwise - through the same request memo, so the sizes a write keeps
	 * belong to the revision the agent was handed and never to another one.
	 *
	 * The preset is the saved field verbatim, without the Document-based recovery the reverse projection applies
	 * to a node saved before that field existed: a size is kept only when the block is provably the same one,
	 * and such a node is simply rebuilt from the catalog.
	 *
	 * @return array<string, array{
	 *     type: string,
	 *     presetId: string|null,
	 *     dimensions: array{width: int|null, height: int|null}
	 * }>
	 *
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getBoundBlocks(WorkflowTemplateIdentifier $identifier): array
	{
		$children = $this->getDraftOrTemplateData($identifier)[0][NodesToTemplate::ELEMENT_CHILDREN] ?? null;
		if (!is_array($children))
		{
			return [];
		}

		$boundBlocks = [];
		foreach ($children as $child)
		{
			if (!is_array($child))
			{
				continue;
			}

			$id = $child[NodesToTemplate::ELEMENT_NAME] ?? null;
			$type = $child[NodesToTemplate::ELEMENT_TYPE] ?? null;
			if (!is_string($id) || $id === '' || !is_string($type))
			{
				continue;
			}

			$node = $child[AiAssistantWorkflowTemplateConverterService::NODE_ELEMENT] ?? null;
			$dimensions = is_array($node) ? ($node['dimensions'] ?? null) : null;
			$presetId = $child[AiAssistantWorkflowTemplateConverterService::PRESET_ID_ELEMENT] ?? null;

			$boundBlocks[$id] = [
				'type' => $type,
				'presetId' => is_string($presetId) && $presetId !== '' ? $presetId : null,
				'dimensions' => [
					'width' => self::readDimension($dimensions, 'width'),
					'height' => self::readDimension($dimensions, 'height'),
				],
			];
		}

		return $boundBlocks;
	}

	/**
	 * A saved dimension of a bound block, under the same "unset is not zero" rule as
	 * {@see \Bitrix\BizprocDesigner\Internal\Entity\Block::readDimension()}.
	 */
	private static function readDimension(mixed $dimensions, string $key): ?int
	{
		$value = is_array($dimensions) ? ($dimensions[$key] ?? null) : null;

		return $value === null ? null : (int)$value;
	}

	/**
	 * Constants the published bound template declares, keyed by code. Read from the same memoised row
	 * {@see self::getPublishedAgentTemplate} uses, so the enforcement baseline of a request is assembled from one
	 * and the same published version. Never the draft: the draft would let the graph under validation vouch for
	 * itself. Empty when there is no published template.
	 *
	 * @return array<string, array>
	 *
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getPublishedTemplateConstants(int $templateId): array
	{
		$constants = $this->getTemplate($templateId)?->getConstants();

		return is_array($constants) ? $constants : [];
	}

	/**
	 * Constants the bound draft has already accumulated, keyed by code. The draft is what a reverse write merges
	 * the declarations of a graph into ({@see AgentDraftService::saveAndPush()}), and that merge never removes a
	 * code, so a bound measured against the published version alone would not see the growth it exists to stop.
	 *
	 * This is a size and never a baseline: what an edit is JUDGED against stays the published version, or the
	 * graph under validation would vouch for itself.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getDraftConstants(int $draftId): array
	{
		$constants = $this->getDraftTemplateData($draftId)['CONSTANTS'] ?? null;

		return is_array($constants) ? $constants : [];
	}

	/**
	 * TEMPLATE_DATA of a draft, as stored, memoised for the request ({@see self::$draftRowById}). Empty
	 * for a draft that does not exist, so a caller reading a field off it needs no state of its own.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getDraftTemplateData(int $draftId): array
	{
		return $this->getDraftRow($draftId)['TEMPLATE_DATA'] ?? [];
	}

	/**
	 * @return array{NAME: string, DESCRIPTION: string}
	 *
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getEffectiveMetadata(WorkflowTemplateIdentifier $identifier): array
	{
		$draftId = (int)($identifier->draftId ?? 0);
		$draftRow = $this->getDraftRow($draftId);
		$draftData = $draftRow['TEMPLATE_DATA'] ?? [];
		$isRestored = WorkflowTemplateDraftTable::hasRestoredMetadata($draftRow ?: null);

		$template = $this->getTemplate((int)($identifier->templateId ?? 0));
		$published = [
			AgentOwnedMetadata::FIELD_NAME => (string)$template?->getName(),
			AgentOwnedMetadata::FIELD_DESCRIPTION => (string)$template?->getDescription(),
		];

		$metadata = [];
		foreach ($published as $field => $publishedValue)
		{
			$followsDraft = isset($draftData[$field])
				&& ($template === null || AgentOwnedMetadata::isFieldOwned($draftData, $field, $isRestored))
			;

			$metadata[$field] = $followsDraft ? (string)$draftData[$field] : $publishedValue;
		}

		return $metadata;
	}

	public function forgetDraftTemplateData(int $draftId): void
	{
		unset($this->draftRowById[$draftId]);
	}

	/**
	 * @return array{TEMPLATE_DATA: array, STATUS: int}|array{} empty for a draft that does not exist, so a
	 *         caller reading a field off it needs no state of its own
	 *
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function getDraftRow(int $draftId): array
	{
		if ($draftId <= 0)
		{
			return [];
		}

		if (!array_key_exists($draftId, $this->draftRowById))
		{
			$draft = WorkflowTemplateDraftTable::query()
				->where('ID', $draftId)
				->setSelect(['TEMPLATE_DATA', 'STATUS'])
				->setLimit(1)
				->fetchObject()
			;

			$templateData = $draft?->getTemplateData();
			$this->draftRowById[$draftId] = $draft === null
				? []
				: [
					'TEMPLATE_DATA' => is_array($templateData) ? $templateData : [],
					'STATUS' => (int)$draft->getStatus(),
				]
			;
		}

		return $this->draftRowById[$draftId];
	}

	/**
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function getDraftOrTemplateData(WorkflowTemplateIdentifier $identifier): ?array
	{
		if ($identifier->draftId)
		{
			// Through the memo of the blob, which holds the same TEMPLATE_DATA and reads it itself when no caller
			// has yet: a reverse call assembles its ceiling off that blob before reaching here
			// ({@see self::getDraftConstants()}), and the writer of a draft drops the entry
			// ({@see self::forgetDraftTemplateData()}), so a read after a write still answers with what was written.
			$draftTemplate = $this->getDraftTemplateData((int)$identifier->draftId)['TEMPLATE'] ?? null;
			if ($draftTemplate)
			{
				return $draftTemplate;
			}
		}

		return $this->getTemplate((int)$identifier->templateId)?->getTemplate();
	}

	/**
	 * The row of a node template, admitting non-system node templates only - that filter is what makes an
	 * identifier of this service mean "a template of the contour".
	 *
	 * The complete row is read once and memoised for the request: the resolver first needs its scalar header,
	 * while validation later reads the graph and constants from that same version without a second query.
	 *
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function getTemplate(int $templateId): ?EO_WorkflowTemplate
	{
		if ($templateId <= 0)
		{
			return null;
		}

		if (!array_key_exists($templateId, $this->templateById))
		{
			$this->templateById[$templateId] = WorkflowTemplateTable::query()
				->where('ID', $templateId)
				->where('TYPE', WorkflowTemplateType::Nodes->value)
				->whereNull('SYSTEM_CODE')
				->setSelect(['*'])
				->setLimit(1)
				->fetchObject()
			;
		}

		return $this->templateById[$templateId];
	}

	/**
	 * With conflict protection enabled, the agent follows the freshest draft of the template, whoever wrote it
	 * last. The legacy path keeps selecting the draft of the agent user.
	 *
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function getNewestDraft(int $templateId, ?int $userId): ?EO_WorkflowTemplateDraft
	{
		// CREATED holds whole seconds, so two drafts written within one second are equally new and the
		// order between them would be up to the database. ID breaks the tie by the order of the writes.
		$query = WorkflowTemplateDraftTable::query()
			->where('TEMPLATE_ID', $templateId)
		;
		if ($userId !== null)
		{
			$query->where('USER_ID', $userId);
		}

		return $query->setOrder(['CREATED' => 'DESC', 'ID' => 'DESC'])
			->setSelect(['ID'])
			->setLimit(1)
			->fetchObject()
		;
	}
}
