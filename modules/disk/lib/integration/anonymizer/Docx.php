<?php

declare(strict_types=1);

namespace Bitrix\Anonymizer\Provider;

use Bitrix\Main\IO\File;
use Bitrix\Main\Loader;
use Bitrix\Anonymizer\AnonymizerException;
use Bitrix\Anonymizer\Replacement;
use Bitrix\DocumentGenerator;
use Bitrix\DocumentGenerator\Body\Data\DocxNodesDto;

// dbg do
class Docx extends Provider implements IFile
{
	private int $fileId;

	private DocumentGenerator\Body\Docx $document;
	/** @var DocxNodesDto[] */
	private array $textNodes;
	private bool $changed = false;

	public function __construct(int $fileId)
	{
		// todo: move to init method
		if (!Loader::includeModule('documentgenerator'))
		{
			throw new AnonymizerException('Can not load required module "documentgenerator"');
		}

		$this->fileId = $fileId;
		$file = \CFile::GetFileArray($this->fileId);

		$fileContent = File::getFileContents($_SERVER["DOCUMENT_ROOT"] . DIRECTORY_SEPARATOR . $file['SRC']);
		$this->document = new DocumentGenerator\Body\Docx($fileContent);
	}

	public function getText(): string
	{
		// TODO: do normal

		$textNodes = $this->getTextNodes();
		$text = '';
		foreach ($textNodes as $docNodes)
		{
			foreach ($docNodes->nodes as $node)
			{
				$value = trim($node->value);
				if (empty($value))
				{
					continue;
				}
				$text .= $value . PHP_EOL;
			}
		}

		return $text;
	}

	public function applyReplacements(Replacement\Storage $replacements): static
	{
		$nodes = $this->getTextNodes();
		$replacer = new DocxReplacer($nodes);
		if ($replacer->applyReplacements($replacements))
		{
			$this->changed = true;
			$this->textNodes = $replacer->getNodes();
		}

		return $this;
	}

	/**
	 * @return DocxNodesDto[]
	 */
	private function getTextNodes(): array
	{
		if (!isset($this->textNodes))
		{
			$this->textNodes = $this->document->getTextNodes();
		}

		return $this->textNodes ?? [];
	}

	public function save(): int
	{
		if ($this->changed === false)
		{
			return $this->fileId;
		}

		if (!$this->document->setNodes($this->textNodes))
		{
			return $this->fileId;
		}

		$processed = $this->document->process();
		if (!$processed->isSuccess())
		{
			return $this->fileId;
		}

		$file = \CFile::MakeFileArray($this->fileId);
		$file['content'] = $this->document->getContent();
		// dbg not disk?
		$file['MODULE_ID'] = 'disk';
		unset($file['tmp_name'], $file['external_id']);

		return \CFile::SaveFile($file, 'disk');
	}
}