<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Public\UI\MessageEditor;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Security\Sign\BadSignatureException;
use Bitrix\Main\Security\Sign\TimeSigner;
use Bitrix\Main\Web\Json;
use Bitrix\MessageService\Integration\Notifications;
use Bitrix\MessageService\Public\UI\MessageEditor\NotificationTemplate\Placeholder;

final class NotificationTemplate implements \JsonSerializable
{
	private const SIGNATURE_TTL = '+7 days';
	private const SIGNATURE_SALT = 'messageservice_notifications_template';

	/** @var array<string, Placeholder> */
	private array $placeholders = [];
	private ?array $translation = null;
	private ?string $signed = null;

	public function __construct(
		private readonly string $code,
	)
	{
	}

	public function getCode(): string
	{
		return $this->code;
	}

	/**
	 * @return Placeholder[]
	 */
	public function getPlaceholders(): array
	{
		return array_values($this->placeholders);
	}

	public function setPlaceholder(Placeholder $placeholder): self
	{
		$this->placeholders[$placeholder->getName()] = $placeholder;

		return $this;
	}

	/**
	 * @return array<int, array{name: string, value: string|null}>
	 */
	private function getSignablePlaceholders(): array
	{
		$result = [];
		foreach ($this->placeholders as $placeholder)
		{
			$result[] = [
				'name' => $placeholder->getName(),
				'value' => $placeholder->getValue(),
			];
		}

		return $result;
	}

	public function getTranslation(): ?array
	{
		if ($this->translation !== null)
		{
			return $this->translation;
		}

		$this->translation = Notifications::getTemplateTranslation($this->code);

		return $this->translation;
	}

	public function setTranslation(?array $translation): self
	{
		$this->translation = $translation;

		return $this;
	}

	public function getSigned(): string
	{
		if ($this->signed === null)
		{
			$this->signed = self::signTemplate($this->code, $this->getSignablePlaceholders());
		}

		return $this->signed;
	}

	/**
	 * @param string $signedTemplate
	 * @return self|null - null on error
	 */
	public static function unsign(string $signedTemplate): ?self
	{
		$payload = self::decodeSignedPayload($signedTemplate);
		if ($payload === null)
		{
			return null;
		}

		$template = new self($payload['template']);
		if (isset($payload['placeholders']))
		{
			foreach ($payload['placeholders'] as $item)
			{
				$placeholder = new Placeholder($item['name']);
				if (array_key_exists('value', $item))
				{
					$placeholder->setValue($item['value']);
				}
				$template->setPlaceholder($placeholder);
			}
		}

		return $template;
	}

	/**
	 * @param string $signedTemplate
	 * @return null|array{
	 *     template: string,
	 *     placeholders?: array<array{name: string, value?: string|null}>
	 * }
	 */
	private static function decodeSignedPayload(string $signedTemplate): ?array
	{
		$signer = new TimeSigner();

		try
		{
			$serializedPayload = $signer->unsign($signedTemplate, self::SIGNATURE_SALT);
		}
		catch (BadSignatureException)
		{
			return null;
		}

		try
		{
			$payload = Json::decode(base64_decode($serializedPayload));
		}
		catch (ArgumentException)
		{
			return null;
		}

		if (!is_array($payload))
		{
			return null;
		}

		if (!isset($payload['template']) || !is_string($payload['template']))
		{
			return null;
		}

		$normalizedPayload = [
			'template' => $payload['template'],
		];
		if (isset($payload['placeholders']) && is_array($payload['placeholders']))
		{
			$normalizedPayload['placeholders'] = self::normalizeSignablePlaceholders($payload['placeholders']);
		}

		return $normalizedPayload;
	}

	/**
	 * @param string $templateCode
	 * @param array<array{name: string, value?: string|null}>|null $placeholders
	 */
	private static function signTemplate(string $templateCode, ?array $placeholders): string
	{
		$payload = [
			'template' => $templateCode,
		];
		if (is_array($placeholders))
		{
			$payload['placeholders'] = self::normalizeSignablePlaceholders($placeholders);
		}

		$serializedPayload = base64_encode(Json::encode($payload));

		return (new TimeSigner())->sign($serializedPayload, self::SIGNATURE_TTL, self::SIGNATURE_SALT);
	}

	/**
	 * @param array<array{name?: mixed, value?: mixed}|mixed> $placeholders
	 * @return array<int, array{name: string, value?: string|null}>
	 */
	private static function normalizeSignablePlaceholders(array $placeholders): array
	{
		$result = [];

		foreach ($placeholders as $placeholder)
		{
			if (!is_array($placeholder))
			{
				continue;
			}

			if (!isset($placeholder['name']) || !is_string($placeholder['name']))
			{
				continue;
			}

			$normalized = ['name' => $placeholder['name']];

			if (array_key_exists('value', $placeholder))
			{
				$value = $placeholder['value'];

				if (!is_string($value) && !is_null($value))
				{
					continue;
				}

				$normalized['value'] = $value;
			}

			$result[] = $normalized;
		}

		return $result;
	}

	public function jsonSerialize(): array
	{
		return [
			'code' => $this->code,
			'translation' => $this->getTranslation(),
			'placeholders' => $this->getPlaceholders(),
			'signed' => $this->getSigned(),
		];
	}
}
