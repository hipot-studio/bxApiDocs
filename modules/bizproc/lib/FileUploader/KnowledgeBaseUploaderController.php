<?php
declare(strict_types=1);

namespace Bitrix\Bizproc\FileUploader;

use Bitrix\Bizproc\Internal\Integration\Rag\Result\KnowledgeBaseGetInfoResult;
use Bitrix\Bizproc\Internal\Integration\Rag\Service\KnowledgeBaseService;
use Bitrix\Bizproc\Internal\Integration\Rag\Service\RagService;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\UI\FileUploader\FileOwnershipCollection;
use Bitrix\UI\FileUploader\Configuration;
use Bitrix\UI\FileUploader\UploaderController;

class KnowledgeBaseUploaderController extends UploaderController
{
	public const OPTION_KNOWLEDGE_BASE_UID = 'knowledgeBaseUid';

	public function __construct(array $options = [])
	{
		$options[self::OPTION_KNOWLEDGE_BASE_UID] = (string)($options[self::OPTION_KNOWLEDGE_BASE_UID] ?? '');

		parent::__construct($options);
	}

	public function isAvailable(): bool
	{

		return CurrentUser::get()->getId() > 0
			&& ServiceLocator::getInstance()->get(RagService::class)->isAvailable()
		;
	}

	public function getConfiguration(): Configuration
	{
		$ragService = ServiceLocator::getInstance()
			->get(RagService::class)
		;

		return (new Configuration())
			->setAcceptedFileTypes($ragService->getAcceptedFileTypes())
			->setMaxFileSize($ragService->getMaxFileSize())
		;
	}

	public function canUpload(): bool
	{
		return $this->isAvailable();
	}

	public function canView(): bool
	{
		return $this->isAvailable();
	}

	public function verifyFileOwner(FileOwnershipCollection $files): void
	{
		$ownFileIds = $this->getKnowledgeBasePersistentFilesIds();
		foreach ($files as $file)
		{
			$file->markAsOwn(in_array($file->getId(), $ownFileIds, true));
		}
	}

	public function canRemove(): bool
	{
		return false;
	}

	/**
	 * @return array<int>
	 */
	public function getKnowledgeBasePersistentFilesIds(): array
	{
		$knowledgeBaseUid = $this->getOptionKnowledgeBaseId();
		if (empty($knowledgeBaseUid))
		{
			return [];
		}

		$result = ServiceLocator::getInstance()
			->get(KnowledgeBaseService::class)
			->getInfo($knowledgeBaseUid)
		;
		if ($result instanceof KnowledgeBaseGetInfoResult)
		{
			return $result->info->fileIds;
		}

		return [];
	}

	public function getOptionKnowledgeBaseId(): string
	{
		return (string)$this->getOption(self::OPTION_KNOWLEDGE_BASE_UID);
	}
}