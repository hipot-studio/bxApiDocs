<?php

namespace Bitrix\VisualEditor\Internal\UseCase;

use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\VisualEditor\Internal\Service\Editor\ChangeSetProcessor;
use Bitrix\VisualEditor\Internal\Service\Editor\ChangeSetValidator;
use Bitrix\VisualEditor\Public\Adapter\AdapterRegistry;
use Bitrix\VisualEditor\Public\Adapter\EditorAdapterInterface;
use Bitrix\VisualEditor\Public\Adapter\EditorCapabilities;
use Bitrix\VisualEditor\Public\Adapter\EditorCapabilitiesInterface;

final class SaveFile
{
	public function __construct(private readonly AdapterRegistry $registry = new AdapterRegistry())
	{
	}

	public function execute(string $adapterId, array $context, array $changeSet): Result
	{
		$adapter = $this->registry->get($adapterId);
		if ($adapter === null)
		{
			Loc::loadMessages(__DIR__ . '/ListFiles.php');

			return (new Result())->addError(new Error(
				Loc::getMessage('VISUALEDITOR_ADAPTER_NOT_FOUND'),
				'adapter-error',
			));
		}
		try
		{
			$capabilities = $this->getCapabilities($adapter, $context);
		}
		catch (\InvalidArgumentException|\TypeError|\ValueError)
		{
			Loc::loadMessages(__DIR__ . '/../Service/Editor/ChangeSetValidator.php');

			return (new Result())->addError(new Error(
				Loc::getMessage('VISUALEDITOR_CHANGESET_REJECTED'),
				'write-rejected',
			));
		}
		if (!is_string($changeSet['path'] ?? null))
		{
			Loc::loadMessages(__DIR__ . '/../Service/Editor/ChangeSetValidator.php');

			return (new Result())->addError(new Error(
				Loc::getMessage('VISUALEDITOR_CHANGESET_REJECTED'),
				'write-rejected',
			));
		}

		$validation = (new ChangeSetValidator())->validateEnvelope($changeSet, $capabilities);
		if (!$validation->isSuccess())
		{
			return $validation;
		}

		$read = $adapter->read($context, $changeSet['path']);
		if (!$read->isSuccess())
		{
			return $read;
		}
		$baseline = $read->getData()['content'] ?? null;
		if (!is_string($baseline))
		{
			Loc::loadMessages(__DIR__ . '/ReadFile.php');

			return (new Result())->addError(new Error(Loc::getMessage('VISUALEDITOR_READ_ERROR'), 'read-error'));
		}

		$processed = (new ChangeSetProcessor())->process($baseline, $changeSet, $capabilities);
		if (!$processed->isSuccess())
		{
			return $processed;
		}
		$data = $processed->getData();
		$changeSet['content'] = $data['html'];
		$saved = $adapter->save($context, $changeSet);
		if (!$saved->isSuccess())
		{
			return $saved;
		}

		return (new Result())->setData(['ok' => true, 'version' => $data['version']]);
	}

	private function getCapabilities(EditorAdapterInterface $adapter, array $context): EditorCapabilities
	{
		return $adapter instanceof EditorCapabilitiesInterface
			? $adapter->getCapabilities($context)
			: new EditorCapabilities();
	}
}
