<?php
declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Integration\Rag\Service;

use Bitrix\Bizproc\Internal\Integration\Rag\Dto\KnowledgeBaseFileStatusDtoCollection;
use Bitrix\Main\Data\ManagedCache;
use Bitrix\Main\Application;

class KnowledgeBaseFileCacheService
{
	public const RAG_FILE_STATUS_INFO_CACHE_TAG_PREFIX = 'RAG_FILE_STATUS_INFO_CACHE_TAG_PREFIX_';
	public const RAG_FILE_STATUS_CACHE_TAG_PREFIX_TTL = 3600*24*7*30;

	public function setCacheInfoUploadFiles(
		string $knowledgeBaseUid,
		KnowledgeBaseFileStatusDtoCollection $collection
	): void
	{
		$this->getCache()->set($this->getCacheInfoUploadFilesTag($knowledgeBaseUid), $collection);
	}

	public function getCacheInfoUploadFiles(string $knowledgeBaseUid): ?KnowledgeBaseFileStatusDtoCollection
	{
		$cacheTag = $this->getCacheInfoUploadFilesTag($knowledgeBaseUid);

		if ($this->getCache()->read(self::RAG_FILE_STATUS_CACHE_TAG_PREFIX_TTL, $cacheTag))
		{
			return $this->getCache()->get($cacheTag);
		}

		return null;
	}

	public function cleanCacheInfoUploadFiles(string $knowledgeBaseUid): void
	{
		$this->getCache()->clean($this->getCacheInfoUploadFilesTag($knowledgeBaseUid));
	}

	private function getCache(): ManagedCache
	{
		return Application::getInstance()->getManagedCache();
	}

	private function getCacheInfoUploadFilesTag(string $knowledgeBaseUid): string
	{
		return self::RAG_FILE_STATUS_INFO_CACHE_TAG_PREFIX . $knowledgeBaseUid;
	}
}