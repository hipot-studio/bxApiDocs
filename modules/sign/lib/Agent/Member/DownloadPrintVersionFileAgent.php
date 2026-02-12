<?php

namespace Bitrix\Sign\Agent\Member;

use Bitrix\Sign\Operation\Member\AbstractDownloadMemberFile;
use Bitrix\Sign\Operation\Member\DownloadPrintVersionFile;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Type\EntityFileCode;
use Bitrix\Sign\Item;
use Bitrix\Sign\Type;
use Bitrix\Main;

class DownloadPrintVersionFileAgent extends AbstractDownloadMemberFileAgent
{
	protected static function getBaseAgentName(int $documentId, int $memberId): string
	{
		return "\\Bitrix\\Sign\\Agent\\Member\\DownloadPrintVersionFileAgent::run({$documentId}, {$memberId}";
	}

	protected static function createDownloadFileOperation(string $documentUid, string $memberUid): AbstractDownloadMemberFile
	{
		return new DownloadPrintVersionFile($documentUid, $memberUid);
	}

	protected static function getEntityFileCode(): int
	{
		return EntityFileCode::PRINT_VERSION;
	}

	protected static function addFileItem(
		?Item\Document $document,
		?Item\Member $member,
		?Item\Fs\File $fsFile
	): Main\Result
	{
		$fsFile->dir = '';

		$fsRepo = Container::instance()->getFileRepository();
		$saveResult = $fsRepo->put($fsFile);

		if (!$saveResult->isSuccess())
		{
			return $saveResult;
		}

		$entityFileRepository = Container::instance()->getEntityFileRepository();
		$fileItem = new Item\EntityFile(
			id: null,
			entityTypeId: Type\EntityType::MEMBER,
			entityId: $member->id,
			code: Type\EntityFileCode::PRINT_VERSION,
			fileId: $fsFile->id,
		);

		return $entityFileRepository->add($fileItem);
	}
}
