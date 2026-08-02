<?php

declare(strict_types=1);

namespace Bitrix\Disk\Integration\Anonymizer;

use Bitrix\Anonymizer\Internal\Exceptions\AnonymizerException;
use Bitrix\Anonymizer\Internal\Services\Replacement\Storage;
use Bitrix\Anonymizer\Public\Providers\FileProviderInterface;
use Bitrix\Anonymizer\Public\Providers\Provider;
use Bitrix\Disk\Driver;
use Bitrix\Disk\File;
use Bitrix\Disk\SystemUser;
use Bitrix\Disk\TypeFile;
use Bitrix\DocumentGenerator;
use Bitrix\DocumentGenerator\Body\Data\DocxNodesDto;
use Bitrix\Main\IO\File as IoFile;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\NotImplementedException;

/**
 * DOCX Disk file provider: {@code b_disk_object.ID}, extension must be docx.
 * Extracts flat text for anonymize_text, applies replacements to document nodes, saves via Disk version API.
 */
final class DocxProvider extends Provider implements FileProviderInterface
{
	private int $objectId;

	private File $diskFile;

	private DocumentGenerator\Body\Docx $document;

	/** @var DocxNodesDto[]|null */
	private ?array $textNodes = null;

	private bool $changed = false;

	/**
	 * @throws AnonymizerException
	 */
	public function __construct(int $objectId)
	{
		$this->objectId = $objectId;
		$this->diskFile = self::loadDocxFile($objectId);
		$this->initDocument();
	}

	public static function createFromData(array $data): ?static
	{
		if (!isset($data['objectId']))
		{
			return null;
		}

		$objectId = (int)$data['objectId'];
		if ($objectId <= 0)
		{
			return null;
		}

		try
		{
			return new self($objectId);
		}
		catch (AnonymizerException)
		{
			return null;
		}
	}

	public function getData(): ?array
	{
		return [
			'objectId' => $this->objectId,
		];
	}

	public function getObjectId(): int
	{
		return $this->objectId;
	}

	public function getFileId(): int
	{
		return $this->objectId;
	}

	public function getContent(): string
	{
		$text = '';
		foreach ($this->getTextNodes() as $docNodes)
		{
			foreach ($docNodes->nodes as $node)
			{
				$value = trim($node->value);
				if ($value === '')
				{
					continue;
				}
				$text .= $value . PHP_EOL;
			}
		}

		return $text;
	}

	public function applyReplacements(Storage $replacements): bool
	{
		$replacer = new DocxReplacer($this->getTextNodes());
		if (!$replacer->applyReplacements($replacements))
		{
			return false;
		}

		$this->textNodes = $replacer->getNodes();
		$this->changed = true;

		return true;
	}

	/**
	 * Replaces placeholder codes with original entity text in document nodes (deanonymize).
	 */
	public function restorePlaceholders(Storage $replacements): bool
	{
		/** @var array<string, \Bitrix\Anonymizer\Internal\Entities\Replacement\ReplacementDto> $pending */
		$pending = [];
		foreach ($replacements->getReplacements() as $dto)
		{
			if ($dto->code === '')
			{
				continue;
			}

			$pending[$dto->code] = $dto;
		}

		if ($pending === [])
		{
			return false;
		}

		$changed = false;

		foreach ($this->getTextNodes() as $docNodes)
		{
			if ($pending === [])
			{
				break;
			}

			foreach ($docNodes->nodes as $node)
			{
				if ($pending === [])
				{
					break 2;
				}

				if ($node->value === null || $node->value === '')
				{
					continue;
				}

				$nodeChanged = false;
				foreach ($pending as $code => $dto)
				{
					$placeholder = $dto->getReplace();
					if ($placeholder === '' || !str_contains($node->value, $placeholder))
					{
						continue;
					}

					$node->value = str_replace($placeholder, $dto->text, $node->value);
					$changed = true;
					$nodeChanged = true;
				}

				if ($nodeChanged)
				{
					$this->purgeRestoredReplacements($pending);
				}
			}
		}

		if ($changed)
		{
			$this->changed = true;
		}

		return $changed;
	}

	/**
	 * @param array<string, \Bitrix\Anonymizer\Internal\Entities\Replacement\ReplacementDto> $pending
	 */
	private function purgeRestoredReplacements(array &$pending): void
	{
		foreach (array_keys($pending) as $code)
		{
			$placeholder = $pending[$code]->getReplace();
			if ($placeholder === '' || !$this->documentContainsPlaceholder($placeholder))
			{
				unset($pending[$code]);
			}
		}
	}

	private function documentContainsPlaceholder(string $placeholder): bool
	{
		foreach ($this->getTextNodes() as $docNodes)
		{
			foreach ($docNodes->nodes as $node)
			{
				if (
					$node->value !== null
					&& $node->value !== ''
					&& str_contains($node->value, $placeholder)
				)
				{
					return true;
				}
			}
		}

		return false;
	}

	public function save(): int
	{
		if (!$this->changed)
		{
			return $this->objectId;
		}

		if (!$this->document->setNodes($this->getTextNodes()))
		{
			return $this->objectId;
		}

		$processed = $this->document->process();
		if (!$processed->isSuccess())
		{
			return $this->objectId;
		}

		global $USER;

		$updatedBy = (is_object($USER) && (int)$USER->GetID() > 0)
			? (int)$USER->GetID()
			: SystemUser::SYSTEM_USER_ID;

		$version = $this->diskFile->uploadVersion([
			'name' => $this->diskFile->getName(),
			'content' => $this->document->getContent(),
			'type' => TypeFile::normalizeMimeType('', $this->diskFile->getName()),
			'MODULE_ID' => Driver::INTERNAL_MODULE_ID,
		], $updatedBy);

		if ($version === null)
		{
			return $this->objectId;
		}

		return (int)$this->diskFile->getId();
	}

	/**
	 * @return DocxNodesDto[]
	 */
	public function getTextNodes(): array
	{
		if ($this->textNodes === null)
		{
			$this->textNodes = $this->document->getTextNodes();
		}

		return $this->textNodes;
	}

	private static function loadDocxFile(int $objectId): File
	{
		if ($objectId <= 0)
		{
			throw new AnonymizerException('Invalid Disk object ID for DOCX provider');
		}

		$diskFile = File::loadById($objectId);
		if (!$diskFile)
		{
			throw new AnonymizerException('Disk file not found: ' . $objectId);
		}

		if (mb_strtolower($diskFile->getExtension()) !== 'docx')
		{
			throw new AnonymizerException('Disk file is not DOCX: ' . $objectId);
		}

		return $diskFile;
	}

	/**
	 * @throws AnonymizerException
	 * @throws LoaderException
	 * @throws NotImplementedException
	 */
	private function initDocument(): void
	{
		if (!Loader::includeModule('documentgenerator'))
		{
			throw new AnonymizerException('Can not load required module "documentgenerator"');
		}

		$file = $this->diskFile->getFile();
		if (!is_array($file) || empty($file['SRC']))
		{
			throw new AnonymizerException('DOCX file content not found for Disk object: ' . $this->objectId);
		}

		$path = $_SERVER['DOCUMENT_ROOT'] . DIRECTORY_SEPARATOR . $file['SRC'];
		$fileContent = IoFile::getFileContents($path);
		$this->document = new DocumentGenerator\Body\Docx($fileContent);
	}
}
