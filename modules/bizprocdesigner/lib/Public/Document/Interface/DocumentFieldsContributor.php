<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Public\Document\Interface;

/**
 * Implemented by a document module that adds its own fields to the ones the designer knows about
 * {@see \Bitrix\BizprocDesigner\Public\Document\DocumentFieldsResolver}.
 * The contribution is declared in the .settings.php of that module:
 *
 *   'bizprocdesigner.documentfields' => [
 *       'value' => ['className' => <implementation>::class],
 *       'readonly' => true,
 *   ],
 */
interface DocumentFieldsContributor
{
	/**
	 * @param array $documentType Complex document type, e.g. ['crm', 'CCrmDocumentDeal', 'DEAL'].
	 *
	 * @return array Field name => field description in the format of
	 *               {@see \CBPDocumentService::getDocumentFields()}
	 *               (Name/Type/Options/Settings/Editable/Required/Multiple). An empty array means no
	 *               contribution. Only fields absent from that base set belong here: a duplicate is
	 *               dropped by the selector in favour of the base field.
	 */
	public function getDocumentFields(array $documentType): array;
}
