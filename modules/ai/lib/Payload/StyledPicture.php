<?php

namespace Bitrix\AI\Payload;

class StyledPicture extends Payload implements IPayload
{
	public function __construct(
		protected string $payload,
	) {}

	/**
	 * @inheritDoc
	 */
	public function getData(): array
	{
		$data = [
			'prompt' => $this->payload,
		];

		if (isset($this->markers['style']))
		{
			$data['style'] = $this->markers['style'];
		}

		if (isset($this->markers['format']))
		{
			$data['format'] = $this->markers['format'];
		}

		if (isset($this->markers['images_number']))
		{
			$data['images_number'] = $this->markers['images_number'];
		}

		return $data;
	}

	public function pack(): string
	{
		return json_encode([
			'prompt' => $this->payload,
			'markers' => $this->markers,
		]);
	}

	public static function unpack(string $packedData): ?static
	{
		$unpackedData = json_decode($packedData, true);

		$prompt = $unpackedData['prompt'] ?? '';
		$markers = $unpackedData['markers'] ?? [];

		return (new self($prompt))->setMarkers($markers);
	}
}