<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Data\AiMeta;

use Bitrix\Landing\Copilot\Data\Type\HtmlBlockNodeType;

final class AiMetaNode
{
	public function __construct(
		private string $nodeCode,
		private HtmlBlockNodeType $type,
		private string $name,
	)
	{
	}

	public static function fromArray(mixed $data): ?self
	{
		if (!is_array($data))
		{
			return null;
		}

		$nodeCode = trim((string)($data['nodeCode'] ?? $data['selector'] ?? ''));
		$type = HtmlBlockNodeType::tryFrom(mb_strtolower(trim((string)($data['type'] ?? ''))));
		$name = trim((string)($data['name'] ?? ''));

		if ($nodeCode === '' || $type === null)
		{
			return null;
		}

		if ($name === '')
		{
			$name = ltrim($nodeCode, '.');
			if (str_starts_with($name, 'ai-node-'))
			{
				$name = mb_substr($name, 8);
			}
		}

		return new self($nodeCode, $type, $name);
	}

	public function toArray(): array
	{
		return [
			'nodeCode' => $this->nodeCode,
			'type' => $this->type->value,
			'name' => $this->name,
		];
	}

	public function getNodeCode(): string
	{
		return $this->nodeCode;
	}

	public function getType(): HtmlBlockNodeType
	{
		return $this->type;
	}

	public function getName(): string
	{
		return $this->name;
	}
}
