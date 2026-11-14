<?php

namespace Bitrix\VisualEditor\Public\Command;

use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\VisualEditor\Public\Adapter\AdapterRegistry;
use Bitrix\VisualEditor\Public\Adapter\EditorUploadInterface;
use Bitrix\VisualEditor\Public\Adapter\UploadedImage;

final class UploadImageCommand
{
	private const MAX_BYTES = 10485760;
	private const MIME_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

	public function __construct(private readonly AdapterRegistry $registry = new AdapterRegistry())
	{
	}

	public function execute(string $adapterId, array $context, string $page, array $file): Result
	{
		$adapter = $this->registry->get($adapterId);
		if (!$adapter instanceof EditorUploadInterface)
		{
			return $this->error('adapter-error');
		}

		if ($page === '' || str_contains($page, "\0"))
		{
			return $this->error('write-rejected');
		}
		$image = $this->readImage($file);
		if ($image === null)
		{
			return $this->error('write-rejected');
		}

		$result = $adapter->upload($context, $page, $image);
		if (!$result->isSuccess())
		{
			return $result;
		}
		$data = $result->getData();
		if (
			($data['ok'] ?? null) !== true
			|| array_key_exists('mode', $data)
			|| !$this->isSafeSource($data['src'] ?? null)
			|| !$this->isRelativePath($data['path'] ?? null)
		)
		{
			return $this->error('adapter-error');
		}

		return (new Result())->setData(['ok' => true, 'src' => $data['src'], 'path' => $data['path']]);
	}

	private function readImage(array $file): ?UploadedImage
	{
		$temporary = $file['tmp_name'] ?? null;
		if (
			($file['error'] ?? null) !== UPLOAD_ERR_OK
			|| !is_string($temporary)
			|| !is_uploaded_file($temporary)
			|| !is_string($file['name'] ?? null)
		)
		{
			return null;
		}

		$size = @filesize($temporary);
		if (!is_int($size) || $size < 1 || $size > self::MAX_BYTES)
		{
			return null;
		}
		$bytes = @file_get_contents($temporary, false, null, 0, self::MAX_BYTES + 1);
		if (!is_string($bytes) || strlen($bytes) !== $size)
		{
			return null;
		}

		$info = @getimagesizefromstring($bytes);
		if (
			!is_array($info)
			|| ($info[0] ?? 0) < 1
			|| ($info[1] ?? 0) < 1
			|| !in_array($info['mime'] ?? null, self::MIME_TYPES, true)
		)
		{
			return null;
		}

		return new UploadedImage($file['name'], $info['mime'], $bytes);
	}

	private function isSafeSource(mixed $source): bool
	{
		if (!is_string($source) || $source === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $source) !== 0)
		{
			return false;
		}
		if (str_starts_with($source, '//'))
		{
			return false;
		}
		if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $source) === 1)
		{
			return preg_match('#^https?://[^/]+(?:/|$)#i', $source) === 1;
		}

		return true;
	}

	private function isRelativePath(mixed $path): bool
	{
		if (!is_string($path) || $path === '')
		{
			return false;
		}
		$decoded = rawurldecode($path);
		if (
			str_starts_with($decoded, '/')
			|| preg_match('/[\x00-\x20\x7f\\\\:%?#]/', $decoded) !== 0
		)
		{
			return false;
		}
		foreach (explode('/', $decoded) as $segment)
		{
			if ($segment === '' || $segment === '.' || $segment === '..')
			{
				return false;
			}
		}

		return true;
	}

	private function error(string $code): Result
	{
		$root = dirname(__DIR__, 2);
		if ($code === 'adapter-error')
		{
			Loc::loadMessages($root . '/Internal/UseCase/ListFiles.php');
			$message = Loc::getMessage('VISUALEDITOR_ADAPTER_NOT_FOUND');
		}
		else
		{
			Loc::loadMessages($root . '/Internal/Service/Editor/ChangeSetApplier.php');
			$message = Loc::getMessage('VISUALEDITOR_CHANGESET_REJECTED');
		}

		return (new Result())->addError(new Error($message, $code));
	}
}
