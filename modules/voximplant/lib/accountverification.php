<?php
namespace Bitrix\Voximplant;

use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/**
 * Class AccountVerification
 * Handles account verification status and INTERNOD agreement checks
 * @package Bitrix\Voximplant
 */
class AccountVerification
{
	private $verifications = null;
	private $error = null;

	/**
	 * Check if INTERNOD warning should be displayed
	 * Warning shows when:
	 * 1. AGREEMENTS field exists in verification
	 * 2. INTERNOD agreement exists
	 * 3. INTERNOD status === 'NEW' or 'REJECTED'
	 *
	 * @return bool True if warning should be shown
	 */
	public function hasInternodWarning()
	{
		$verifications = $this->getAccountVerifications();
		if (!$verifications || !is_array($verifications))
		{
			return false;
		}

		foreach ($verifications as $key => $verification)
		{
			if (!isset($verification['AGREEMENTS']) || !is_array($verification['AGREEMENTS']))
			{
				continue;
			}

			foreach ($verification['AGREEMENTS'] as $agreementsKey => $agreement)
			{
				if (
					isset($agreement['TYPE']) && $agreement['TYPE'] === 'INTERNOD'
					&& isset($agreement['STATUS']) && in_array($agreement['STATUS'], ['NEW', 'REJECTED'])
				)
				{
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Get account verifications from CVoxImplantDocuments
	 * @return array|null Parsed verifications array or null on error
	 */
	private function getAccountVerifications()
	{
		if ($this->verifications !== null)
		{
			return $this->verifications;
		}

		$documents = new \CVoxImplantDocuments();
		$this->verifications = $documents->GetStatus();

		if ($this->verifications === false)
		{
			$this->error = $documents->GetError();
			return null;
		}

		return $this->verifications;
	}

	/**
	 * Get verification deadline date
	 * Returns first found VERIFICATION_DEADLINE
	 *
	 * @return string|null Date string or null if not found
	 */
	public function getInternodDeadline(): ?string
	{
		$verifications = $this->getAccountVerifications();
		foreach ($verifications as $verification)
		{
			if (!empty($verification['VERIFICATION_DEADLINE']))
			{
				$date = new \Bitrix\Main\Type\Date($verification['VERIFICATION_DEADLINE'], 'Y-m-d');
				return $date->format(\Bitrix\Main\Type\Date::getFormat());
			}
		}
		return null;
	}

	/**
	 * Get verification source type
	 * Returns 'VERIFICATIONS' or 'DOCUMENTS' based on what controller returned
	 *
	 * @return string|null Source type or null if not found
	 */
	public function getVerificationSource(): ?string
	{
		$verifications = $this->getAccountVerifications();
		if (!$verifications || !is_array($verifications))
		{
			return null;
		}

		foreach ($verifications as $verification)
		{
			if (!empty($verification['VERIFICATION_SOURCE']))
			{
				return $verification['VERIFICATION_SOURCE'];
			}
		}

		return null;
	}

	/**
	 * Get last error
	 * @return \CVoxImplantError|null
	 */
	public function getError()
	{
		return $this->error;
	}
}
