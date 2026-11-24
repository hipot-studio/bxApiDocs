<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Service\WorkflowTemplate;

use Bitrix\Bizproc\Api;
use Bitrix\Bizproc\Workflow\Template\Converter\SequentialToNodeWorkflow;
use Bitrix\BizprocDesigner\Infrastructure\Enum\StartTrigger;
use Bitrix\BizprocDesigner\Internal\Entity\DocumentDescription;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

Loc::loadMessages(__FILE__);

/**
 * Creates the empty template the editor opens on, for every entry that needs one.
 *
 * The logic used to live private in the editor controller, where it served the one entry there was. A
 * second entry - the REST one an external agent uses - needs the very same template, and the property that
 * matters is easy to lose in a copy: **the row must be of the Nodes type**. A sequential template written
 * instead would be invisible to its own author, because the listing of the module narrows a non-admin to
 * `TYPE = Nodes` (`bizproc/lib/RestService.php`), and the editor could not open it either.
 *
 * The type is not set as a field: it follows from the shape of TEMPLATE, which
 * {@see SequentialToNodeWorkflow} produces. That is why the conversion is here rather than in the caller.
 */
final class EmptyTemplateCreationService
{
	/**
	 * The name a template is born with; the caller may override it.
	 *
	 * The message travelled here together with the creation itself. Leaving it addressed at the lang file
	 * of the editor controller would have resolved to an empty string: Loc reads the file that matches the
	 * one asking, and this is no longer that file. An empty NAME is not a loud failure - the row saves and
	 * the template appears nameless - which is exactly why it is stated here.
	 */
	private const DEFAULT_NAME_MESSAGE = 'BIZPROCDESIGNER_EMPTY_TEMPLATE_DEFAULT_NAME';

	/**
	 * Writes an empty Nodes template of the given document type and returns it.
	 *
	 * `checkAccess: false` on the save request repeats what the editor has always done, and it is not a
	 * hole: both entries judge the right to author on the document type BEFORE they get here - the editor
	 * through its own gate, the REST entry through
	 * {@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\AgentEntryAuthorizationService}.
	 * Letting the save re-judge it would ask a different question (the legacy document gate) than the one
	 * the entries answered.
	 *
	 * @return Result data: ['templateId' => int] on success
	 */
	public function create(
		DocumentDescription $documentType,
		?string $name = null,
		?string $startTrigger = null,
		?int $userId = null,
	): Result
	{
		$result = new Result();

		$fields = $this->defaultFields($name, $startTrigger);
		$user = new \CBPWorkflowTemplateUser($userId ?? \CBPWorkflowTemplateUser::CurrentUser);

		$templateService = new Api\Service\WorkflowTemplateService();
		$saveResult = $templateService->saveTemplate(
			new Api\Request\WorkflowTemplateService\SaveTemplateRequest(
				templateId: 0,
				parameters: [],
				fields: self::prepareFields($fields, $documentType->toBizprocComplexType()),
				user: $user,
				checkAccess: false,
			),
			withoutCommonVersion: true,
		);

		if (!$saveResult->isSuccess())
		{
			return $result->addErrors($saveResult->getErrors());
		}

		// The id, not the row: reading the row back is the business of whoever needs it, and the two
		// callers need different columns of it.
		return $result->setData(['templateId' => (int)$saveResult->getTemplateId()]);
	}

	/**
	 * The row an empty template is written from. A start trigger, when given, is baked into the graph by
	 * the converter rather than stored beside it.
	 *
	 * The name of the trigger is judged here rather than by the callers. Both of them already judged it -
	 * the editor on the parameter of its action, the REST entry before the call - so nothing changes for
	 * either, while the converter stops being reachable with a name no trigger answers to: it stores the
	 * string as given {@see SequentialToNodeWorkflow::setStartTrigger()}, and a graph built around an
	 * unknown one would open on a block the catalog cannot resolve.
	 */
	private function defaultFields(?string $name, ?string $startTrigger): array
	{
		$trigger = $startTrigger === null ? null : StartTrigger::tryFrom($startTrigger)?->value;

		$converter = new SequentialToNodeWorkflow([]);
		if ($trigger !== null && $trigger !== '')
		{
			$converter->setStartTrigger($trigger);
		}

		return [
			'TEMPLATE' => $converter->convert(),
			'NAME' => $name ?? (string)Loc::getMessage(self::DEFAULT_NAME_MESSAGE),
			'AUTO_EXECUTE' => \CBPDocumentEventType::None,
			'DESCRIPTION' => '',
			'PARAMETERS' => [],
			'VARIABLES' => [],
			'CONSTANTS' => [],
			'TEMPLATE_SETTINGS' => [],
		];
	}

	/**
	 * The allowlist of columns a template write carries. Shared by the two writers of the editor - the
	 * creation above and the publication of a diagram - so that a column added to one is not missing from
	 * the other.
	 *
	 * @param array $documentType bizproc complex type, [module, entity, documentType]
	 */
	public static function prepareFields(array $template, array $documentType): array
	{
		return [
			'TEMPLATE' => $template['TEMPLATE'],
			'DOCUMENT_TYPE' => $documentType,
			'NAME' => $template['NAME'],
			// The column is nullable and rows written before the editor still hold NULL there, so the
			// editor is handed a null it sends back with the whole template on every draft save. That is
			// the absence of a description, not a value the gate below should refuse: refusing it would
			// stop the autosave of those templates outright. Anything else keeps its type and is judged.
			'DESCRIPTION' => $template['DESCRIPTION'] ?? '',
			'PARAMETERS' => $template['PARAMETERS'],
			'VARIABLES' => $template['VARIABLES'],
			'CONSTANTS' => $template['CONSTANTS'],
			'TEMPLATE_SETTINGS' => $template['TEMPLATE_SETTINGS'],
			'AUTO_EXECUTE' => $template['AUTO_EXECUTE'],
		];
	}
}
