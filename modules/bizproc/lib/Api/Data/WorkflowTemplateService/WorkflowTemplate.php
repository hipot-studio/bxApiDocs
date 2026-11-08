<?php

namespace Bitrix\Bizproc\Api\Data\WorkflowTemplateService;

use Bitrix\Bizproc\Api\Request\WorkflowTemplateService\SaveTemplateRequest;
use Bitrix\Main\ArgumentException;
use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Main\Localization\Loc;

final class WorkflowTemplate
{
	/**
	 * The only fields a client save path may write. Everything else is server-owned: authorship
	 * (CREATED_BY, UPDATED_BY), provenance (SYSTEM_CODE, CREATE_SOURCE) and above all TYPE, which decides
	 * which ACL owns the row - a client-set TYPE would move a live Nodes template out of the template ACL
	 * and back onto the legacy document-type gate, while a client-set SYSTEM_CODE would pass the row off as
	 * a catalogue one and skip the copy gate of
	 * {@see \Bitrix\Bizproc\Internal\Service\AiAgentGrid\LaunchableTemplateResolver}. Unknown fields are
	 * dropped rather than rejected, so clients that send the whole row back keep working.
	 *
	 * Dropping TYPE here only closes the direct route. TYPE is also derived from TEMPLATE and AUTO_EXECUTE,
	 * which this very list makes writable, so a save that would take a Nodes row out of its ACL by the
	 * derivation is refused as well - see
	 * {@see \Bitrix\Bizproc\Api\Service\WorkflowTemplateService::takesTemplateOutOfNodes()}.
	 *
	 * The set is the union of what the legitimate producers actually send: the chart editor
	 * ({@see \Bitrix\BizprocDesigner\Infrastructure\Controller\Diagram::prepareFields()}), the legacy
	 * designer (component bizproc.workflow.edit) and the AI agent draft service. Install, import and
	 * conversion paths write through the loader/ORM directly and never reach this class.
	 */
	private const WRITABLE_FIELDS = [
		'NAME',
		'DESCRIPTION',
		'DOCUMENT_TYPE',
		'TEMPLATE',
		'TEMPLATE_SETTINGS',
		'PARAMETERS',
		'VARIABLES',
		'CONSTANTS',
		'AUTO_EXECUTE',
		'SORT',
		'IS_SYSTEM',
		'TRACK_ON',
	];

	private int $templateId = 0;
	private array $fields = [];

	/**
	 * create object from Request
	 * @throws ArgumentException
	 */
	public static function createFromRequest(SaveTemplateRequest $request): self
	{
		$fields = self::normalizeFields($request->fields, $request->templateId === 0);

		$template = new self();
		$template->templateId = $request->templateId;
		$template->fields = $fields;

		if ($request->user)
		{
			$template->assignUser($request->user);
		}

		if ($request->parameters)
		{
			$template->defineDocumentType($request->parameters);
		}

		return $template;
	}

	/**
	 * normalize and validate fields
	 * @throws ArgumentException
	 */
	private static function normalizeFields(array $fields, bool $checkRequired = true): array
	{
		$fields = array_intersect_key($fields, array_flip(self::WRITABLE_FIELDS));

		$fields['VARIABLES'] = is_array($fields['VARIABLES'] ?? null) ? $fields['VARIABLES'] : [];
		$fields['CONSTANTS'] = is_array($fields['CONSTANTS'] ?? null) ? $fields['CONSTANTS'] : [];

		if ($checkRequired)
		{
			self::checkRequiredFields($fields);
		}
		self::validateFieldSize($fields, 'PARAMETERS', \CBPWorkflowTemplateLoader::MAX_PARAMETERS_LENGTH);
		self::validateFieldSize($fields, 'VARIABLES', \CBPWorkflowTemplateLoader::MAX_VARIABLES_LENGTH);
		self::validateFieldSize($fields, 'CONSTANTS', \CBPWorkflowTemplateLoader::MAX_CONSTANTS_LENGTH);

		return $fields;
	}

	/**
	 * @throws ArgumentException
	 */
	private static function checkRequiredFields(array $fields): void
	{
		$fieldsToCheck = [
			'NAME',
			'DESCRIPTION',
			'TEMPLATE',
			'PARAMETERS',
			'VARIABLES',
			'CONSTANTS',
			'TEMPLATE_SETTINGS',
		];
		foreach ($fieldsToCheck as $fieldToCheck)
		{
			if (empty($fields[$fieldToCheck]) && !array_key_exists($fieldToCheck, $fields))
			{
				throw new ArgumentException(ErrorMessage::PARAM_REQUIRED->get(['#NAME#' => $fieldToCheck]));
			}
		}
	}

	/**
	 * @throws ArgumentException
	 */
	private static function validateFieldSize(array $fields, string $key, int $maxSize): void
	{
		if (!empty($fields[$key]) && \CBPWorkflowTemplateLoader::getCompressedFieldLength($fields[$key]) > $maxSize)
		{
			throw new ArgumentException(
				Loc::getMessage("BIZPROC_API_DATA_WORKFLOW_TEMPLATE_{$key}_ERROR")
			);
		}
	}

	private function assignUser(\CBPWorkflowTemplateUser $user): void
	{
		if ($user->getId() <= 0)
		{
			throw new ArgumentException(ErrorMessage::INVALID_USER_ID->get());
		}

		$this->fields['USER_ID'] = $user->getId();
		$this->fields['MODIFIER_USER'] = $user;
	}

	private function defineDocumentType(array $parameters): void
	{
		if (!isset($parameters['MODULE_ID'], $parameters['ENTITY'], $parameters['DOCUMENT_TYPE']))
		{
			$errorMsg = ErrorMessage::INVALID_PARAM_ARG->get([
				'#PARAM#' => 'DOCUMENT_TYPE',
				'#VALUE#' => $parameters
			]);
			throw new ArgumentException($errorMsg);
		}

		$this->fields['DOCUMENT_TYPE'] = [
			$parameters['MODULE_ID'],
			$parameters['ENTITY'],
			$parameters['DOCUMENT_TYPE'],
		];
	}

	public function getUserId(): ?int
	{
		return $this->fields['USER_ID'] ?? null;
	}

	public function getDocumentType(): array
	{
		return $this->fields['DOCUMENT_TYPE'] ?? [];
	}

	public function getFields(): array
	{
		return $this->fields;
	}

	public function getTemplateId(): int
	{
		return $this->templateId;
	}
}
