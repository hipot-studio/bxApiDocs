<?php declare(strict_types=1);

namespace Bitrix\AI\Image;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\SystemException;
use Bitrix\Main\Web\IpAddress;
use Bitrix\Main\Web\Uri;

class ImageReference
{
	private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
	private const DEFAULT_MIME_TYPE = 'image/jpeg';
	private const ALLOWED_URL_SCHEMES = ['http', 'https'];

	private function __construct(
		private ?string $url = null,
		private ?string $base64Data = null,
		private string $mimeType = self::DEFAULT_MIME_TYPE,
		private ?int $fileId = null,
	) {}

	public static function fromUrl(string $url): self
	{
		if (!self::isUrlSafe($url))
		{
			throw new SystemException('Invalid or unsafe image URL');
		}

		return new self(url: $url);
	}

	private static function isUrlSafe(string $url): bool
	{
		if ($url === '')
		{
			return false;
		}

		$uri = new Uri($url);
		$scheme = strtolower($uri->getScheme());
		$host = $uri->getHost();

		if ($host === '' || !in_array($scheme, self::ALLOWED_URL_SCHEMES, true))
		{
			return false;
		}

		if (IpAddress::createByUri($uri)->isPrivate())
		{
			return false;
		}

		return true;
	}

	public static function fromBase64(string $data, string $mimeType = self::DEFAULT_MIME_TYPE): self
	{
		if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true))
		{
			$mimeType = self::DEFAULT_MIME_TYPE;
		}

		return new self(base64Data: $data, mimeType: $mimeType);
	}

	public static function fromFileId(int $fileId): self
	{
		return new self(fileId: $fileId);
	}

	public static function fromArray(array $data): ?self
	{
		if (isset($data['file_id']) && (int)$data['file_id'] > 0)
		{
			return self::fromFileId((int)$data['file_id']);
		}

		if (isset($data['url']) && $data['url'] !== '')
		{
			return self::fromUrl($data['url']);
		}

		if (isset($data['data']) && $data['data'] !== '')
		{
			return self::fromBase64($data['data'], $data['mime_type'] ?? self::DEFAULT_MIME_TYPE);
		}

		return null;
	}

	public function isUrl(): bool
	{
		return $this->url !== null;
	}

	public function isBase64(): bool
	{
		return $this->base64Data !== null;
	}

	public function isFileId(): bool
	{
		return $this->fileId !== null;
	}

	public function getFileId(): ?int
	{
		return $this->fileId;
	}

	public function toApiUrl(int $userId): string
	{
		if ($this->url !== null)
		{
			return $this->url;
		}

		if ($this->fileId !== null)
		{
			$url = ServiceLocator::getInstance()->get(DiskFileUrlResolver::class)->resolve($this->fileId, $userId);
			if ($url === null)
			{
				throw new SystemException(
					"Failed to resolve Disk file ID {$this->fileId} to a public URL"
				);
			}

			return $url;
		}

		return "data:{$this->mimeType};base64,{$this->base64Data}";
	}

	public function toDiagnostic(): array
	{
		if ($this->fileId !== null)
		{
			return ['type' => 'file', 'file_id' => $this->fileId];
		}

		if ($this->url !== null)
		{
			return ['type' => 'url', 'url' => $this->url];
		}

		return [
			'type' => 'base64',
			'mime_type' => $this->mimeType,
			'size_bytes' => (int)(strlen($this->base64Data) * 3 / 4),
		];
	}

	public function toArray(): array
	{
		if ($this->fileId !== null)
		{
			return ['file_id' => $this->fileId];
		}

		if ($this->url !== null)
		{
			return ['url' => $this->url];
		}

		return ['data' => $this->base64Data, 'mime_type' => $this->mimeType];
	}

	public function toStorable(): array
	{
		if ($this->fileId !== null)
		{
			return ['file_id' => $this->fileId];
		}

		if ($this->url !== null)
		{
			return ['url' => $this->url];
		}

		return [
			'type' => 'base64',
			'mime_type' => $this->mimeType,
			'size_bytes' => (int)(strlen($this->base64Data) * 3 / 4),
			'content_hash' => md5($this->base64Data),
		];
	}

	public static function fromStorable(array $data): ?self
	{
		if (isset($data['file_id']) && (int)$data['file_id'] > 0)
		{
			return self::fromFileId((int)$data['file_id']);
		}

		if (isset($data['url']) && $data['url'] !== '')
		{
			return self::fromUrl($data['url']);
		}

		return null;
	}
}
