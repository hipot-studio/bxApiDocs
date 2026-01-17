<?php
declare(strict_types=1);

namespace Bitrix\Bizproc\Infrastructure\Controller\Integration\Rag;

use Bitrix\Bizproc\Internal\Integration\Rag\Result\KnowledgeBaseGetInfoResult;
use Bitrix\Bizproc\Internal\Integration\Rag\Result\KnowledgeBaseInfoResult;
use Bitrix\Bizproc\Internal\Integration\Rag\Service\KnowledgeBaseService;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Engine\JsonController;

class KnowledgeBase extends JsonController
{
	public function createAction(
		string $name,
		string $description,
		array $fileIds,
	): ?array
	{
		$result = ServiceLocator::getInstance()
			->get(KnowledgeBaseService::class)
			->createWithFiles(
				name: $name,
				description: $description,
				fileIds: $fileIds,
				userId: (int)CurrentUser::get()->getId(),
			)
		;

		if ($result instanceof KnowledgeBaseInfoResult)
		{
			return [
				'uid' => $result->uid,
				'fileIds' => $result->fileIds,
				'fileIdsReplaces' => $result->fileIdsReplaces,
			];
		}

		$this->addErrors($result->getErrors());

		return null;
	}

	public function getAction(string $uid): ?array
	{
		$result = ServiceLocator::getInstance()
			->get(KnowledgeBaseService::class)
			->getInfo($uid)
		;

		if (!$result instanceof KnowledgeBaseGetInfoResult)
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		$knowledgeBaseInfo = $result->info;

		return [
			'uid' => $knowledgeBaseInfo->uuid,
			'name' => $knowledgeBaseInfo->name,
			'description' => $knowledgeBaseInfo->description,
			'fileIds' => $knowledgeBaseInfo->fileIds,
		];
	}

	public function updateAction(string $uid, string $name, string $description, array $fileIds): ?array
	{
		$result = ServiceLocator::getInstance()
			->get(KnowledgeBaseService::class)
			->updateWithFiles(
				uid: $uid,
				name: $name,
				description: $description,
				fileIds: $fileIds,
				userId: (int)CurrentUser::get()->getId(),
			)
		;

		if ($result instanceof KnowledgeBaseInfoResult)
		{
			return [
				'uid' => $result->uid,
				'fileIds' => $result->fileIds,
				'fileIdsReplaces' => $result->fileIdsReplaces,
			];
		}

		$this->addErrors($result->getErrors());

		return null;
	}
}