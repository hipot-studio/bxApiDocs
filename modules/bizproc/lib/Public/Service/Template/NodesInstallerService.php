<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Service\Template;

use Bitrix\Bizproc\Public\Entity\Template\NodesInstaller;
use Bitrix\Main\Application;
use Bitrix\Main\InvalidOperationException;
use Bitrix\Main\Web\Json;
use Bitrix\Bizproc\Api\Enum\Template\WorkflowTemplateType;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\IO;
use Bitrix\Main\IO\Directory;
use Bitrix\Main\IO\File;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;

class NodesInstallerService
{
	private const ALLOWED_TEMPLATE_FIELDS = [
		'NAME',
		'DESCRIPTION',
		'PARAMETERS',
		'VARIABLES',
		'CONSTANTS',
		'TEMPLATE',
	];

	private const NODES_DIR = 'bitrix/modules/bizproc/nodes';
	private const INSTALLER_FILE_NAME = 'installer.php';
	private const TEMPLATE_FILE_NAME = 'template.json';
	private const TEMPLATE_LOC_FILE_NAME = 'template.json.php';
	private const SHOULD_TRY_CACHE_TAG = 'bizproc_nodes_installer';
	private const SHOULD_TRY_TTL = 86400; // 1 day

	public function makeTemplatePackage(MakeTemplatePackageDto $request): IO\Directory
	{
		$targetDir = $this->getTargetDirPath($request);
		$dir = Directory::createDirectory($targetDir);

		if (!$dir->isExists())
		{
			throw new InvalidOperationException("Cannot create target directory: {$targetDir}. Maybe, you don't have permissions.");
		}

		$data = $this->getTemplateData($request->id);
		$files = $this->makeFilesData($data, $request);

		foreach ($files as $path => $fileContents)
		{
			$fullPath = $targetDir . '/' . $path;
			File::putFileContents($fullPath, $fileContents);
		}

		return $dir;
	}

	public function trySyncSection(string $sectionId, string $langId = LANGUAGE_ID): void
	{
		if ($this->shouldTry($sectionId))
		{
			$this->syncSection($sectionId, $langId);
			$this->setTried($sectionId);
		}
	}

	public function syncSection(string $sectionId, string $langId = LANGUAGE_ID): void
	{
		if (preg_match('/[^a-z0-9_\-]/i', $sectionId))
		{
			throw new ArgumentException('Invalid section name', 'sectionId');
		}

		$sectionDir = new IO\Directory($this->getNodesDir() . '/' . $sectionId);

		if (!$sectionDir->isExists())
		{
			return; //no templates to install
		}

		foreach ($sectionDir->getChildren() as $child)
		{
			if (!$child->isDirectory())
			{
				continue;
			}
			/** @var IO\DirectoryEntry $child */
			$this->installFromDir($child, $langId);
		}
	}

	private function getTemplateData(int $id): array
	{
		$data = WorkflowTemplateTable::query()
			->setSelect(self::ALLOWED_TEMPLATE_FIELDS)
			->where('ID', $id)
			->fetch()
		;
		if (!$data)
		{
			throw new ArgumentException("Workflow template with ID {$id} not found.");
		}
		unset($data['ID']);

		return $data;
	}

	private function makeFilesData(array $template, MakeTemplatePackageDto $request): array
	{
		$files = [];
		$messages = $this->pullMessages($template, $request);

		$files[self::TEMPLATE_FILE_NAME] = $this->makeTemplateFileContents($template);

		if (!empty($messages))
		{
			$files['lang/ru/' . self::TEMPLATE_LOC_FILE_NAME] = $this->makeLangFileContents($messages);
		}

		$files[self::INSTALLER_FILE_NAME] = $this->makeInstallerFileContents();

		return $files;
	}

	private function makeLangFileContents(array $messages): string
	{
		$langFileContent = ['<?php', ''];
		foreach ($messages as $key => $text)
		{
			$langFileContent[] = sprintf('$MESS["%s"] = "%s";', \EscapePHPString($key), \EscapePHPString($text));
		}
		$langFileContent[] = '';

		return implode(PHP_EOL, $langFileContent);
	}

	private function makeTemplateFileContents(array $template): string
	{
		return Json::encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
	}

	private function makeInstallerFileContents(): string
	{
		$time = time();

		return <<<PHP
<?php

declare(strict_types=1);

use Bitrix\Bizproc\Public\Entity\Template\NodesInstaller;

return new class extends NodesInstaller
{
	public function getModifiedTime(): int
	{
		return {$time};
	}
};

PHP;
	}

	private function pullMessages(array &$template, MakeTemplatePackageDto $request): array
	{
		$messPrefix = 'BIZPROC_NODES_' . strtoupper($request->code) . '_';

		$oldMessages = \Bitrix\Main\Localization\Loc::loadLanguageFile(
			$this->getTargetDirPath($request) . '/' . self::TEMPLATE_LOC_FILE_NAME, 'ru'
		);

		$messages = [];

		// Closure to generate localization prefix
		$getLocPrefix = function (string $type) use (&$messages, $messPrefix): string
		{
			$type = strtoupper($type);
			$i = 1;

			do
			{
				$prefix = $messPrefix . $type . '_' . $i;
				++$i;
			}
			while (isset($messages[$prefix]));

			return $prefix;
		};

		// Closure to create or reuse message id for a given text
		$createMessage = function(string $text, string $key) use (&$messages, $oldMessages, $getLocPrefix): string
		{
			$code = array_search($text, $oldMessages, true);
			if ($code === false)
			{
				$code = array_search($text, $messages, true);
			}
			if ($code === false)
			{
				$code = $getLocPrefix($key);
			}
			$messages[$code] = $text;

			return '###' . $code . '###';
		};

		// Walk template data and replace non-ascii strings with message placeholders
		array_walk_recursive($template, static function (&$item, $key) use ($createMessage) {
			if (is_string($item) && preg_match('/[^\x00-\x7F]/', $item))
			{
				$item = $createMessage($item, (string)$key);
			}
		});

		ksort($messages);

		return $messages;
	}

	private function shouldTry(string $sectionId): bool
	{
		$cache = Application::getInstance()->getManagedCache();

		if ($cache->read(self::SHOULD_TRY_TTL, self::SHOULD_TRY_CACHE_TAG . $sectionId))
		{
			return false;
		}

		return $this->lockDb($sectionId);
	}

	private function setTried(string $sectionId): void
	{
		$cache = Application::getInstance()->getManagedCache();
		$cache->set(self::SHOULD_TRY_CACHE_TAG . $sectionId, 1);
		$this->lockDb($sectionId, true);
	}

	private function lockDb(string $sectionId, bool $release = false): bool
	{
		$name = 'bizproc_nodes_installer_' . $sectionId;
		$connection = Application::getInstance()->getConnection();

		if ($release)
		{
			return $connection->unlock($name);
		}

		return $connection->lock($name);
	}

	private function installFromDir(IO\DirectoryEntry $dir, string $langId): void
	{
		$templateFile = null;
		$installerFile = null;

		$langDir = new IO\Directory($dir->getPhysicalPath() . '/lang');
		if ($langDir->isExists())
		{
			$langFile = new IO\File($langDir->getPhysicalPath() . "/{$langId}/" . self::TEMPLATE_LOC_FILE_NAME);
			if (!$langFile->isExists())
			{
				return; // template does not support this language
			}
		}

		foreach ($dir->getChildren() as $child)
		{
			if ($child->isFile())
			{
				if ($child->getName() === self::TEMPLATE_FILE_NAME)
				{
					/** @var IO\FileEntry $templateFile */
					$templateFile = $child;
				}
				elseif ($child->getName() === self::INSTALLER_FILE_NAME)
				{
					/** @var IO\FileEntry $installerFile */
					$installerFile = $child;
				}
			}
		}

		if (!$templateFile)
		{
			return;
		}

		$installerInstance = $this->createInstallerInstance($installerFile);
		if ($installerInstance === null || !$installerInstance->shouldInstall())
		{
			return;
		}

		$systemCode = $dir->getName();

		$tpl = WorkflowTemplateTable::query()
			->setSelect(['ID', 'MODIFIED', 'IS_MODIFIED'])
			->where('SYSTEM_CODE', $systemCode)
			->where('TYPE',  WorkflowTemplateType::Nodes->value)
			->setLimit(1)
			->fetchObject()
		;

		if ($tpl?->getIsModified())
		{
			return; // system template modified by user, do not overwrite
		}

		$modifiedTime = $installerInstance->getModifiedTime();
		if (
			$modifiedTime
			&& $tpl
			&& $modifiedTime <= $tpl->getModified()->getTimestamp()
		)
		{
			return; // no changes
		}

		$template = $this->unpackJsonToTemplate($templateFile->getContents());
		if (empty($template['TEMPLATE']))
		{
			return; //broken template file
		}

		$template = $this->replaceMessages($dir, $template, $langId);

		if (!$template)
		{
			return;
		}

		[$module, $entity, $docType] = \Bitrix\Bizproc\Public\Entity\Document\Workflow::getComplexType();
		$template['MODULE_ID'] = $module;
		$template['ENTITY'] = $entity;
		$template['DOCUMENT_TYPE'] = $docType;
		$template['AUTO_EXECUTE'] = \CBPDocumentEventType::None;
		$template['MODIFIED'] = DateTime::createFromTimestamp($modifiedTime ?: time());
		$template['IS_MODIFIED'] = 'N';
		$template['SYSTEM_CODE'] = $systemCode;
		$template['ACTIVE'] = 'N';
		$template['TYPE'] = WorkflowTemplateType::Nodes->value;

		$this->upsertTpl($tpl?->getId() ?? 0, $template);
	}

	private function createInstallerInstance(?IO\FileEntry $installerFile): ?NodesInstaller
	{
		if ($installerFile)
		{
			$installerInstance = include $installerFile->getPhysicalPath();
			if ($installerInstance instanceof NodesInstaller)
			{
				return $installerInstance;
			}
		}

		return null;
	}

	private function unpackJsonToTemplate(string $json): ?array
	{
		try
		{
			$allFields = \Bitrix\Main\Web\Json::decode($json);
		}
		catch (ArgumentException $e)
		{
			return null;
		}

		return array_intersect_key(
			$allFields,
			array_fill_keys(self::ALLOWED_TEMPLATE_FIELDS, true)
		);
	}

	private function replaceMessages(IO\DirectoryEntry $dir, array $template, string $langId): array
	{
		$messages = \Bitrix\Main\Localization\Loc::loadLanguageFile(
			$dir->getPath() . '/' . self::TEMPLATE_LOC_FILE_NAME, $langId
		);

		array_walk_recursive($template, static function (&$item) use ($messages) {
			if (
				is_string($item)
				&& str_starts_with($item, '###')
				&& str_ends_with($item, '###')
			)
			{
				$code = substr($item, 3, -3);
				$item = $messages[$code] ?? $code;
			}
		});

		return $template;
	}

	private function upsertTpl(int $id, array $data): void
	{
		if ($id > 0)
		{
			WorkflowTemplateTable::update($id, $data);
		}
		else
		{
			WorkflowTemplateTable::add($data);
		}
	}

	private function getNodesDir(): string
	{
		$documentRoot = (string)\Bitrix\Main\Application::getInstance()->getContext()->getServer()->getDocumentRoot();

		return $documentRoot . '/' . self::NODES_DIR;
	}

	private function getTargetDirPath(MakeTemplatePackageDto $request): string
	{
		$nodesDir = $request->outputDir ?? $this->getNodesDir();

		return "{$nodesDir}/{$request->section}/{$request->code}";
	}
}
