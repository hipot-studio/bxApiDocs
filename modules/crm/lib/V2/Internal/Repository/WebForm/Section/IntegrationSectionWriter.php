<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\WebForm\Section;

use Bitrix\Crm\Ads\Internals\AdsFormLinkTable;
use Bitrix\Crm\V2\Internal\Entity\WebForm\Section\AdFieldMapping;
use Bitrix\Crm\V2\Internal\Entity\WebForm\Section\AdIntegration;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebForm;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebFormChangeSet;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebFormPath;
use Bitrix\Crm\WebForm\Form;
use Bitrix\Crm\WebForm\Internals\FormFieldMappingTable;
use Bitrix\Main\Error;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\Result;

/**
 * Writes the ad account links of a CRM form and their field mappings, mirroring
 * IntegrationSectionLoader.
 *
 * A link is identified by the provider, the account and the ad form: a link the input repeats keeps
 * its row, a new one is created, and a stored link the input leaves out is removed together with its
 * mappings. The mappings of a kept link are rewritten by the list the input carries for it and left
 * alone when it carries none. Links of the export direction belong to another write path and are
 * never removed here.
 *
 * The links carry a subscription in the seo module, so they go through the legacy crm mechanism
 * owning that subscription instead of the module itself: an internal writer calls nothing outside
 * Bitrix\Crm and Bitrix\Main. Calling a legacy business operation from here is a transitional
 * exception, and it is bounded: the mechanism is reached from this writer and from the delete
 * cascade of the form, nowhere else in the layer, and it is asked for the links of one form only.
 * The exception goes away when the mechanism itself moves into the layer together with the
 * subscription it owns; until then duplicating the subscription here would leave two implementations
 * of one rule.
 *
 * Its refusals, subscription failures included, reach the caller with their texts kept and addressed
 * to the section of the input they came from: the mechanism answers with errors of its own that
 * carry no code, and an error without a code reaches the client as a message belonging to no
 * property at all.
 *
 * The flag of the messenger integration is a column of the form row: FormWriteRepository writes it.
 *
 * @internal
 */
final class IntegrationSectionWriter extends WebFormSectionWriter
{
	private const ADS_PATH = WebFormPath::ADS;
	private const INTEGRATION_PARAMETER = 'INTEGRATION';
	private const FIELD_MAPPING_REFERENCE = 'FIELD_MAPPING';

	public function getSectionName(): string
	{
		return WebForm::SECTION_INTEGRATIONS;
	}

	protected function writeTouched(int $formId, WebFormChangeSet $changeSet): Result
	{
		if (!$changeSet->has(self::ADS_PATH))
		{
			return new Result();
		}

		/** @var AdIntegration[] $ads */
		$ads = $changeSet->get(self::ADS_PATH);

		// The mechanism reads the id and the links of the form and nothing else, so the form is
		// carried to it as it is instead of being loaded from the storage a second time.
		$form = new Form();
		$form->setId($formId);
		$form->merge([self::INTEGRATION_PARAMETER => self::createLinks($formId, $ads)]);

		return self::atAdsPath($form->getIntegration()->save());
	}

	/**
	 * The result of the mechanism with every refusal addressed to the collection of the input.
	 *
	 * None of the codes the mechanism answers with is an input path - the checks of the links raise
	 * errors with no code, and the ones of the storage name a column of a table of their own - so the
	 * whole result is addressed to the section the input arrived in. Without it the refusal travels
	 * out as a text belonging to nothing, and the caller has no property to point its user at.
	 */
	private static function atAdsPath(Result $result): Result
	{
		if ($result->isSuccess())
		{
			return $result;
		}

		$addressed = new Result();
		foreach ($result->getErrors() as $error)
		{
			$addressed->addError(
				new Error($error->getMessage(), self::ADS_PATH, $error->getCustomData()),
			);
		}

		return $addressed;
	}

	/**
	 * The links as the mechanism takes them: it rewrites the mappings of every link it is given, so
	 * a link whose mappings the input leaves unspecified is handed the ones it already has.
	 *
	 * Without that, an unspecified list would arrive as an empty one and the mechanism would clear
	 * what is stored - a link changed in its account name alone would lose every mapping of its
	 * fields. Unspecified means unchanged here as it does everywhere else in an element of a
	 * collection.
	 *
	 * @param AdIntegration[] $ads
	 * @return array<array<string, mixed>>
	 */
	private static function createLinks(int $formId, array $ads): array
	{
		$storedMappings = self::loadStoredFieldMappings($formId, $ads);

		$links = [];
		foreach ($ads as $ad)
		{
			$links[] = [
				// A link created by this path imports leads: the export direction has its own one.
				'LINK_DIRECTION' => $ad->direction ?? AdsFormLinkTable::LINK_DIRECTION_IMPORT,
				'ADS_TYPE' => $ad->providerCode,
				'ADS_ACCOUNT_ID' => $ad->accountId,
				'ADS_ACCOUNT_NAME' => $ad->accountName,
				'ADS_FORM_ID' => $ad->formId,
				'ADS_FORM_NAME' => $ad->formName,
				'FIELDS_MAPPING' => $ad->fieldMappings === null
					? ($storedMappings[self::originOf($ad)] ?? [])
					: self::createFieldMappings($ad->fieldMappings, $storedMappings[self::originOf($ad)] ?? [])
				,
			];
		}

		return $links;
	}

	/**
	 * Mappings the form stores for the links of the input, keyed the way the mechanism identifies a
	 * link: by the provider, the account and the ad form.
	 *
	 * They stand in for a list the input leaves unspecified, and they carry the service data of a
	 * mapping the input names again - the values an ad field is translated by, which this contract
	 * does not publish and a rewrite would otherwise drop.
	 *
	 * @param AdIntegration[] $ads
	 * @return array<string, array<array<string, mixed>>>
	 */
	private static function loadStoredFieldMappings(int $formId, array $ads): array
	{
		$origins = [];
		foreach ($ads as $ad)
		{
			$origins[self::originOf($ad)] = true;
		}

		if ($formId <= 0 || $origins === [])
		{
			return [];
		}

		$rows = AdsFormLinkTable::query()
			->setSelect([
				'ADS_TYPE',
				'ADS_ACCOUNT_ID',
				'ADS_FORM_ID',
				'CRM_FIELD_KEY' => self::FIELD_MAPPING_REFERENCE . '.CRM_FIELD_KEY',
				'ADS_FIELD_KEY' => self::FIELD_MAPPING_REFERENCE . '.ADS_FIELD_KEY',
				'ITEMS' => self::FIELD_MAPPING_REFERENCE . '.ITEMS',
			])
			->registerRuntimeField(
				new Reference(
					self::FIELD_MAPPING_REFERENCE,
					FormFieldMappingTable::class,
					Join::on('this.ID', 'ref.FORM_LINK_ID'),
					['join_type' => Join::TYPE_INNER],
				),
			)
			->where('WEBFORM_ID', $formId)
			// The mechanism builds its own map of stored links out of the importing ones alone, so a
			// link of the export direction is not the link a repeat of the input stands for, however
			// equal the three values naming it.
			->where('LINK_DIRECTION', AdsFormLinkTable::LINK_DIRECTION_IMPORT)
			->fetchAll()
		;

		$mappings = [];
		foreach ($rows as $row)
		{
			$origin = self::origin(
				(string)$row['ADS_TYPE'],
				(string)$row['ADS_ACCOUNT_ID'],
				(string)$row['ADS_FORM_ID'],
			);
			if (!isset($origins[$origin]))
			{
				continue;
			}

			$mappings[$origin][] = [
				'CRM_FIELD_KEY' => $row['CRM_FIELD_KEY'],
				'ADS_FIELD_KEY' => $row['ADS_FIELD_KEY'],
				'ITEMS' => $row['ITEMS'],
			];
		}

		return $mappings;
	}

	private static function originOf(AdIntegration $ad): string
	{
		return self::origin((string)$ad->providerCode, (string)$ad->accountId, (string)$ad->formId);
	}

	private static function origin(string $providerCode, string $accountId, string $adFormId): string
	{
		return $providerCode . '/' . $accountId . '/' . $adFormId;
	}

	/**
	 * The mappings of a link as the mechanism takes them, with the service data of a mapping the form
	 * already holds carried over.
	 *
	 * The values an ad field is translated by live in that service data, which this contract does not
	 * publish: rewritten without it, a mapping the input names again would lose the translation of
	 * its values. This is the rule the fields of the form follow as well - what the contract does not
	 * carry survives a write of what it does.
	 *
	 * @param AdFieldMapping[] $fieldMappings
	 * @param array<array<string, mixed>> $storedMappings
	 * @return array<array<string, mixed>>
	 */
	private static function createFieldMappings(array $fieldMappings, array $storedMappings = []): array
	{
		$storedItems = [];
		foreach ($storedMappings as $storedMapping)
		{
			$storedItems[(string)$storedMapping['CRM_FIELD_KEY']] ??= $storedMapping['ITEMS'] ?? null;
		}

		$rows = [];
		foreach ($fieldMappings as $fieldMapping)
		{
			$crmFieldName = (string)$fieldMapping->crmFieldName;
			$rows[] = [
				'CRM_FIELD_KEY' => $fieldMapping->crmFieldName,
				'ADS_FIELD_KEY' => $fieldMapping->adsFieldName,
				'ITEMS' => $storedItems[$crmFieldName] ?? [],
			];
		}

		return $rows;
	}
}
