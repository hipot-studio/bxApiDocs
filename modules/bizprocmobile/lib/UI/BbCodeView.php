<?php

namespace Bitrix\BizprocMobile\UI;

use Bitrix\Bizproc\Public\Service\File\ShowFileAccessService;
use Bitrix\Disk\AttachedObject;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Mobile\UI\File;
use Bitrix\BizprocMobile\Fields;

class BbCodeView implements \JsonSerializable
{
	private string $text;
	private array $files = [];

	public function __construct(string $sourceText)
	{
		$this->extractFromSource($sourceText);
	}

	public function getText(): string
	{
		return $this->text;
	}

	public function getFiles(): array
	{
		return $this->files;
	}

	private function extractFromSource(string $sourceText): void
	{
		$sourceText = preg_replace_callback(
			'|\[url\s*=\s*/bitrix/tools/bizproc_show_file\.php\?([^]]+)]|',
			$this->getFileLinksReplacer($this->files),
			$sourceText,
		);

		if (Loader::includeModule('disk'))
		{
			$sourceText = preg_replace_callback(
				'|\[url\s*=\s*/bitrix/tools/disk/uf.php\?([^]]+)]|',
				$this->getDiskFileLinksReplacer($this->files),
				$sourceText
			);
		}

		$this->text = $sourceText;
	}

	private function getFileLinksReplacer(array& $files): callable
	{
		return function ($matches) use (&$files)
		{
			parse_str(htmlspecialcharsback($matches[1]), $query);

			if (class_exists(ShowFileAccessService::class))
			{
				return $this->replaceAccessibleFileLink($query, $files);
			}

			$fileId = $query['i'] ?? null;
			if (isset($fileId))
			{
				$fileId = (int)$fileId;
				$file = File::load($fileId);
				if (isset($file))
				{
					$uri = 'fid://' . $file->getId();
					$files[$uri] = $file;

					return '[url=' . $uri . ']';
				}
			}

			return '[url=/dev/null]'; // File not found
		};
	}

	private function replaceAccessibleFileLink(array $query, array& $files): string
	{
		$isAuthorized = (int)(CurrentUser::get()?->getId() ?? 0) > 0;
		$verdict = (new ShowFileAccessService())->resolve($query, $isAuthorized);
		if (!$verdict->isAllowed())
		{
			return '[url=/dev/null]';
		}

		$fileId = (int)(is_array($verdict->file) ? ($verdict->file['ID'] ?? 0) : $verdict->file);
		$file = $fileId > 0 ? File::load($fileId) : null;
		if (!isset($file))
		{
			return '[url=/dev/null]'; // File not found
		}

		$uri = 'fid://' . $file->getId();
		$files[$uri] = $file;

		return '[url=' . $uri . ']';
	}

	private function getDiskFileLinksReplacer(array& $files): callable
	{
		return function ($matches) use (&$files)
		{
			parse_str(htmlspecialcharsback($matches[1]), $query);
			$attachedModel = AttachedObject::loadById($query['attachedId'] ?? null);
			$diskFile = $attachedModel?->getFile();
			if (isset($diskFile))
			{
				$file = File::load($diskFile->getFileId());
				if (isset($file))
				{
					$uri = 'fid://' . $file->getId();
					$files[$uri] = $file;

					return '[url=' . $uri . ']';
				}
			}

			return '[url=/dev/null]'; // File not found
		};
	}

	public function jsonSerialize(): array
	{
		return [
			'text' => $this->text,
			'files' => $this->files,
		];
	}
}
