<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Marketplace\Banner;

final readonly class BannerProjection
{
	public function __construct(
		public string $id,
		public string $source,
		public string $state,
		public string $title,
		public string $description,
		public string $actionType,
		public string $actionLabel,
		public ?string $actionUrl,
		public ?string $backgroundImageUrl = null,
		public ?string $textColor = null,
		public ?string $buttonBackgroundColor = null,
		public ?string $buttonTextColor = null,
	)
	{
	}

	public static function fromArray(array $data): ?self
	{
		$action = $data['action'] ?? null;
		if (
			!is_string($data['id'] ?? null)
			|| !is_string($data['source'] ?? null)
			|| !is_string($data['state'] ?? null)
			|| !is_string($data['title'] ?? null)
			|| !is_string($data['description'] ?? null)
			|| !is_array($action)
			|| !is_string($action['type'] ?? null)
			|| !is_string($action['label'] ?? null)
			|| !self::isNullableString($action['url'] ?? null)
		)
		{
			return null;
		}

		$styling = RemoteStyling::normalize($data['source'] === 'local' ? [] : $data);

		return new self(
			$data['id'],
			$data['source'],
			$data['state'],
			$data['title'],
			$data['description'],
			$action['type'],
			$action['label'],
			$action['url'] ?? null,
			$styling['backgroundImageUrl'],
			$styling['textColor'],
			$styling['buttonBackgroundColor'],
			$styling['buttonTextColor'],
		);
	}

	/**
	 * @return array{
	 *     id: string,
	 *     source: string,
	 *     state: string,
	 *     title: string,
	 *     description: string,
	 *     backgroundImageUrl: string|null,
	 *     textColor: string|null,
	 *     buttonBackgroundColor: string|null,
	 *     buttonTextColor: string|null,
	 *     action: array{type: string, label: string, url: string|null}
	 * }
	 */
	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'source' => $this->source,
			'state' => $this->state,
			'title' => $this->title,
			'description' => $this->description,
			...RemoteStyling::normalize($this->source === 'local' ? [] : [
				'backgroundImageUrl' => $this->backgroundImageUrl,
				'textColor' => $this->textColor,
				'buttonBackgroundColor' => $this->buttonBackgroundColor,
				'buttonTextColor' => $this->buttonTextColor,
			]),
			'action' => [
				'type' => $this->actionType,
				'label' => $this->actionLabel,
				'url' => $this->actionUrl,
			],
		];
	}

	private static function isNullableString(mixed $value): bool
	{
		return $value === null || is_string($value);
	}
}
