<?php
/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage crm
 * @copyright 2001-2026 Bitrix
 */

namespace Bitrix\Crm\WebForm\Agreement;

use Bitrix\Crm\WebForm\Embed\Config;
use Bitrix\Crm\WebForm\Form;
use Bitrix\Main;
use Bitrix\Main\UserConsent\Agreement;
use Bitrix\Main\UserConsent\Internals\AgreementTable;

/**
 * Acceptance policy of the legal documents of a published form.
 *
 * The set of eligible documents is derived from the saved form policy. An outdated browser bundle
 * cannot add a document to that set, drop one from it, or skip a required one.
 */
final class SubmissionPolicy
{
	public const ERROR_AGREEMENT_REQUIRED = 'CRM_WEBFORM_AGREEMENT_REQUIRED';

	private const CONSENT_FIELD_PREFIX = 'AGREEMENT_';
	private const CONSENT_ACCEPTED_VALUE = 'Y';

	/**
	 * Available documents of the form in their saved order.
	 *
	 * @var array<int, array{required: bool, acceptBySubmit: bool, revision?: string}>
	 */
	private array $documents;

	/**
	 * @param array<int, array{required: bool, acceptBySubmit: bool, revision?: string}> $documents
	 */
	private function __construct(array $documents)
	{
		$this->documents = $documents;
	}

	public static function createFromForm(Form $form): self
	{
		$data = $form->get();
		if (($data['USE_LICENCE'] ?? '') !== 'Y')
		{
			return new self([]);
		}

		$documents = self::readDocuments($data['AGREEMENTS'] ?? []);
		$submitDocuments = array_filter($documents, static fn(array $document): bool => $document['acceptBySubmit']);
		if (Config::isAcceptBySubmitEnabled() && $submitDocuments)
		{
			foreach ((new Config($form))->getAgreements(array_keys($submitDocuments)) as $agreement)
			{
				$agreementId = (int)substr($agreement['name'], strlen(self::CONSENT_FIELD_PREFIX));
				if (isset($documents[$agreementId]) && $documents[$agreementId]['acceptBySubmit'])
				{
					$documents[$agreementId]['revision'] = $agreement['revision'];
				}
			}
		}

		return new self($documents);
	}

	/**
	 * Resolves the acceptance set of a single submission against the saved policy.
	 *
	 * The traversal goes over the saved documents and looks the payload up by the exact public
	 * field name, so an identifier of another form and a repeated identifier never reach the set.
	 *
	 * @param array $consents Submitted consents: public field name => value.
	 * @param array $explicitConsents Checkbox selections reported by a client that supports the flag fallback.
	 * @param array $displayedSubmitAgreements Revisions displayed as accepted by submitting the form.
	 * @return Main\Result Data key `acceptedAgreementIds` holds the accepted identifiers.
	 */
	public function resolveAcceptance(
		array $consents,
		array $explicitConsents = [],
		array $displayedSubmitAgreements = [],
	): Main\Result
	{
		$result = new Main\Result();
		$acceptedAgreementIds = [];
		$acceptBySubmitEnabled = Config::isAcceptBySubmitEnabled();

		foreach ($this->documents as $agreementId => $document)
		{
			$acceptBySubmit = $document['acceptBySubmit'] && $acceptBySubmitEnabled;
			if ($acceptBySubmit)
			{
				$displayedRevision = $displayedSubmitAgreements[self::CONSENT_FIELD_PREFIX . $agreementId] ?? null;
				$isDisplayed = is_string($displayedRevision)
					&& isset($document['revision'])
					&& hash_equals($document['revision'], $displayedRevision);
				$isExplicitlySelected = self::isSelected($consents, $agreementId)
					&& self::isSelected($explicitConsents, $agreementId);
				if (!$isDisplayed && !$isExplicitlySelected)
				{
					return $result->addError(new Main\Error(
						"Agreement with id=`{$agreementId}` was not displayed.",
						self::ERROR_AGREEMENT_REQUIRED,
					));
				}

				$acceptedAgreementIds[] = $agreementId;

				continue;
			}

			$isSelected = self::isSelected($consents, $agreementId);
			$requiresExplicitConsent = $document['acceptBySubmit'] && !$acceptBySubmitEnabled;
			$isExplicitlySelected = !$requiresExplicitConsent
				|| self::isSelected($explicitConsents, $agreementId);
			if ($document['required'] && (!$isSelected || !$isExplicitlySelected))
			{
				return $result->addError(new Main\Error(
					"Agreement with id=`{$agreementId}` is required.",
					self::ERROR_AGREEMENT_REQUIRED,
				));
			}

			if ($isSelected && $isExplicitlySelected)
			{
				$acceptedAgreementIds[] = $agreementId;
			}
		}

		return $result->setData(['acceptedAgreementIds' => $acceptedAgreementIds]);
	}

	/**
	 * @param array $relations Saved form to document relations.
	 * @return array<int, array{required: bool, acceptBySubmit: bool}>
	 */
	private static function readDocuments(array $relations): array
	{
		$saved = [];
		foreach ($relations as $relation)
		{
			$agreementId = (int)($relation['AGREEMENT_ID'] ?? 0);
			if ($agreementId <= 0)
			{
				continue;
			}

			$saved[$agreementId] = [
				'required' => ($relation['REQUIRED'] ?? 'N') === 'Y',
				'acceptBySubmit' => ($relation['ACCEPT_BY_SUBMIT'] ?? 'N') === 'Y',
			];
		}

		if (!$saved)
		{
			return [];
		}

		$availableDocuments = self::readAvailableDocuments(array_keys($saved));

		$documents = [];
		foreach ($saved as $agreementId => $relation)
		{
			if (!isset($availableDocuments[$agreementId]))
			{
				continue;
			}

			$documents[$agreementId] = [
				'required' => $relation['required'] || $relation['acceptBySubmit'],
				'acceptBySubmit' => $relation['acceptBySubmit'],
			];
		}

		return $documents;
	}

	/**
	 * Reads every linked document at once: a missing or inactive one is simply absent from the
	 * answer and therefore cannot be accepted.
	 *
	 * @param int[] $agreementIds
	 * @return array<int, bool> Available agreement identifiers.
	 */
	private static function readAvailableDocuments(array $agreementIds): array
	{
		$rows = AgreementTable::getList([
			'select' => ['ID'],
			'filter' => [
				'@ID' => $agreementIds,
				'=ACTIVE' => Agreement::ACTIVE,
			],
		])->fetchAll();

		$availableDocuments = [];
		foreach ($rows as $row)
		{
			$availableDocuments[(int)$row['ID']] = true;
		}

		return $availableDocuments;
	}

	private static function isSelected(array $consents, int $agreementId): bool
	{
		$value = $consents[self::CONSENT_FIELD_PREFIX . $agreementId] ?? null;

		return is_scalar($value) && (string)$value === self::CONSENT_ACCEPTED_VALUE;
	}
}
