<?php

namespace Bitrix\Bizproc\Internal\Service\WorkflowTemplate;

use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Internal\Repository\WorkflowTemplate\FileRepository;
use Bitrix\Main\Result;

class ConstantsFileService
{
	public function __construct(
		private readonly FileRepository $fileRepository,
	) {}

	/**
	 * @param array $constants
	 *
	 * @return list<int>
	 */
	public function getFileIdsFromConstants(array $constants): array
	{

		$fileIds = [];
		foreach ($constants as $constant)
		{
			if (($constant['Type'] ?? null) !== FieldType::FILE)
			{
				continue;
			}

			$value = $constant['Default'] ?? null;
			if (is_numeric($value) && $value > 0)
			{
				$fileIds[] = (int)$value;
			}
			elseif (is_array($value))
			{
				$value = array_filter($value, static fn($item) => is_numeric($item) && $item > 0);
				$value = array_map(static fn($item) => (int)$item, $value);
				$fileIds = array_merge($fileIds, $value);
			}
		}

		return array_unique($fileIds);
	}

	public function getFileIdsByTemplateId(int $templateId): array
	{
		$constants = (array)\CBPWorkflowTemplateLoader::getTemplateConstants($templateId);

		return $this->getFileIdsFromConstants($constants);
	}

	public function add(int $templateId, array $constants): Result
	{
		$fileIds = $this->getFileIdsFromConstants($constants);

		return $this->fileRepository->add($templateId, $fileIds);
	}

	public function update(int $templateId, array $constants): Result
	{
		$fileIds = $this->getFileIdsFromConstants($constants);

		return $this->fileRepository->syncByTemplateId($templateId, $fileIds);
	}

	public function delete(int $templateId): Result
	{
		return $this->fileRepository->syncByTemplateId($templateId);
	}
}