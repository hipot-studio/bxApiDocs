<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\LegalRegistry;

use Bitrix\Crm\V2\Public\Entity\LegalRegistry\CatalogVersion;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\ExpiryRule;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\UserConsent\Agreement;
use Bitrix\Main\UserConsent\ConsentUrl;
use Bitrix\Main\UserConsent\Text;

/** Sanctioned one-time snapshot of current Main content; never copies consent history. */
class MainAgreementSnapshotGateway
{
	public static function getInstance(): self
	{
		$locator = ServiceLocator::getInstance();
		$gateway = $locator->has(self::class) ? $locator->get(self::class) : null;

		return $gateway instanceof self ? $gateway : new self();
	}

	public function captureCurrent(int $agreementId): array
	{
		if ($agreementId <= 0 || $agreementId > 2147483647)
		{
			throw new \UnexpectedValueException('NOT_FOUND_OR_DENIED');
		}
		$agreement = $this->createAgreement($agreementId);
		if (!$agreement->isExist() || !$agreement->isActive())
		{
			throw new \UnexpectedValueException('NOT_FOUND_OR_DENIED');
		}
		$data = $agreement->getData();
		$url = $agreement->getUrl();
		if ($url !== null && !ConsentUrl::isAllowed($url))
		{
			throw new \InvalidArgumentException('Invalid snapshot URL.');
		}
		$schema = [];
		if ($data['TYPE'] === Agreement::TYPE_STANDARD)
		{
			$snapshot = $this->standardSnapshot($agreement);
			$static = [];
			foreach ($snapshot['values'] as $key => $value)
			{
				$key = mb_strtolower((string)$key);
				if ($key !== 'fields') { $static[$key] = $value; }
			}
			$staticSchema = [];
			foreach ($static as $key => $value)
			{
				$staticSchema[$key] = match (true)
				{
					is_array($value) => 'string[]', is_bool($value) => 'boolean',
					is_int($value) => 'integer', is_float($value) => 'number',
					default => 'string',
				};
			}
			(new VersionRenderer())->validateAgainstSchema($staticSchema, $static);
			foreach ($static as $value)
			{
				foreach (is_array($value) ? $value : [$value] as $literal)
				{
					if (preg_match('/%(?:fields|cut\.fields\.(?:start|end))%/', (string)$literal) === 1)
					{
						// A static literal cannot become an indistinguishable frozen runtime token.
						throw new \InvalidArgumentException('Ambiguous static snapshot.');
					}
				}
			}

			[$protected, $tokens] = $this->protectRuntime($snapshot['template']);
			$template = strtr(trim(Text::replace($protected, $static, true)), $tokens);
			$rawLabel = Text::replace($snapshot['label'], $static);
			$hasRuntimeLabel = preg_match('/%(?:fields|cut\.fields\.(?:start|end))%/', $rawLabel) === 1;
			$label = $this->freezeLabel($agreement, $rawLabel);
			if ($hasRuntimeLabel || preg_match('/%(?:fields|cut\.fields\.(?:start|end))%/', $template) === 1)
			{
				$schema = ['fields' => 'string[]'];
			}
			if ($agreement->isAgreementTextHtml()) { $template = VersionRenderer::sanitizeHtml($template); }
		}
		else
		{
			// Main custom content intentionally has no template substitution grammar.
			$template = $agreement->getHtml(); $label = $agreement->getLabel();
		}
		$version = new CatalogVersion(null, null, null, null, 'draft', (string)$data['LANGUAGE_ID'],
			$template, $label, $schema, hash('sha256', $template), new ExpiryRule('none'), null, null, $url);
		(new VersionRenderer())->validateContent($version);

		return ['name' => (string)$data['NAME'], 'version' => $version];
	}

	protected function createAgreement(int $id): Agreement
	{
		return new class($id) extends Agreement {
			public function registrySnapshot(): array
			{
				return ['template' => $this->intl->getText(), 'label' => $this->intl->getLabelText(),
					'values' => $this->getDataProviderValues() + $this->getReplaceFieldValues()];
			}
		};
	}

	protected function standardSnapshot(Agreement $agreement): array { return $agreement->registrySnapshot(); }

	private function freezeLabel(Agreement $agreement, string $label): string
	{
		[$protected, $tokens] = $this->protectRuntime($label);
		// An isolated custom clone delegates native label/link rules without touching Main storage.
		$copy = clone $agreement;
		$copy->setData(array_replace($agreement->getData(), [
			'TYPE' => Agreement::TYPE_CUSTOM,
			'LABEL_TEXT' => $protected,
		]));
		$copy->setReplace([]);

		return strtr($copy->getLabel(), $tokens);
	}

	private function protectRuntime(string $text): array
	{
		$prefix = 'LEGALREGISTRY' . hash('sha256', $text) . 'TOKEN';
		while (str_contains($text, $prefix)) { $prefix .= 'TOKEN'; }
		$tokens = [];
		$protected = preg_replace_callback('/%(?:fields|cut\.fields\.(?:start|end))%/',
			static function (array $match) use (&$tokens, $prefix): string {
				$key = $prefix . count($tokens); $tokens[$key] = $match[0];

				return $key;
			}, $text);

		return [$protected, $tokens];
	}
}
