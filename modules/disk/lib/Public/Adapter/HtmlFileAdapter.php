<?php

declare(strict_types=1);

namespace Bitrix\Disk\Public\Adapter;

use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Service\HtmlEditor\AiLockService;
use Bitrix\Disk\Internal\Service\HtmlEditor\SaveService;
use Bitrix\Disk\Internal\Service\HtmlEditor\SessionService;
use Bitrix\Disk\Internal\Service\HtmlEditor\SourceResolver;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\VisualEditor\Public\Adapter\EditorAdapterInterface;
use Bitrix\VisualEditor\Public\Adapter\EditorCapabilities;
use Bitrix\VisualEditor\Public\Adapter\EditorCapabilitiesInterface;

final class HtmlFileAdapter implements EditorAdapterInterface, EditorCapabilitiesInterface
{
	private const PATH = 'index.html';
	private const MAX_OPS = 500;

	/**
	 * Returns the server policy matching the SDK operation limit.
	 *
	 * @param array $context
	 * @return EditorCapabilities
	 */
	public function getCapabilities(array $context): EditorCapabilities
	{
		return new EditorCapabilities(maxOps: self::MAX_OPS);
	}

	public function list(array $context): Result
	{
		$resolved = $this->resolve($context);
		if (!$resolved->isSuccess())
		{
			return $resolved;
		}

		return (new Result())->setData(['paths' => [self::PATH], 'truncated' => false]);
	}

	public function read(array $context, string $path): Result
	{
		if ($path !== self::PATH)
		{
			return $this->error('access_denied');
		}
		$resolved = $this->resolve($context);
		if (!$resolved->isSuccess())
		{
			return $resolved;
		}
		$file = $resolved->getData()['file'];
		$fileArray = \CFile::makeFileArray((int)$file->getFileId());
		if (empty($fileArray['tmp_name']) || !is_file($fileArray['tmp_name']))
		{
			return $this->error('access_denied');
		}
		$content = file_get_contents($fileArray['tmp_name']);
		if ($content === false)
		{
			return $this->error('access_denied');
		}

		return (new Result())->setData(['content' => $content]);
	}

	public function save(array $context, array $changeSet): Result
	{
		$path = $changeSet['path'] ?? null;
		$content = $changeSet['content'] ?? null;
		$baseVersion = $changeSet['baseVersion'] ?? null;
		if ($path !== self::PATH || !is_string($content))
		{
			return $this->error('access_denied');
		}
		if (!is_string($baseVersion) || preg_match('/^[0-9a-f]{12}$/D', $baseVersion) !== 1)
		{
			return $this->error('content_version_conflict');
		}
		$resolved = $this->resolve($context);
		if (!$resolved->isSuccess())
		{
			return $resolved;
		}
		$data = $resolved->getData();

		$result = (new SaveService())->saveHtml(
			$data['file'],
			$data['securityContext'],
			$context['sessionHash'],
			$content,
			$baseVersion,
			$data['shouldCheckUnifiedLink'],
		);
		if ($result->getError()?->getCode() === AiLockService::ERROR_LOCKED)
		{
			return $this->error('file_locked');
		}

		return $result;
	}

	private function resolve(array $context): Result
	{
		$fileId = $context['fileId'] ?? null;
		$attachedObjectId = $context['attachedObjectId'] ?? null;
		$uniqueCode = $context['uniqueCode'] ?? null;
		$sessionHash = $context['sessionHash'] ?? null;
		$userId = (int)CurrentUser::get()->getId();
		if (
			!is_int($fileId) || $fileId <= 0
			|| ($attachedObjectId !== null && (!is_int($attachedObjectId) || $attachedObjectId <= 0))
			|| ($uniqueCode !== null && (!is_string($uniqueCode) || $uniqueCode === ''))
			|| !is_string($sessionHash) || $sessionHash === ''
			|| $userId <= 0
		)
		{
			return $this->error('access_denied');
		}
		$file = File::loadById($fileId);
		$realFile = $file?->getRealObject();
		if (!$realFile instanceof File)
		{
			return $this->error('access_denied');
		}
		if ($uniqueCode !== null && !hash_equals((string)$realFile->getUniqueCode(), $uniqueCode))
		{
			return $this->error('access_denied');
		}
		$shouldCheckUnifiedLink = $uniqueCode !== null;
		$source = (new SourceResolver())->resolve($file, $userId, $attachedObjectId, $shouldCheckUnifiedLink);
		if (!$source->isSuccess())
		{
			return $source;
		}
		$data = $source->getData();
		$active = (new SessionService())->resolveActive($data['file'], $data['securityContext'], $sessionHash);
		if (!$active->isSuccess())
		{
			return $active;
		}
		$sessionContext = $active->getData()['session']->getContext();
		if (
			$sessionContext === null
			|| $sessionContext->getAttachedObjectId() !== $attachedObjectId
			|| $sessionContext->isHtmlUnifiedLinkValidated() !== $shouldCheckUnifiedLink
		)
		{
			return $this->error('access_denied');
		}

		return (new Result())->setData($data + ['shouldCheckUnifiedLink' => $shouldCheckUnifiedLink]);
	}

	private function error(string $code): Result
	{
		return (new Result())->addError(new Error($code, $code));
	}
}
