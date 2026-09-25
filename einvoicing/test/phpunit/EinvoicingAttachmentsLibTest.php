<?php
/* Copyright (C) 2026 Pierre Grasswill
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 * or see https://www.gnu.org/
 */

/**
 *      \file       test/phpunit/EinvoicingAttachmentsLibTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for einvoicing/lib/einvoicing_attachments.lib.php: the BT-123 codes, the
 *                  mime code of an attached file (BT-125-1) and the mark kept in llx_ecm_files.
 *      \remarks    To run this script as CLI: phpunit filename.php
 */

global $conf, $user, $langs, $db;

$dolibarrHtdocs = getenv('DOLIBARR_HTDOCS');
if (!$dolibarrHtdocs) {
	$dolibarrHtdocs = dirname(__FILE__) . '/../../htdocs';
}
if (!file_exists($dolibarrHtdocs . '/master.inc.php')) {
	throw new \RuntimeException('Could not locate master.inc.php under "' . $dolibarrHtdocs . '/". Set the environment variable (export DOLIBARR_HTDOCS=...) to the htdocs directory of the Dolibarr instance to test against.');
}

require_once $dolibarrHtdocs . '/master.inc.php';
require_once DOL_DOCUMENT_ROOT . '/ecm/class/ecmfiles.class.php';
dol_include_once('einvoicing/lib/einvoicing_attachments.lib.php');
require_once __DIR__ . '/CommonClassTestCompat.inc.php';

/**
 * Class for PHPUnit tests
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class EinvoicingAttachmentsLibTest extends CommonClassTest
{
	/**
	 * The list is the one of BR-FR-17 in the CTC-FR schematron (FNFE V1.4.0), nothing more.
	 *
	 * @return void
	 */
	public function testCodesAreTheOnesOfBrFr17()
	{
		$this->assertSame(
			array('RIB', 'LISIBLE', 'FEUILLE_DE_STYLE', 'PJA', 'BORDEREAU_SUIVI', 'DOCUMENT_ANNEXE', 'BON_LIVRAISON', 'BON_COMMANDE', 'BORDEREAU_SUIVI_VALIDATION', 'ETAT_ACOMPTE', 'FACTURE_PAIEMENT_DIRECT', 'RECAPITULATIF_COTRAITANCE'),
			einvoicingAttachmentCodes()
		);
	}

	/**
	 * Only the mime codes EN 16931 allows are known, whatever the case of the extension.
	 *
	 * @return void
	 */
	public function testMimeCodeIsReadFromTheExtension()
	{
		$this->assertSame('application/pdf', einvoicingAttachmentMimeCode('Delivery proof.PDF'));
		$this->assertSame('image/jpeg', einvoicingAttachmentMimeCode('photo.jpeg'));
		$this->assertSame('image/jpeg', einvoicingAttachmentMimeCode('photo.jpg'));
		$this->assertSame('image/png', einvoicingAttachmentMimeCode('a.png'));
		$this->assertSame('text/csv', einvoicingAttachmentMimeCode('a.csv'));
		$this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', einvoicingAttachmentMimeCode('a.xlsx'));
		$this->assertSame('application/vnd.oasis.opendocument.spreadsheet', einvoicingAttachmentMimeCode('a.ods'));
		$this->assertSame('', einvoicingAttachmentMimeCode('script.sh'));
		$this->assertSame('', einvoicingAttachmentMimeCode('archive.zip'));
		$this->assertSame('', einvoicingAttachmentMimeCode('noextension'));
	}

	/**
	 * The path is written the way the core indexes an upload: relative to DOL_DATA_ROOT, no slash around.
	 *
	 * @return void
	 */
	public function testIndexPathIsTheOneOfTheCore()
	{
		$this->assertSame('facture/FA2609-0001', einvoicingAttachmentIndexPath(DOL_DATA_ROOT . '/facture/FA2609-0001/'));
		$this->assertSame('2/facture/(PROV12)', einvoicingAttachmentIndexPath(DOL_DATA_ROOT . '/2/facture/(PROV12)'));
	}

	/**
	 * The mark keeps the other keys of extraparams, refuses a code BR-FR-17 does not know, and an empty
	 * code is a file sent without BT-123.
	 *
	 * @return void
	 */
	public function testTheMarkKeepsTheOtherParameters()
	{
		global $db;

		$db->begin();
		$sql = "INSERT INTO " . MAIN_DB_PREFIX . "ecm_files (ref, label, entity, filepath, filename, extraparams, date_c, fk_user_c) VALUES (";
		$sql .= "'" . md5((string) mt_rand()) . "', 'test', 1, 'facture/TEST1094', 'a.pdf', '{\"other\":\"kept\"}', '" . $db->idate(dol_now()) . "', 1)";
		$db->query($sql);
		$rowid = (int) $db->last_insert_id(MAIN_DB_PREFIX . 'ecm_files');

		$this->assertSame(1, einvoicingSetAttachmentCode($db, $rowid, 'BON_LIVRAISON'));
		$this->assertSame(-2, einvoicingSetAttachmentCode($db, $rowid, 'FREE TEXT'));
		$obj = $db->fetch_object($db->query("SELECT extraparams FROM " . MAIN_DB_PREFIX . "ecm_files WHERE rowid = " . $rowid));
		$this->assertSame(array('other' => 'kept', 'einvoicing_bg24' => 'BON_LIVRAISON'), json_decode($obj->extraparams, true));

		$this->assertSame(1, einvoicingSetAttachmentCode($db, $rowid, ''));
		$obj = $db->fetch_object($db->query("SELECT extraparams FROM " . MAIN_DB_PREFIX . "ecm_files WHERE rowid = " . $rowid));
		$this->assertSame('', json_decode($obj->extraparams, true)['einvoicing_bg24']);

		$db->rollback();
	}
}
