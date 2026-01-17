<?php

namespace Bitrix\Sign\Item\Document\Config;

use Bitrix\Sign\Contract;

class DocumentFillConfig implements Contract\Item
{
	public function __construct(
		/** @var DocumentSourceFile[] $sourceFiles */
		public array                     $sourceFiles,
		public int                       $crmCompanyId,
		/** @var DocumentMemberConfig $signersList */
		public array                     $signersList,
		public int                       $assigneeEntityId,
		public DocumentMemberConfig      $representativeUser,
		public string                    $companyProviderUid,
		public ?string                   $regionDocumentType,
		public ?int                      $hcmLinkCompanyId,
		public ?DocumentMemberConfig     $reviewerUser,
		public ?DocumentMemberConfig     $editorUser,
		public ?DocumentMemberConfig     $responsibleUser,
		public ?DocumentExternalSettings $externalSettings,
		public ?string                   $language,
	)
	{
	}
}
