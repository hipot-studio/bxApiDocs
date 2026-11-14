<?php

declare(strict_types=1);

namespace Bitrix\Disk\Integration\AiAssistant\ToolSet;

use Bitrix\AiAssistant\Definition\Dto\DefinitionMetadataDto;
use Bitrix\AiAssistant\Definition\Dto\UsesToolsDto;
use Bitrix\AiAssistant\Definition\ToolSet\BaseToolSet;
use Bitrix\Disk\Integration\AiAssistant\Tool\CopyFilesTool;
use Bitrix\Disk\Integration\AiAssistant\Tool\CopyFoldersTool;
use Bitrix\Disk\Integration\AiAssistant\Tool\CreateFoldersTool;
use Bitrix\Disk\Integration\AiAssistant\Tool\CreatePublicLinkTool;
use Bitrix\Disk\Integration\AiAssistant\Tool\DeleteFilesTool;
use Bitrix\Disk\Integration\AiAssistant\Tool\DeleteFoldersTool;
use Bitrix\Disk\Integration\AiAssistant\Tool\DownloadFileTool;
use Bitrix\Disk\Integration\AiAssistant\Tool\EmptyTrashTool;
use Bitrix\Disk\Integration\AiAssistant\Tool\GetFileHistoryTool;
use Bitrix\Disk\Integration\AiAssistant\Tool\GetFileLinkTool;
use Bitrix\Disk\Integration\AiAssistant\Tool\GetCleanupCandidatesTool;
use Bitrix\Disk\Integration\AiAssistant\Tool\GetFolderAccessTool;
use Bitrix\Disk\Integration\AiAssistant\Tool\GetFolderContentsTool;
use Bitrix\Disk\Integration\AiAssistant\Tool\GetStorageUsageTool;
use Bitrix\Disk\Integration\AiAssistant\Tool\GetTrashContentsTool;
use Bitrix\Disk\Integration\AiAssistant\Tool\ManageFolderAccessTool;
use Bitrix\Disk\Integration\AiAssistant\Tool\MoveFilesTool;
use Bitrix\Disk\Integration\AiAssistant\Tool\MoveFoldersTool;
use Bitrix\Disk\Integration\AiAssistant\Tool\RenameFilesTool;
use Bitrix\Disk\Integration\AiAssistant\Tool\RenameFoldersTool;
use Bitrix\Disk\Integration\AiAssistant\Tool\ResolveAccessSubjectTool;
use Bitrix\Disk\Integration\AiAssistant\Tool\RestoreFilesFromTrashTool;
use Bitrix\Disk\Integration\AiAssistant\Tool\RestoreFileVersionTool;
use Bitrix\Disk\Integration\AiAssistant\Tool\RestoreFoldersFromTrashTool;
use Bitrix\Disk\Integration\AiAssistant\Tool\SearchFilesTool;
use Bitrix\Disk\Integration\AiAssistant\Tool\UploadFilesTool;

final class DiskToolSet extends BaseToolSet
{
	public function getCode(): string
	{
		return 'disk';
	}

	public function getAdditionalRequiredModules(): array
	{
		return ['aiassistant'];
	}

	public function getMetadata(): DefinitionMetadataDto
	{
		return new DefinitionMetadataDto(
			'Disk Tool Set',
			'Tools for searching, browsing and changing Bitrix24 Disk content. When a write tool returns an error, '
				. 'do not call another write tool without a new explicit user instruction. Never delete an existing item '
				. 'to resolve NAME_CONFLICT.',
		);
	}

	public function getUsesTools(): UsesToolsDto
	{
		return new UsesToolsDto(
			tools: [
				SearchFilesTool::class,
				GetFolderContentsTool::class,
				GetFileHistoryTool::class,
				GetFolderAccessTool::class,
				ResolveAccessSubjectTool::class,
				ManageFolderAccessTool::class,
				DeleteFilesTool::class,
				GetFileLinkTool::class,
				DownloadFileTool::class,
				UploadFilesTool::class,
				CreateFoldersTool::class,
				RenameFilesTool::class,
				RenameFoldersTool::class,
				MoveFilesTool::class,
				MoveFoldersTool::class,
				CopyFilesTool::class,
				CopyFoldersTool::class,
				RestoreFileVersionTool::class,
				CreatePublicLinkTool::class,
				GetCleanupCandidatesTool::class,
				DeleteFoldersTool::class,
				GetTrashContentsTool::class,
				EmptyTrashTool::class,
				RestoreFilesFromTrashTool::class,
				RestoreFoldersFromTrashTool::class,
				GetStorageUsageTool::class,
			],
		);
	}
}
