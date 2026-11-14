<?php

namespace Bitrix\Mobile\Internal\Onboarding;

/**
 * Industry of the portal, the second personalization axis of the push text
 * (see INDUSTRY-MAP). Case values are the wire values of the qualification
 * wizard field cjm-business and double as the industry keys of the campaign
 * document `content` section.
 *
 * Every wizard code is a first-class case; a null or unknown code degrades to
 * OTHER. Industries the text matrix does not cover fall back to the `_default`
 * content bucket, so the industry key stays complete and the JSON can be filled
 * in per industry independently.
 *
 * Some cases are regional wordings of one industry - GOVERNMENT is what the wizard
 * offers in BY, GOVERNMENT_MUNICIPAL what it offers in RU and the rest of the CIS, and a
 * portal ever sends one of them. Which of them share a text is data, not code: the
 * campaign document maps them onto one bucket through `industryAliases`.
 */
enum Industry: string
{
	case REAL_ESTATE = 'real-estate';
	case MANUFACTURING = 'manufacturing';
	case HEALTHCARE = 'healthcare';
	case LEGAL = 'legal';
	case SERVICES = 'services';
	case RETAIL = 'retail';
	case WHOLESALE = 'wholesale';
	case TRANSPORTATION = 'transportation-and-storage';
	case DIGITAL_MARKETING = 'digital-marketing-or-communications';
	case EDUCATION = 'education';
	case IT = 'it';
	case FINANCIAL = 'financial';
	case HORECA = 'horeca';
	case TRAVEL = 'travel';
	case GOVERNMENT_MUNICIPAL = 'government-or-municipal-organization';
	case GOVERNMENT = 'government-organization';
	case OTHER = 'other-business';

	public static function fromCode(?string $code): self
	{
		return ($code === null) ? self::OTHER : (self::tryFrom($code) ?? self::OTHER);
	}
}
