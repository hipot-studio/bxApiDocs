<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Script\Action;

use Bitrix\Landing\Manager;
use Bitrix\Landing\Site\Type;
use Bitrix\Landing\Transfer\Requisite\Dictionary\AdditionalOptionPart;
use Bitrix\Landing\Transfer\Requisite\Dictionary\RatioPart;
use Bitrix\Landing\Transfer\Requisite\Dictionary\RunDataPart;
use Bitrix\Landing\Transfer\TransferException;
use Bitrix\Rest\AppTable;

class PreparePageData extends Blank
{
	public function action(): void
	{
		$data = $this->context->getData();
		if (!isset($data))
		{
			throw new TransferException('DATA not found');
		}

		$id = (int)($data['ID'] ?? null);
		if ($id <= 0)
		{
			throw new TransferException('ID must not be empty');
		}
		$this->context->getRunData()->set(RunDataPart::OldId, $id);

		$notAllowedKeys = [
			'ID', 'VIEWS', 'DATE_CREATE', 'DATE_MODIFY',
			'DATE_PUBLIC', 'CREATED_BY_ID', 'MODIFIED_BY_ID',
		];
		foreach ($notAllowedKeys as $key)
		{
			if (isset($data[$key]))
			{
				unset($data[$key]);
			}
		}

		$siteId = $this->context->getSiteId();
		if ($siteId > 0)
		{
			$data['SITE_ID'] = $siteId;
		}

		// set external partners info
		$appCode = $this->context->getAppCode();
		if ($appCode)
		{
			$this->context->getRunData()->set(RunDataPart::PreviousTplCode, $data['TPL_CODE']);
			$data['XML_ID'] = $data['TITLE'] . '|' . $appCode;
			$data['TPL_CODE'] = $appCode;
		}

		// todo: this section for all?
		$data['ACTIVE'] = 'N';
		$data['PUBLIC'] = 'N';
		$data['FOLDER_SKIP_CHECK'] = 'Y';
		$data['INITIATOR_APP_CODE'] = $appCode;
		// unset($data['CODE']);

		$this->context->setData($data);
	}

}
