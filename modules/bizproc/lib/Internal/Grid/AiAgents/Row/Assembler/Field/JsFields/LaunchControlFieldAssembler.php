<?php

namespace Bitrix\Bizproc\Internal\Grid\AiAgents\Row\Assembler\Field\JsFields;

use Bitrix\Bizproc\Internal\Integration\Rag\Dto\KnowledgeBaseFileStatusDtoCollection;
use Bitrix\Bizproc\Internal\Integration\Rag\Dto\KnowledgeBaseFileStatusDto;
use Bitrix\Bizproc\Internal\Integration\Rag\FileStatus;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;

class LaunchControlFieldAssembler extends JsExtensionFieldAssembler
{
	protected function getExtensionClassName(): string
	{
		return 'LaunchControlField';
	}

	protected function getRenderParams($rawValue): array
	{
		return [
			'agentId' => $this->getAgentId($rawValue),
			'launchedAt' => $this->getLaunchedAt($rawValue),
			'ragFilesStatuses' => $this->getRagFilesStatuses($rawValue),
		];
	}

	protected function prepareColumnForExport($data): string
	{
		return '';
	}

	private function getLaunchedAt($rawValue): ?int
	{
		if ($rawValue['ACTIVATED_AT'] instanceof DateTime)
		{
			return $rawValue['ACTIVATED_AT']->getTimestamp();
		}

		return null;
	}

	private function getAgentId($rawValue): ?int
	{
		if (empty($rawValue['LAUNCHED_BY_USER_DATA'] ?? ''))
		{
			return $rawValue['ID'] ?? null;
		}

		return null;
	}

	private function getRagFilesStatuses($rawValue): ?array
	{
		$ragFilesStatuses = $rawValue['RAG_FILES_STATUS'] ?? null;

		if ($ragFilesStatuses instanceof KnowledgeBaseFileStatusDtoCollection)
		{
			$status = $ragFilesStatuses->getStatus();
			if (empty($status) || $status == FileStatus::Success)
			{
				return null;
			}

			$files = array_map(
				fn(KnowledgeBaseFileStatusDto $file) => [
					'fileName' => (string)$file->fileName,
					'status' => $file->status?->value,
					'statusMessage' => $this->getRagFileStatusMessage($file->status),
					'iconClass' => $this->getRagFileIconClass($file->status),
				],
				$ragFilesStatuses->getAll()
			);

			$status = $ragFilesStatuses->getStatus();

			return [
				'status' => $status?->value,
				'statusMessage' => $this->getRagFileCollectionStatusMessage($status),
				'descriptionMessage' => $this->getRagFileCollectionDescriptionMessage($status),
				'files' => $files,
				'iconClass' => $this->getRagFileCollectionIconClass($status),
			];
		}

		return null;
	}

	private function getRagFileCollectionIconClass(?FileStatus $status): string
	{
		return match ($status)
		{
			FileStatus::Uploading,
			FileStatus::Processing => 'process',
			FileStatus::Success => '',
			FileStatus::FailedUpload => 'cross',
			default => 'cross',
		};
	}

	private function getRagFileIconClass(?FileStatus $status): string
	{
		return match ($status)
		{
			FileStatus::Uploading,
			FileStatus::Processing => '--process',
			FileStatus::Success => '--check-m',
			FileStatus::FailedUpload => '--cross-m',
			default => '--cross-m',
		};
	}

	private function getRagFileStatusMessage(?FileStatus $status): ?string
	{
		$message = match ($status)
		{
			FileStatus::Uploading => 'BIZPROC_INTERNAL_AI_AGENTS_RAG_FILE_UPLOADING_STATUS',
			FileStatus::Processing => 'BIZPROC_INTERNAL_AI_AGENTS_RAG_FILE_PROCESSING_STATUS',
			FileStatus::Success => 'BIZPROC_INTERNAL_AI_AGENTS_RAG_FILE_SUCCESS_STATUS',
			FileStatus::FailedUpload => 'BIZPROC_INTERNAL_AI_AGENTS_RAG_FILE_FAILED_UPLOAD_STATUS',
			default => 'BIZPROC_INTERNAL_AI_AGENTS_RAG_FILE_NONE_STATUS',
		};

		return Loc::getMessage($message);
	}

	private function getRagFileCollectionStatusMessage(?FileStatus $status): ?string
	{
		$message = match ($status)
		{
			FileStatus::Uploading => 'BIZPROC_INTERNAL_AI_AGENTS_RAG_FILE_COLLECTION_UPLOADING_STATUS',
			FileStatus::Processing => 'BIZPROC_INTERNAL_AI_AGENTS_RAG_FILE_COLLECTION_PROCESSING_STATUS',
			FileStatus::FailedUpload => 'BIZPROC_INTERNAL_AI_AGENTS_RAG_FILE_COLLECTION_FAILED_UPLOAD_STATUS',
			default => null,
		};

		return $message ? Loc::getMessage($message) : null;
	}

	private function getRagFileCollectionDescriptionMessage(?FileStatus $status): ?string
	{
		$message = match ($status)
		{
			FileStatus::Uploading => 'BIZPROC_INTERNAL_AI_AGENTS_RAG_FILE_COLLECTION_UPLOADING_DESCRIPTION',
			FileStatus::Processing => 'BIZPROC_INTERNAL_AI_AGENTS_RAG_FILE_COLLECTION_PROCESSING_DESCRIPTION',
			FileStatus::FailedUpload => 'BIZPROC_INTERNAL_AI_AGENTS_RAG_FILE_COLLECTION_FAILED_UPLOAD_DESCRIPTION',
			default => null,
		};

		return $message ? Loc::getMessage($message) : null;
	}
}