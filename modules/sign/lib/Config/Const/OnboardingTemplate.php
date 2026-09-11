<?php


namespace Bitrix\Sign\Config\Const;

abstract class OnboardingTemplate
{
	// The preset must stay initiatedByType 'company' with no regional blocks: the onboarding UI never renders
	// registration settings fields, so an employee preset with a regional placeholder would hit the required-field
	// check in Operation\Document\Template\Send and leave the user unable to fill them.
	public const SHA256 = '256233794c40f7d779430061011d35f499f3321781d1f67425235bff8829f40a';
	public const FILE_NAME = 'onboarding_template.json';
}