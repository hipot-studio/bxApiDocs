<?php

namespace Bitrix\UI\Form;

/**
 * The FormProvider class provides a list of partner feedback forms with region zones, form IDs, languages, and security keys.
 *
 * For displaying partner feedback forms on the client side, use the PartnerForm class (see ui/partnerform/src/PartnerForm.js).
 *
 * Each form is associated with one or more zones (regions), a language code, and a unique security key.
 * The list is intended for localized feedback widgets or CRM forms.
 *
 * @package Bitrix\UI\Form
 */
class FormProvider
{
	/**
	 * Returns a list of feedback forms with their respective region zones, form IDs, languages, and security keys.
	 *
	 * Each form is associated with one or more zones (regions), a language code, and a unique security key.
	 * These forms are typically used to display localized feedback widgets or CRM forms.
	 * @return array[]
	 */
	public function getPartnerFormList(): array
	{
		$westZones = \Bitrix\UI\Form\FeedbackForm::getWestZones();

		return [
			['zones' => $westZones, 'id' => 34, 'lang' => 'en', 'sec' => '940xiq'],
			['zones' => $westZones, 'id' => 58, 'lang' => 'br', 'sec' => 'db0u10'],
			['zones' => $westZones, 'id' => 82, 'lang' => 'la', 'sec' => 'f5cg69'],
			['zones' => $westZones, 'id' => 84, 'lang' => 'pl', 'sec' => 'xaq93h'],
			['zones' => $westZones, 'id' => 86, 'lang' => 'tr', 'sec' => '6xbruy'],
			['zones' => $westZones, 'id' => 88, 'lang' => 'vn', 'sec' => '8g591b'],
			['zones' => $westZones, 'id' => 90, 'lang' => 'it', 'sec' => 'mc95rg'],
			['zones' => $westZones, 'id' => 92, 'lang' => 'de', 'sec' => 'uokzr7'],
			['zones' => $westZones, 'id' => 94, 'lang' => 'fr', 'sec' => 'gyzkzb'],
			['zones' => $westZones, 'id' => 96, 'lang' => 'ms', 'sec' => 'zaetk0'],
			['zones' => $westZones, 'id' => 108, 'lang' => 'id', 'sec' => '3gs5vj'],
			['zones' => $westZones, 'id' => 98, 'lang' => 'sc', 'sec' => '532jfn'],
			['zones' => $westZones, 'id' => 100, 'lang' => 'th', 'sec' => '1q4cis'],
			['zones' => $westZones, 'id' => 102, 'lang' => 'ja', 'sec' => 'c84t56'],
			['zones' => $westZones, 'id' => 104, 'lang' => 'tc', 'sec' => '6s7a1m'],
			['zones' => $westZones, 'id' => 110, 'lang' => 'ar', 'sec' => 'zfkgno'],
			['zones' => $westZones, 'id' => 106, 'lang' => 'ru', 'sec' => 'j1h7w4'],
			['zones' => $westZones, 'id' => 34, 'lang' => 'ua', 'sec' => '940xiq'],
			['zones' => $westZones, 'id' => 34, 'lang' => 'kz', 'sec' => '940xiq'],
			['zones' => ['ru'], 'id' => 3282, 'lang' => 'ru', 'sec' => '8x81lp'],
			['zones' => ['by'], 'id' => 3, 'lang' => 'ru', 'sec' => 'kde6g5'],
			['zones' => ['kz'], 'id' => 4, 'lang' => 'kz', 'sec' => 'id3qfm'],
			['zones' => ['kz'], 'id' => 2, 'lang' => 'ru', 'sec' => 'po3skw'],
			['zones' => ['kz'], 'id' => 6, 'lang' => 'en', 'sec' => 'gozt5o'],
			['zones' => ['uz'], 'id' => 7, 'lang' => 'ru', 'sec' => 'xjkuqu'],
		];
	}

	public function getPartnerFeedbackFormList(): array
	{
		$westZones = \Bitrix\UI\Form\FeedbackForm::getWestZones();

		return [
			['zones' => $westZones, 'id' => 588, 'lang' => 'en', 'sec' => '5yxb4v'],
			['zones' => $westZones, 'id' => 590, 'lang' => 'br', 'sec' => 'd926w3'],
			['zones' => $westZones, 'id' => 592, 'lang' => 'la', 'sec' => 'u8hl3w'],
			['zones' => $westZones, 'id' => 594, 'lang' => 'de', 'sec' => 'ytqxzu'],
			['zones' => $westZones, 'id' => 596, 'lang' => 'pl', 'sec' => 't49oo9'],
			['zones' => $westZones, 'id' => 598, 'lang' => 'tr', 'sec' => '03a1oo'],
			['zones' => $westZones, 'id' => 600, 'lang' => 'vn', 'sec' => 'aa58o7'],
			['zones' => $westZones, 'id' => 602, 'lang' => 'it', 'sec' => 'ahmuhf'],
			['zones' => $westZones, 'id' => 604, 'lang' => 'fr', 'sec' => 'v133xm'],
			['zones' => $westZones, 'id' => 606, 'lang' => 'ms', 'sec' => '852wkj'],
			['zones' => $westZones, 'id' => 608, 'lang' => 'id', 'sec' => 'afmab1'],
			['zones' => $westZones, 'id' => 610, 'lang' => 'tc', 'sec' => 'fux6b2'],
			['zones' => $westZones, 'id' => 612, 'lang' => 'sc', 'sec' => 't0dlen'],
			['zones' => $westZones, 'id' => 614, 'lang' => 'th', 'sec' => 'k7by62'],
			['zones' => $westZones, 'id' => 616, 'lang' => 'ja', 'sec' => '3dlvmd'],
			['zones' => $westZones, 'id' => 618, 'lang' => 'ar', 'sec' => 'tszo3e'],
			['zones' => $westZones, 'id' => 620, 'lang' => 'ru', 'sec' => 'd7uqas'],
			['zones' => ['ru'], 'id' => 3594, 'lang' => 'ru', 'sec' => '3xe3ye'],
			['zones' => ['by'], 'id' => 85, 'lang' => 'ru', 'sec' => 'ygosc1'],
			['zones' => ['kz'], 'id' => 104, 'lang' => 'ru', 'sec' => '3k8fn5'],
			['zones' => ['uz'], 'id' => 39, 'lang' => 'ru', 'sec' => 'uxsiwm'],
		];
	}

	public function getPartnerRefusalFormList(): array
	{
		$westZones = \Bitrix\UI\Form\FeedbackForm::getWestZones();

		return [
			['zones' => $westZones, 'id' => 542, 'lang' => 'en', 'sec' => 'n6qbr1'],
			['zones' => $westZones, 'id' => 544, 'lang' => 'br', 'sec' => '8bvgmo'],
			['zones' => $westZones, 'id' => 546, 'lang' => 'la', 'sec' => 'fgemml'],
			['zones' => $westZones, 'id' => 548, 'lang' => 'de', 'sec' => 'vfe8vl'],
			['zones' => $westZones, 'id' => 550, 'lang' => 'pl', 'sec' => 'dqtss4'],
			['zones' => $westZones, 'id' => 552, 'lang' => 'tr', 'sec' => 'cbt3z6'],
			['zones' => $westZones, 'id' => 554, 'lang' => 'vn', 'sec' => 'rtba1e'],
			['zones' => $westZones, 'id' => 556, 'lang' => 'it', 'sec' => 't14rtt'],
			['zones' => $westZones, 'id' => 558, 'lang' => 'fr', 'sec' => '0ta2it'],
			['zones' => $westZones, 'id' => 560, 'lang' => 'ms', 'sec' => 'igpafm'],
			['zones' => $westZones, 'id' => 562, 'lang' => 'id', 'sec' => '4cyuen'],
			['zones' => $westZones, 'id' => 564, 'lang' => 'tc', 'sec' => '7viz4q'],
			['zones' => $westZones, 'id' => 566, 'lang' => 'sc', 'sec' => '1qblkh'],
			['zones' => $westZones, 'id' => 568, 'lang' => 'th', 'sec' => 'l2g19u'],
			['zones' => $westZones, 'id' => 570, 'lang' => 'ja', 'sec' => 'o47o3f'],
			['zones' => $westZones, 'id' => 572, 'lang' => 'ar', 'sec' => 'pfhgqj'],
			['zones' => $westZones, 'id' => 574, 'lang' => 'ru', 'sec' => 'i932o7'],
			['zones' => ['ru'], 'id' => 3593, 'lang' => 'ru', 'sec' => 'amp2bn'],
			['zones' => ['by'], 'id' => 83, 'lang' => 'ru', 'sec' => '2s1mg3'],
			['zones' => ['kz'], 'id' => 102, 'lang' => 'ru', 'sec' => 'rsa332'],
			['zones' => ['uz'], 'id' => 37, 'lang' => 'ru', 'sec' => 'x34spz'],
		];
	}

	public function getPartnerRefusalCheckoutFormList(): array
	{
		return [
			['zones' => ['ru'], 'id' => 3613, 'lang' => 'ru', 'sec' => 'shrvf7'],
		];
	}

	public function getPartnerRefusalCheckoutNonAdminFormList(): array
	{
		return [
			['zones' => ['ru'], 'id' => 3694, 'lang' => 'ru', 'sec' => 'ge9v9b'],
		];
	}
}
