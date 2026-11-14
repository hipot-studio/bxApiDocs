<?php

namespace Bitrix\VisualEditor\Infrastructure\Controller;

use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\Json;
use Bitrix\VisualEditor\Internal\UseCase\ListFiles;
use Bitrix\VisualEditor\Internal\UseCase\ReadFile;
use Bitrix\VisualEditor\Internal\UseCase\SaveFile;
use Bitrix\VisualEditor\Public\Command\UploadImageCommand;

final class Editor extends Controller
{
	public function configureActions(): array
	{
		return [
			'upload' => [
				'-prefilters' => [ActionFilter\ContentType::class],
				'+prefilters' => [new ActionFilter\ContentType(['multipart/form-data'])],
			],
		];
	}

	protected function getDefaultPreFilters(): array
	{
		return [
			new ActionFilter\ContentType([
				ActionFilter\ContentType::JSON,
				'application/x-www-form-urlencoded',
			]),
			new ActionFilter\Authentication(),
			new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
			new ActionFilter\Csrf(),
		];
	}

	public function listAction(string $adapterId, array $context): ?array
	{
		$result = (new ListFiles())->execute($adapterId, $context);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData();
	}

	public function readAction(string $adapterId, array $context, string $path): ?array
	{
		$result = (new ReadFile())->execute($adapterId, $context, $path);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		$data = $result->getData();
		$data['contentBase64'] = $data['content'] === null ? null : base64_encode($data['content']);
		unset($data['content']);

		return $data;
	}

	public function saveAction(string $adapterId, array $context, array $changeSet): ?array
	{
		$result = (new SaveFile())->execute($adapterId, $context, $changeSet);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData();
	}

	public function uploadAction(string $adapterId, string $context, string $page): ?array
	{
		try
		{
			$decoded = Json::decode($context);
		}
		catch (\Bitrix\Main\ArgumentException)
		{
			$decoded = null;
		}
		if (!is_array($decoded) || !str_starts_with(ltrim($context), '{'))
		{
			Loc::loadMessages(dirname(__DIR__, 2) . '/Internal/Service/Editor/ChangeSetApplier.php');
			$this->addError(new Error(Loc::getMessage('VISUALEDITOR_CHANGESET_REJECTED'), 'write-rejected'));

			return null;
		}
		$file = $this->getRequest()->getFile('file');
		$result = (new UploadImageCommand())->execute(
			$adapterId,
			$decoded,
			$page,
			is_array($file) ? $file : [],
		);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData();
	}
}
