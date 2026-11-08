<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\WebForm;

use Bitrix\Crm\UI\Webpack;
use Bitrix\Crm\V2\Internal\Entity\WebForm\Embedding\Embedding;
use Bitrix\Crm\V2\Internal\Entity\WebForm\Embedding\EmbeddingVariant;
use Bitrix\Crm\V2\Internal\Entity\WebForm\Embedding\ViewOptions;
use Bitrix\Crm\V2\Internal\Entity\WebForm\Embedding\ViewSettings;
use Bitrix\Crm\WebForm\Internals\FormTable;
use Bitrix\Crm\WebForm\Options\Dictionary;
use Bitrix\Crm\WebForm\Telemetry\SourceContextSigner;
use Bitrix\Crm\WebForm\Telemetry\SubmissionClassifier;

/**
 * @internal
 */
final class EmbeddingRepository
{
	/**
	 * Returns the direct embedding options of a default CRM form.
	 *
	 * The read never builds the form resources: while they are missing every embed code stays null
	 * instead of turning into a snippet with an empty body.
	 */
	public function getByFormId(int $formId): ?Embedding
	{
		$row = self::fetchDefaultForm($formId, ['ID', 'ACTIVE', 'SECURITY_CODE', 'FORM_SETTINGS']);
		if ($row === null)
		{
			return null;
		}

		$id = (int)$row['ID'];
		$codeByVariant = self::readEmbedCodes($id, (string)($row['SECURITY_CODE'] ?? ''));
		$storedViews = self::readStoredViews($row);
		$dictionaryViews = self::readDictionaryViews();

		return new Embedding(
			formId: $id,
			formActive: self::isActive($row),
			resourcesReady: $codeByVariant !== null,
			inline: new EmbeddingVariant(
				name: EmbeddingVariant::INLINE,
				code: $codeByVariant[EmbeddingVariant::INLINE] ?? null,
			),
			click: self::createConfigurableVariant(
				EmbeddingVariant::CLICK,
				$codeByVariant,
				$storedViews,
				$dictionaryViews,
			),
			auto: self::createConfigurableVariant(
				EmbeddingVariant::AUTO,
				$codeByVariant,
				$storedViews,
				$dictionaryViews,
			),
		);
	}

	/**
	 * The two codes every public link and embed snippet of a form is built from.
	 *
	 * They are answered apart from the embedding on purpose: the security code of a form belongs
	 * inside links and snippets, and nowhere a projection of the embedding model could pick it up.
	 *
	 * @return null|array{code: string, securityCode: string}
	 */
	public function getLinkCodesByFormId(int $formId): ?array
	{
		$row = self::fetchDefaultForm($formId, ['CODE', 'SECURITY_CODE']);
		if ($row === null)
		{
			return null;
		}

		return [
			'code' => (string)($row['CODE'] ?? ''),
			'securityCode' => (string)($row['SECURITY_CODE'] ?? ''),
		];
	}

	/**
	 * The values the settings of one embedding variant accept, as the read publishes them beside
	 * those settings. A write of the settings asks the same source, so a value a client may send is
	 * a value the client was offered.
	 */
	public function getViewOptions(string $variant): ViewOptions
	{
		return ViewOptions::forVariant($variant, self::readDictionaryViews());
	}

	/**
	 * The dictionary of the form editor the allowed values of a variant are built from.
	 *
	 * @return array<mixed>
	 */
	private static function readDictionaryViews(): array
	{
		return Dictionary::instance()->getViews();
	}

	/**
	 * The one place this storage writes down what an ordinary CRM form is.
	 *
	 * @param list<string> $select
	 * @return null|array<string, mixed>
	 */
	private static function fetchDefaultForm(int $formId, array $select): ?array
	{
		$row =
			FormTable::query()
				->setSelect($select)
				->where('ID', $formId)
				->where('TYPE_ID', FormTable::TYPE_DEFAULT)
				->setLimit(1)
				->fetch()
		;

		return is_array($row) ? $row : null;
	}

	/**
	 * @param null|array<string, string> $codeByVariant
	 * @param array<mixed> $storedViews
	 * @param array<mixed> $dictionaryViews
	 */
	private static function createConfigurableVariant(
		string $variant,
		?array $codeByVariant,
		array $storedViews,
		array $dictionaryViews,
	): EmbeddingVariant
	{
		return new EmbeddingVariant(
			name: $variant,
			code: $codeByVariant[$variant] ?? null,
			settings: ViewSettings::fromStoredViews($storedViews, $variant),
			options: ViewOptions::forVariant($variant, $dictionaryViews),
		);
	}

	/**
	 * Reads the embed code of every variant off the existing resource pack. Returns null while the
	 * pack has no usable file, so that no caller can receive an empty snippet.
	 *
	 * @return null|array<string, string>
	 */
	private static function readEmbedCodes(int $formId, string $securityCode): ?array
	{
		$webpack = Webpack\Form::instance($formId);
		if (!$webpack->isBuilt())
		{
			return null;
		}

		// The lazy file configuration of the pack resets the embed attribute to the inline action,
		// so it has to happen before the variants are configured, not in the middle of them.
		if ($webpack->getEmbeddedFileUrl() === '')
		{
			return null;
		}

		$codes = [];
		$telemetrySign = (new SourceContextSigner())->sign($formId, SubmissionClassifier::SOURCE_SITE);
		foreach ([EmbeddingVariant::INLINE, EmbeddingVariant::CLICK, EmbeddingVariant::AUTO] as $variant)
		{
			$codes[$variant] = $webpack
				->configureFormEmbeddedScript([
					'action' => $variant,
					'sec' => $securityCode,
					'telemetrySign' => $telemetrySign,
				])
				->getEmbeddedScript()
			;
		}

		return $codes;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<mixed>
	 */
	private static function readStoredViews(array $row): array
	{
		$settings = $row['FORM_SETTINGS'] ?? null;
		$views = is_array($settings) ? ($settings['VIEWS'] ?? null) : null;

		return is_array($views) ? $views : [];
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private static function isActive(array $row): bool
	{
		$value = $row['ACTIVE'] ?? null;

		return $value === true || $value === 'Y' || $value === 1 || $value === '1';
	}
}
