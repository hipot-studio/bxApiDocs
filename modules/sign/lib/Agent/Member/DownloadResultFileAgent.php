<?php

namespace Bitrix\Sign\Agent\Member;

use Bitrix\Sign\Operation\Member\AbstractDownloadMemberFile;
use Bitrix\Sign\Operation\Member\DownloadResultFile;
use Bitrix\Sign\Operation\Member\ResultFile\Save;
use Bitrix\Sign\Type\EntityFileCode;
use Bitrix\Sign\Item;
use Bitrix\Main;

class DownloadResultFileAgent extends AbstractDownloadMemberFileAgent
{
	protected static function getBaseAgentName(int $documentId, int $memberId): string
	{
		return "\\Bitrix\\Sign\\Agent\\Member\\DownloadResultFileAgent::run({$documentId}, {$memberId}";
	}

	protected static function createDownloadFileOperation(string $documentUid, string $memberUid): AbstractDownloadMemberFile
	{
		return new DownloadResultFile($documentUid, $memberUid);
	}

	protected static function getEntityFileCode(): int
	{
		return EntityFileCode::SIGNED;
	}

	protected static function addFileItem(
		?Item\Document $document,
		?Item\Member $member,
		?Item\Fs\File $fsFile
	): Main\Result
	{
		return (new Save($document, $member, $fsFile))->launch();
	}
}
