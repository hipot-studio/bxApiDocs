<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Feature;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\Error;
use Bitrix\Main\File\ContentReplacer;
use Bitrix\Main\FileTable;
use Bitrix\Main\IO\File;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Json;
use Bitrix\Tasks\Feature\ChatAvatarFeature;

class ChatAvatarPublisher
{
	private const OPTION_MODULE = 'tasks';
	private const IDS_OPTION = 'chat_avatar_file_ids';
	// Keep the persisted option name compatible with already published replacements.
	private const VERSIONS_OPTION = 'chat_avatar_file_versions';
	private const IDS_SCHEMA = 2;
	private const VERSIONS_SCHEMA = 1;
	private const REPLACEMENT_OWNER = 'tasks.chat.avatar';
	private const LOCK_NAME = 'tasks_chat_avatar_publish';
	private const LOCK_TIMEOUT = 5;
	private const SAVE_PATH = 'tasks';

	public function publish(int $revision, array $pathsByType): Result
	{
		if (!$this->isEnabled())
		{
			return $this->success(false);
		}

		$paths = $this->normalizePaths($revision, $pathsByType);
		if ($paths === null)
		{
			return $this->error('CHAT_AVATAR_INVALID', 'Invalid chat avatar publication parameters.');
		}

		$pool = Application::getInstance()->getConnectionPool();
		$connection = Application::getConnection();
		$locked = false;
		$pool->useMasterOnly(true);

		try
		{
			do
			{
				$locked = $connection->lock(self::LOCK_NAME, self::LOCK_TIMEOUT);
				if (!$locked)
				{
					$state = $this->readState(fresh: true);
					if ($revision <= (int)($state['revision'] ?? 0))
					{
						return $this->success(false);
					}
				}
			}
			while (!$locked);

			$ids = $this->readIds(fresh: true);
			$state = $this->readState(fresh: true);
			$this->adoptKnownRoots($ids, $state);

			$publishedRevision = (int)($state['revision'] ?? 0);
			if ($revision <= $publishedRevision)
			{
				return $this->success(false);
			}

			$replacements = [];
			$changed = false;
			foreach (ChatAvatarType::cases() as $type)
			{
				$rootId = $this->getExistingRootId($type, $state);
				if ($rootId <= 0)
				{
					$rootId = $this->findRootByExternalId($type);
					$isNewRoot = $rootId <= 0;
					if ($isNewRoot)
					{
						$rootId = $this->findOrSaveFile($paths[$type->value], $this->getRootExternalId($type));
					}
					$this->validatePreparedImage($rootId);
					$this->rememberReplacedRootIds($type, $ids, $state, $rootId);
					$ids[$type->value] = $rootId;
					$state['roots'][$type->value] = $rootId;
					$this->writeIds($ids);
					$this->writeState($state);
					$changed = true;

					if ($isNewRoot)
					{
						continue;
					}
				}

				$replacementId = $this->findOrSaveFile(
					$paths[$type->value],
					$this->getReplacementExternalId($revision, $rootId),
				);
				$this->validatePreparedImage($replacementId);
				$replacements[$rootId] = $replacementId;
			}

			$this->writeIds($ids);
			$this->writeState($state);

			foreach ($replacements as $rootId => $replacementId)
			{
				$expectedReplacementId = $this->getCurrentReplacementId((int)$rootId);
				$replaceResult = $this->replaceContent(
					(int)$rootId,
					$replacementId,
					$expectedReplacementId,
				);
				if (!$replaceResult->isSuccess())
				{
					return $this->error(
						'CHAT_AVATAR_PUBLISH_FAILED',
						$replaceResult->getError()?->getMessage() ?? 'Could not publish a chat avatar image.',
					);
				}

				$state['versions'][(string)$rootId] = $replacementId;
				$this->writeState($state);
				$changed = $changed || ($replaceResult->getData()['changed'] ?? false) === true;
			}

			$this->clearImCaches();
			$state['revision'] = $revision;
			$this->writeState($state);

			return $this->success($changed);
		}
		catch (\Throwable $exception)
		{
			return $this->error('CHAT_AVATAR_PUBLISH_FAILED', $exception->getMessage());
		}
		finally
		{
			try
			{
				if ($locked)
				{
					$connection->unlock(self::LOCK_NAME);
				}
			}
			finally
			{
				$pool->useMasterOnly(false);
			}
		}
	}

	public function ensureOriginal(ChatAvatarType $type): int
	{
		if (!$this->isEnabled())
		{
			return 0;
		}

		$rootId = $this->findExistingRootId($type);
		if ($rootId > 0)
		{
			return $rootId;
		}

		$pool = Application::getInstance()->getConnectionPool();
		$connection = Application::getConnection();
		$locked = false;
		$pool->useMasterOnly(true);

		try
		{
			do
			{
				$locked = $connection->lock(self::LOCK_NAME, self::LOCK_TIMEOUT);
				if (!$locked)
				{
					$rootId = $this->findExistingRootId($type, fresh: true)
						?: $this->findRootByExternalId($type);
					if ($rootId > 0)
					{
						return $rootId;
					}
				}
			}
			while (!$locked);

			$ids = $this->readIds(fresh: true);
			$state = $this->readState(fresh: true);
			$this->adoptKnownRoots($ids, $state);
			$rootId = $this->getExistingRootId($type, $state);
			if ($rootId > 0)
			{
				return $rootId;
			}

			$rootId = $this->findRootByExternalId($type);
			if ($rootId > 0)
			{
				$this->rememberReplacedRootIds($type, $ids, $state, $rootId);
				$ids[$type->value] = $rootId;
				$state['roots'][$type->value] = $rootId;
				$this->writeIds($ids);
				$this->writeState($state);

				return $rootId;
			}

			$path = $this->getInstalledPath($type);
			if (!$this->isAllowedPath($path))
			{
				return 0;
			}

			$rootId = $this->findOrSaveFile($path, $this->getRootExternalId($type));
			$this->validatePreparedImage($rootId);
			$this->rememberReplacedRootIds($type, $ids, $state, $rootId);
			$ids[$type->value] = $rootId;
			$state['roots'][$type->value] = $rootId;
			$this->writeIds($ids);
			$this->writeState($state);

			return $rootId;
		}
		catch (\Throwable)
		{
			return 0;
		}
		finally
		{
			try
			{
				if ($locked)
				{
					$connection->unlock(self::LOCK_NAME);
				}
			}
			finally
			{
				$pool->useMasterOnly(false);
			}
		}
	}

	public function isSystemFileId(int $fileId): bool
	{
		if ($fileId <= 0)
		{
			return false;
		}

		$state = $this->readState();
		$knownIds = array_merge(
			array_values($state['roots']),
			array_map('intval', array_keys($state['versions'])),
			array_values($state['versions']),
			array_values($state['legacyIds']),
		);
		if (in_array($fileId, array_map('intval', $knownIds), true))
		{
			return true;
		}

		$ids = $this->readIds();
		foreach (ChatAvatarType::cases() as $type)
		{
			if ((int)($ids[$type->value] ?? 0) !== $fileId)
			{
				continue;
			}

			if ($this->isKnownLegacyRoot($fileId, $type))
			{
				return true;
			}
		}

		return false;
	}

	protected function replaceContent(int $fileId, int $replacementId, ?int $expectedReplacementId): Result
	{
		return (new ContentReplacer())->replace(
			$fileId,
			$replacementId,
			$expectedReplacementId,
			self::REPLACEMENT_OWNER,
		);
	}

	protected function saveFile(string $path, string $externalId): int
	{
		$file = \CFile::MakeFileArray($path);
		if (!is_array($file))
		{
			return 0;
		}

		$file['MODULE_ID'] = self::OPTION_MODULE;
		$file['external_id'] = $externalId;

		return (int)\CFile::SaveFile($file, self::SAVE_PATH);
	}

	protected function clearImCaches(): void
	{
		if (!Loader::includeModule('im'))
		{
			throw new \RuntimeException('IM module is not installed.');
		}

		\Bitrix\Im\LastSearch::clearCache();
		\CIMContactList::CleanAllChatCache();
	}

	private function normalizePaths(int $revision, array $pathsByType): ?array
	{
		if ($revision <= 0 || count($pathsByType) !== count(ChatAvatarType::cases()))
		{
			return null;
		}

		$paths = [];
		foreach (ChatAvatarType::cases() as $type)
		{
			$path = $pathsByType[$type->value] ?? null;
			if (!is_string($path) || !$this->isAllowedPath($path))
			{
				return null;
			}

			$paths[$type->value] = $path;
		}

		return $paths;
	}

	private function isAllowedPath(string $path): bool
	{
		$realPath = realpath($path);
		$basePath = realpath($this->getInstalledDirectory());

		return $realPath !== false
			&& $basePath !== false
			&& File::isFileExists($realPath)
			&& str_starts_with($realPath, $basePath . DIRECTORY_SEPARATOR);
	}

	private function getInstalledDirectory(): string
	{
		return Application::getDocumentRoot() . '/bitrix/images/tasks/chat_avatar';
	}

	private function getInstalledPath(ChatAvatarType $type): string
	{
		return $this->getInstalledDirectory() . '/' . $type->value . '.png';
	}

	private function findOrSaveFile(string $path, string $externalId): int
	{
		$files = FileTable::query()
			->setSelect(['ID', 'MODULE_ID'])
			->where('EXTERNAL_ID', $externalId)
			->setLimit(2)
			->fetchAll()
		;
		if (count($files) > 1)
		{
			throw new \RuntimeException('Ambiguous prepared chat avatar file.');
		}
		if ($files !== [])
		{
			$file = $files[0];
			if ((string)$file['MODULE_ID'] !== self::OPTION_MODULE || !$this->hasSameContent((int)$file['ID'], $path))
			{
				throw new \RuntimeException('Prepared chat avatar file does not match its source.');
			}

			return (int)$file['ID'];
		}

		$fileId = $this->saveFile($path, $externalId);
		if ($fileId <= 0 || !$this->hasSameContent($fileId, $path))
		{
			throw new \RuntimeException('Could not save a prepared chat avatar file.');
		}

		return $fileId;
	}

	private function findRootByExternalId(ChatAvatarType $type): int
	{
		$files = FileTable::query()
			->setSelect(['ID', 'MODULE_ID', 'ORIGINAL_NAME'])
			->where('EXTERNAL_ID', $this->getRootExternalId($type))
			->setLimit(2)
			->fetchAll()
		;
		if (count($files) > 1)
		{
			throw new \RuntimeException('Ambiguous prepared chat avatar root.');
		}
		if ($files === [])
		{
			return 0;
		}

		$file = $files[0];
		if (
			(string)$file['MODULE_ID'] !== self::OPTION_MODULE
			|| (string)$file['ORIGINAL_NAME'] !== $type->value . '.png'
		)
		{
			throw new \RuntimeException('Prepared chat avatar root has invalid ownership.');
		}

		return (int)$file['ID'];
	}

	private function hasSameContent(int $fileId, string $path): bool
	{
		$file = \CFile::MakeFileArray($fileId);

		return is_array($file)
			&& isset($file['tmp_name'])
			&& File::isFileExists($file['tmp_name'])
			&& filesize($path) === filesize($file['tmp_name'])
			&& hash_file('sha256', $path) === hash_file('sha256', $file['tmp_name']);
	}

	private function validatePreparedImage(int $fileId): void
	{
		$resized = \CFile::ResizeImageGet(
			$fileId,
			['width' => 200, 'height' => 200],
			BX_RESIZE_IMAGE_EXACT,
			true,
		);
		if (!is_array($resized) || empty($resized['src']))
		{
			throw new \RuntimeException('Could not prepare a chat avatar image.');
		}
	}

	private function adoptKnownRoots(array &$ids, array &$state): void
	{
		foreach (ChatAvatarType::cases() as $type)
		{
			$stateRootId = (int)($state['roots'][$type->value] ?? 0);
			if ($stateRootId > 0)
			{
				$ids[$type->value] = $stateRootId;

				continue;
			}

			$candidateId = (int)($ids[$type->value] ?? 0);
			if ($candidateId > 0 && $this->isAdoptableRoot($candidateId, $type))
			{
				$state['roots'][$type->value] = $candidateId;
			}
		}

		$ids['_version'] = self::IDS_SCHEMA;
	}

	private function rememberReplacedRootIds(
		ChatAvatarType $type,
		array $ids,
		array &$state,
		int $replacementId,
	): void
	{
		$legacyIds = [(int)($state['roots'][$type->value] ?? 0)];
		$legacyId = (int)($ids[$type->value] ?? 0);
		if ($legacyId > 0 && $this->isKnownLegacyRoot($legacyId, $type))
		{
			$legacyIds[] = $legacyId;
		}

		foreach ($legacyIds as $legacyId)
		{
			if ($legacyId > 0 && $legacyId !== $replacementId)
			{
				$state['legacyIds'][] = $legacyId;
			}
		}

		$state['legacyIds'] = array_values(array_unique(array_map('intval', $state['legacyIds'])));
	}

	private function isAdoptableRoot(int $fileId, ChatAvatarType $type): bool
	{
		$file = \CFile::GetByID($fileId, true)->fetch();

		return $file !== false && $this->matchesRootFile($file, $type);
	}

	private function isKnownLegacyRoot(int $fileId, ChatAvatarType $type): bool
	{
		$file = \CFile::GetByID($fileId, true)->fetch();

		return $file === false || $this->matchesRootFile($file, $type);
	}

	private function matchesRootFile(array $file, ChatAvatarType $type): bool
	{
		return (string)$file['MODULE_ID'] === self::OPTION_MODULE
			&& (string)$file['ORIGINAL_NAME'] === $type->value . '.png';
	}

	private function getExistingRootId(ChatAvatarType $type, array $state): int
	{
		$rootId = (int)($state['roots'][$type->value] ?? 0);
		if ($rootId <= 0)
		{
			return 0;
		}

		return \CFile::GetByID($rootId, true)->fetch()
			? $rootId
			: 0;
	}

	private function findExistingRootId(ChatAvatarType $type, bool $fresh = false): int
	{
		try
		{
			$state = $this->readState($fresh);
			$rootId = $this->getExistingRootId($type, $state);
			if ($rootId > 0)
			{
				return $rootId;
			}

			$ids = $this->readIds($fresh);
			$candidateId = (int)($ids[$type->value] ?? 0);

			return $candidateId > 0 && $this->isAdoptableRoot($candidateId, $type)
				? $candidateId
				: 0;
		}
		catch (\Throwable)
		{
			return 0;
		}
	}

	private function getCurrentReplacementId(int $rootId): ?int
	{
		$file = \CFile::GetByID($rootId)->fetch();
		if (!$file || (int)$file['ID'] === $rootId)
		{
			return null;
		}

		return (int)$file['ID'];
	}

	private function readIds(bool $fresh = false): array
	{
		$ids = $this->decodeOption(self::IDS_OPTION, $fresh);

		return is_array($ids) ? $ids : [];
	}

	private function readState(bool $fresh = false): array
	{
		$state = $this->decodeOption(self::VERSIONS_OPTION, $fresh);
		if (!is_array($state) || (int)($state['schema'] ?? 0) !== self::VERSIONS_SCHEMA)
		{
			return [
				'schema' => self::VERSIONS_SCHEMA,
				'roots' => [],
				'versions' => [],
				'legacyIds' => [],
				'revision' => 0,
			];
		}

		$state['roots'] = is_array($state['roots'] ?? null) ? $state['roots'] : [];
		$state['versions'] = is_array($state['versions'] ?? null) ? $state['versions'] : [];
		$state['legacyIds'] = is_array($state['legacyIds'] ?? null) ? $state['legacyIds'] : [];
		$state['revision'] = max(0, (int)($state['revision'] ?? 0));

		return $state;
	}

	private function decodeOption(string $name, bool $fresh = false): mixed
	{
		$value = $fresh ? $this->readOptionFromDatabase($name) : Option::get(self::OPTION_MODULE, $name, '');

		try
		{
			return Json::decode($value);
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	private function readOptionFromDatabase(string $name): string
	{
		$connection = Application::getConnection();
		$sql = (new SqlExpression(
			'SELECT ?# FROM ?# WHERE ?# = ?s AND ?# = ?s',
			'VALUE',
			'b_option',
			'MODULE_ID',
			self::OPTION_MODULE,
			'NAME',
			$name,
		))->compile();

		return (string)($connection->queryScalar($sql) ?? '');
	}

	private function writeIds(array $ids): void
	{
		Option::set(self::OPTION_MODULE, self::IDS_OPTION, Json::encode($ids));
	}

	private function writeState(array $state): void
	{
		Option::set(self::OPTION_MODULE, self::VERSIONS_OPTION, Json::encode($state));
	}

	private function getRootExternalId(ChatAvatarType $type): string
	{
		return $this->getExternalIdPrefix() . ':root:' . $type->value;
	}

	private function getReplacementExternalId(int $revision, int $fileId): string
	{
		return $this->getExternalIdPrefix() . ':' . $revision . ':' . $fileId;
	}

	protected function getExternalIdPrefix(): string
	{
		return 'taskav';
	}

	private function isEnabled(): bool
	{
		return Feature::isEnabled(ChatAvatarFeature::class);
	}

	private function success(bool $changed): Result
	{
		return (new Result())->setData(['changed' => $changed]);
	}

	private function error(string $code, string $message): Result
	{
		return (new Result())->addError(new Error($message, $code));
	}
}
