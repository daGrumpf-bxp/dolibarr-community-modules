<?php
/* Copyright (C) 2026		Pierre Grasswill			<da.grumpf@gmail.com>
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
 */

/**
 * \file    einvoicing/lib/einvoicing_attachments.lib.php
 * \ingroup einvoicing
 * \brief   Files of a customer invoice embedded in its e-invoice as additional supporting documents (BG-24).
 *
 * A file joins the e-invoice when it is uploaded from the tab of the module, not from the Documents tab:
 * the core indexes it in llx_ecm_files, and the module marks that row in its extraparams column, a JSON
 * field the core keeps for "other parameters" and never writes itself. The mark also holds the BT-123
 * code chosen for the file, empty when the file goes out without one.
 */

dol_include_once('/einvoicing/class/protocols/CIIProtocol.class.php');

/** Key of the mark in llx_ecm_files.extraparams */
const EINVOICING_ATTACHMENT_EXTRAPARAM = 'einvoicing_bg24';


/**
 * Codes BR-FR-17 accepts in BT-123, the description of an additional supporting document.
 * Any other value is rejected as fatal by the CTC-FR schematron, so the file goes out without BT-123
 * rather than with a free text.
 *
 * @return string[]
 */
function einvoicingAttachmentCodes()
{
	return array(
		'RIB', 'LISIBLE', 'FEUILLE_DE_STYLE', 'PJA', 'BORDEREAU_SUIVI', 'DOCUMENT_ANNEXE', 'BON_LIVRAISON',
		'BON_COMMANDE', 'BORDEREAU_SUIVI_VALIDATION', 'ETAT_ACOMPTE', 'FACTURE_PAIEMENT_DIRECT',
		'RECAPITULATIF_COTRAITANCE',
	);
}

/**
 * Mime code (BT-125-1) of an attached file, read from its extension.
 * Only the mime codes EN 16931 allows are known, the same list the reception side extracts.
 *
 * @param	string		$filename	File name
 * @return	string					Mime code, '' when the file cannot be embedded
 */
function einvoicingAttachmentMimeCode($filename)
{
	$extension = dol_strtolower(pathinfo((string) $filename, PATHINFO_EXTENSION));
	if ($extension === 'jpeg') {
		$extension = 'jpg';
	}
	$mimecode = array_search($extension, CIIProtocol::ATTACHMENT_MIME_EXTENSIONS, true);

	return $mimecode === false ? '' : (string) $mimecode;
}

/**
 * Directory of an invoice, as the core writes it in llx_ecm_files.filepath (relative to DOL_DATA_ROOT).
 *
 * @param	string		$dir		Absolute directory of the invoice
 * @return	string
 */
function einvoicingAttachmentIndexPath($dir)
{
	$reldir = preg_replace('/^'.preg_quote(DOL_DATA_ROOT, '/').'/', '', (string) $dir);

	return trim((string) $reldir, '/\\');
}

/**
 * Files of an invoice marked to join its e-invoice, the ones still on disk only.
 *
 * @param	DoliDB		$db			Database handler
 * @param	CommonInvoice	$invoice	Customer invoice
 * @return	array<int,array{rowid:int,filename:string,fullname:string,code:string,size:int,date:int}>	Keyed by llx_ecm_files rowid
 */
function einvoicingFetchAttachedFiles($db, $invoice)
{
	$dir = getMultidirOutputCompat($invoice, '', 1);
	$files = array();
	if (empty($dir) || empty($invoice->id)) {
		return $files;
	}

	$sql = "SELECT rowid, filename, extraparams FROM ".MAIN_DB_PREFIX."ecm_files";
	$sql .= " WHERE filepath = '".$db->escape(einvoicingAttachmentIndexPath($dir))."'";
	$sql .= " AND entity = ".((int) $invoice->entity);
	$sql .= " AND extraparams LIKE '%".$db->escape(EINVOICING_ATTACHMENT_EXTRAPARAM)."%'";
	$sql .= " ORDER BY rowid";
	$resql = $db->query($sql);
	if (!$resql) {
		dol_syslog(__FUNCTION__.' '.$db->lasterror(), LOG_ERR);
		return $files;
	}
	while ($obj = $db->fetch_object($resql)) {
		$params = json_decode((string) $obj->extraparams, true);
		$fullname = $dir.'/'.$obj->filename;
		if (!is_array($params) || !array_key_exists(EINVOICING_ATTACHMENT_EXTRAPARAM, $params) || !is_file($fullname)) {
			continue;
		}
		$files[(int) $obj->rowid] = array(
			'rowid' => (int) $obj->rowid,
			'filename' => (string) $obj->filename,
			'fullname' => $fullname,
			'code' => (string) $params[EINVOICING_ATTACHMENT_EXTRAPARAM],
			'size' => (int) filesize($fullname),
			'date' => (int) filemtime($fullname),
		);
	}
	$db->free($resql);

	return $files;
}

/**
 * Mark a file indexed in llx_ecm_files to join the e-invoice, with its BT-123 code.
 * The other keys of extraparams are kept: the column belongs to the core, not to this module.
 *
 * @param	DoliDB		$db			Database handler
 * @param	int			$rowid		Row of llx_ecm_files
 * @param	string		$code		BT-123 code, '' for none
 * @return	int						1 if OK, <0 if KO
 */
function einvoicingSetAttachmentCode($db, $rowid, $code)
{
	if ($code !== '' && !in_array($code, einvoicingAttachmentCodes(), true)) {
		return -2;
	}

	$resql = $db->query("SELECT extraparams FROM ".MAIN_DB_PREFIX."ecm_files WHERE rowid = ".((int) $rowid));
	$obj = $resql ? $db->fetch_object($resql) : null;
	if (!$obj) {
		return -1;
	}
	$params = json_decode((string) $obj->extraparams, true);
	if (!is_array($params)) {
		$params = array();
	}
	$params[EINVOICING_ATTACHMENT_EXTRAPARAM] = $code;

	$sql = "UPDATE ".MAIN_DB_PREFIX."ecm_files SET extraparams = '".$db->escape((string) json_encode($params))."'";
	$sql .= " WHERE rowid = ".((int) $rowid);

	return $db->query($sql) ? 1 : -1;
}
