<?php

namespace Bitrix\Sign\Operation\Member;

use Bitrix\Main;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Sign\Contract\Operation;
use Bitrix\Sign\Item;
use Bitrix\Sign\Result\Operation\Member\MakeB2eSignedFileNameResult;
use Bitrix\Sign\Util\Request\File;
use Bitrix\Sign\Service;
use Bitrix\Main\Web\MimeType;

class MakeB2eSignedFileName implements Operation
{
	private const FILE_NAME_SEPARATOR = '. ';
	private const FILE_NAME_DATE_FORMAT = 'Y-m-d';

	public function __construct(
		private readonly ?Item\Member $member,
		private readonly Item\EntityFile $entity,
	) {}

	public function launch(): MakeB2eSignedFileNameResult
	{
		$title = $this->getDocumentName($this->member);
		$dateSigned = $this->getDateSigned($this->member);
		$memberName = $this->getMemberName($this->member);
		$ext = $this->getFileExtension($this->entity);
		$separator = self::FILE_NAME_SEPARATOR;

		$name = "{$title}{$separator}{$dateSigned}{$separator}{$memberName}.{$ext}";

		return new MakeB2eSignedFileNameResult($name);
	}

	/**
	 * @throws ObjectNotFoundException
	 */
	private function getFileExtension(Item\EntityFile $entity): string
	{
		$file = \CFile::GetFileArray($entity->fileId);

		if (!$file)
		{
			throw new Main\ObjectNotFoundException('File not found');
		}

		$ext = \Bitrix\Main\IO\Path::getExtension($file['ORIGINAL_NAME']);

		if (!$ext)
		{
			$mimeType = MimeType::normalize($file['CONTENT_TYPE']);
			$detectedExt = $this->detectExtensionByType($mimeType);
			if ($detectedExt)
			{
				$ext = $detectedExt;
			}
		}

		return $ext;
	}

	private function getMemberName(?Item\Member $member): string
	{
		if ($member === null)
		{
			return '';
		}

		$memberService = Service\Container::instance()->getMemberService();
		$memberRepresentedName = $memberService->getMemberRepresentedName($member);

		return File::sanitizeFilename($memberRepresentedName) ?? '';
	}

	private function getDateSigned(?Item\Member $member): string
	{
		$member?->dateSigned->setDefaultTimeZone();

		return $member?->dateSigned->format(self::FILE_NAME_DATE_FORMAT) ?? '';
	}

	private function getDocumentName(?Item\Member $member): ?string
	{
		$document = Service\Container::instance()->getDocumentRepository()->getById($member->documentId);
		$title = File::sanitizeFilename($document?->title ?? '');
		if ($title === null)
		{
			$title = Main\Localization\Loc::getMessage('SIGN_DEFAULT_FILE_NAME') . '_' . File::getRandomName(5);
		}

		return $title;
	}

	private function detectExtensionByType(string $mimeType): ?string
	{
		return match ($mimeType)
		{
			'application/zip' => 'zip',
			'application/pdf' => 'pdf',
			default => MimeType::getExtensionByMimeType($mimeType),
		};
	}
}