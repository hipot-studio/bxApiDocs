<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\DockedUpload;

use Bitrix\Disk\BizProcDocument;
use Bitrix\Disk\BizProcDocumentCompatible;
use Bitrix\Disk\Integration\BizProcManager;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Builds the descriptors of the required/optional autostart BizProc parameters for a
 * docked commit (API-05), feeding the widget's own parameter drawer (SC-012).
 *
 * The template selection is the same pair of queries the commit-path enforcement runs
 * ({@see BizProcDocument::hasUnmetRequiredAutoStartParameters}): active parameterized
 * autostart templates for the (storage, event) pair over both the DISK and the WEBDAV
 * document types. Matching the two selections is an invariant - a full parameter set
 * collected from these descriptors must clear the commit gate
 * (hasUnmetBizProcParameters === false).
 *
 * The composite id `bizproc{templateId}_{paramId}` matches the legacy
 * BIZPROC_PARAMETERS_REQUIRED format and the flat keys
 * {@see \Bitrix\Bizproc\Starter\Parameters::fillValuesWithTemplateId()} consumes, so the
 * flat set the drawer collects flows unpacked into API-03, API-01 and the autostart.
 */
class BizProcParameterDescriptorProvider
{
	public const ERROR_INVALID_SCENARIO = 'DISK_DOCKED_INVALID_SCENARIO';

	private const SCENARIO_CREATE = 'create';
	private const SCENARIO_EDIT = 'edit';

	private const DOCUMENT_MODULE_DISK = 'disk';
	private const DOCUMENT_MODULE_WEBDAV = 'webdav';

	/**
	 * @return Result Data on success: {hasParameters: bool, required: string[], templates: array}.
	 *   A disabled or unavailable BizProc, or the absence of parameterized templates, is an
	 *   empty SUCCESS (hasParameters=false), not an error. Only an unknown scenario fails.
	 */
	public function getAutoStartParameters(int $storageId, string $scenario, bool $isBizProcEnabled): Result
	{
		$result = new Result();

		if ($scenario !== self::SCENARIO_CREATE && $scenario !== self::SCENARIO_EDIT)
		{
			return $result->addError(new Error('Unknown autostart scenario', self::ERROR_INVALID_SCENARIO));
		}

		// Nothing below may touch a bizproc class before the module is confirmed available.
		if (!$isBizProcEnabled || !$this->isBizProcAvailable())
		{
			return $result->setData($this->emptyPayload());
		}

		$autoExecuteType = $scenario === self::SCENARIO_CREATE
			? \CBPDocumentEventType::Create
			: \CBPDocumentEventType::Edit;

		$documentTypes = [
			self::DOCUMENT_MODULE_DISK => BizProcDocument::generateDocumentComplexType($storageId),
			self::DOCUMENT_MODULE_WEBDAV => BizProcDocumentCompatible::generateDocumentComplexType($storageId),
		];

		$required = [];
		$templates = [];

		foreach ($documentTypes as $documentModule => $documentType)
		{
			foreach ($this->loadTemplates($documentType, $autoExecuteType) as $template)
			{
				$parameters = $template['PARAMETERS'] ?? null;
				if (!is_array($parameters) || $parameters === [])
				{
					continue;
				}

				$templateId = (int)$template['ID'];
				$descriptors = [];

				foreach ($parameters as $paramId => $property)
				{
					$paramId = (string)$paramId;
					$compositeId = 'bizproc' . $templateId . '_' . $paramId;
					$normalized = \Bitrix\Bizproc\FieldType::normalizeProperty($property);

					$descriptors[] = [
						'id' => $compositeId,
						'paramId' => $paramId,
						'name' => (string)($normalized['Name'] ?? ''),
						'description' => (string)($normalized['Description'] ?? ''),
						'type' => (string)($normalized['Type'] ?? ''),
						'required' => (bool)$normalized['Required'],
						'multiple' => (bool)$normalized['Multiple'],
						'default' => $normalized['Default'],
						'options' => $normalized['Options'],
					];

					if ($normalized['Required'])
					{
						$required[] = $compositeId;
					}
				}

				$templates[] = [
					'templateId' => $templateId,
					'name' => (string)($template['NAME'] ?? ''),
					'documentModule' => $documentModule,
					// The bare `module@entity@type` the field renderer wants: BX.Bizproc.FieldType
					// splits it back into a complex type, and the server-side fallback render of a
					// type without a local renderer parses the very same value. The signed form
					// below carries no `@` and is not interchangeable with it.
					'documentType' => implode('@', $documentType),
					'signedDocumentType' => \CBPDocument::signDocumentType($documentType),
					'parameters' => $descriptors,
				];
			}
		}

		return $result->setData([
			'hasParameters' => $templates !== [],
			'required' => $required,
			'templates' => $templates,
		]);
	}

	/**
	 * @return array{hasParameters: false, required: array, templates: array}
	 */
	private function emptyPayload(): array
	{
		return [
			'hasParameters' => false,
			'required' => [],
			'templates' => [],
		];
	}

	protected function isBizProcAvailable(): bool
	{
		return BizProcManager::isAvailable();
	}

	/**
	 * Active parameterized autostart templates for the document type, same selection as
	 * the commit-path enforcement. Isolated so the descriptor building can be exercised
	 * without a live template.
	 *
	 * @param array $documentType complex document type (module, entity, type)
	 * @return array<int, array<string, mixed>> raw ID/NAME/PARAMETERS rows
	 */
	protected function loadTemplates(array $documentType, int $autoExecuteType): array
	{
		$rows = [];

		$iterator = \CBPWorkflowTemplateLoader::getList(
			[],
			[
				'DOCUMENT_TYPE' => $documentType,
				'AUTO_EXECUTE' => $autoExecuteType,
				'ACTIVE' => 'Y',
				'!PARAMETERS' => null,
			],
			false,
			false,
			['ID', 'NAME', 'PARAMETERS']
		);

		while ($template = $iterator->fetch())
		{
			$rows[] = $template;
		}

		return $rows;
	}
}
