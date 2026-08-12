<?php

namespace Bitrix\AI\Payload;

class Classify extends Payload implements IPayload
{
	public function __construct(
		protected string $payload
	) {}

	/**
	 * @inheritDoc
	 */
	public function getData(): string
	{
		return (new Formatter($this->payload, $this->engine))->format($this->markers);
	}

	/**
	 * @inheritDoc
	 */
	public function getCost(): int
	{
		if (!is_null($this->customCost))
		{
			return $this->customCost;
		}

		return self::DEFAULT_USAGE_COST;
	}

	/**
	 * @inheritDoc
	 */
	public function pack(): string
	{
		return json_encode([
			'data' => $this->payload,
			'markers' => $this->markers,
			static::PROPERTY_CUSTOM_COST => $this->customCost,
		]);
	}

	/**
	 * @inheritDoc
	 */
	public static function unpack(string $packedData): ?static
	{
		$unpackedData = json_decode($packedData, true);

		if (json_last_error() !== JSON_ERROR_NONE || !is_array($unpackedData))
		{
			return null;
		}

		$payload = (new static($unpackedData['data'] ?? ''))
			->setMarkers(is_array($unpackedData['markers'] ?? null) ? $unpackedData['markers'] : []);
		static::setCustomCost($payload, $unpackedData);

		return $payload;
	}
}
