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
 *       \file       einvoicing/invoice_attachments.php
 *       \ingroup    einvoicing
 *       \brief      Tab on the customer invoice card: files embedded in the e-invoice as additional supporting documents (BG-24)
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
// Try main.inc.php using relative path
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res && file_exists("../../../../main.inc.php")) {
	$res = @include "../../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}
/**
 * The main.inc.php has been included so the following variable are now defined:
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/invoice.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formfile.class.php';
dol_include_once('/einvoicing/lib/einvoicing.lib.php');
dol_include_once('/einvoicing/class/protocols/CIIProtocol.class.php');
dol_include_once('/einvoicing/lib/einvoicing_attachments.lib.php');

$langs->loadLangs(array('bills', 'other', 'einvoicing@einvoicing'));

$id = GETPOSTINT('id');
$ref = GETPOST('ref', 'alpha');
$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');
$fileid = GETPOSTINT('fileid');

// Security check: these files are documents of the invoice, so the rights are the ones of its Documents tab
if (!isModEnabled('einvoicing') || !getDolGlobalInt('EINVOICING_EMBED_ATTACHED_FILES')) {
	accessforbidden('Option EINVOICING_EMBED_ATTACHED_FILES not enabled');
}

$object = new Facture($db);
if ($id > 0 || !empty($ref)) {
	$object->fetch($id, $ref);
}
$result = restrictedArea($user, 'facture', $object->id, '');
if ($object->id <= 0) {
	accessforbidden();
}

$permissiontoadd = $user->hasRight('facture', 'creer');
$upload_dir = getMultidirOutputCompat($object, '', 1);
$codes = einvoicingAttachmentCodes();
$selfurl = $_SERVER['PHP_SELF'].'?id='.$object->id;


/*
 * Actions
 */

if (GETPOST('sendit', 'alpha') && $permissiontoadd) {
	// Refuse what the e-invoice cannot carry before it lands on disk, rather than skip it at generation
	$names = isset($_FILES['userfile']['name']) ? (array) $_FILES['userfile']['name'] : array();
	$refused = array();
	foreach ($names as $name) {
		if ($name !== '' && einvoicingAttachmentMimeCode($name) === '') {
			$refused[] = $name;
		}
	}
	if ($refused) {
		setEventMessages($langs->trans('EInvAttachmentTypeNotAllowed', implode(', ', $refused), implode(', ', array_unique(CIIProtocol::ATTACHMENT_MIME_EXTENSIONS))), null, 'errors');
	} else {
		$indexpath = einvoicingAttachmentIndexPath($upload_dir);
		$resql = $db->query("SELECT MAX(rowid) as maxid FROM ".MAIN_DB_PREFIX."ecm_files");
		$maxid = ($resql && ($obj = $db->fetch_object($resql))) ? (int) $obj->maxid : 0;

		// The upload itself is the one of the core, which indexes the file in llx_ecm_files
		include DOL_DOCUMENT_ROOT.'/core/actions_linkedfiles.inc.php';

		$sql = "SELECT rowid, filename FROM ".MAIN_DB_PREFIX."ecm_files";
		$sql .= " WHERE rowid > ".((int) $maxid)." AND filepath = '".$db->escape($indexpath)."' AND entity = ".((int) $object->entity);
		$resql = $db->query($sql);
		while ($resql && ($obj = $db->fetch_object($resql))) {
			einvoicingSetAttachmentCode($db, (int) $obj->rowid, '');
		}
	}
	header('Location: '.$selfurl);
	exit;
}

$attached = einvoicingFetchAttachedFiles($db, $object);

if ($action == 'setcodes' && $permissiontoadd) {
	$wanted = array();
	foreach ($attached as $rowid => $file) {
		$code = GETPOST('code_'.$rowid, 'aZ09');
		$wanted[$rowid] = in_array($code, $codes, true) ? $code : '';
	}
	// BR-FR-18: one supporting document at most is the readable view of the invoice
	if (count(array_keys($wanted, 'LISIBLE', true)) > 1) {
		setEventMessages($langs->trans('EInvAttachmentOnlyOneLisible'), null, 'errors');
	} else {
		$error = 0;
		foreach ($wanted as $rowid => $code) {
			if ($code !== $attached[$rowid]['code'] && einvoicingSetAttachmentCode($db, $rowid, $code) < 0) {
				$error++;
			}
		}
		if ($error) {
			setEventMessages($db->lasterror(), null, 'errors');
		} else {
			setEventMessages($langs->trans('RecordSaved'), null, 'mesgs');
		}
	}
	header('Location: '.$selfurl);
	exit;
}

if ($action == 'confirm_deletefile' && $confirm == 'yes' && $permissiontoadd && isset($attached[$fileid])) {
	if (dol_delete_file($attached[$fileid]['fullname'], 0, 0, 0, $object)) {
		setEventMessages($langs->trans('FileWasRemoved', $attached[$fileid]['filename']), null, 'mesgs');
	} else {
		setEventMessages($langs->trans('ErrorFailToDeleteFile', $attached[$fileid]['filename']), null, 'errors');
	}
	header('Location: '.$selfurl);
	exit;
}


/*
 * View
 */

$form = new Form($db);
$formfile = new FormFile($db);

llxHeader('', $object->ref.' - '.$langs->trans('EInvAttachmentsTab'));

$object->fetch_thirdparty();
$head = facture_prepare_head($object);
print dol_get_fiche_head($head, 'einvoiceattachments', $langs->trans('InvoiceCustomer'), -1, $object->picto);

$linkback = '<a href="'.DOL_URL_ROOT.'/compta/facture/list.php?restore_lastsearch_values=1">'.$langs->trans("BackToList").'</a>';
$morehtmlref = '<div class="refidno">'.$object->thirdparty->getNomUrl(1, 'customer').'</div>';
dol_banner_tab($object, 'ref', $linkback, 1, 'ref', 'ref', $morehtmlref, '', 0, '', '', 1);

print dol_get_fiche_end();

if ($action == 'deletefile' && isset($attached[$fileid])) {
	print $form->formconfirm($selfurl.'&fileid='.$fileid, $langs->trans('DeleteFile'), $langs->trans('ConfirmDeleteFile'), 'confirm_deletefile', '', 0, 1);
}

print '<div class="opacitymedium marginbottomonly">'.$langs->trans('EInvAttachmentsTabHelp').'</div>';

// Below EN 16931 the schema has no room for BG-24: say so here rather than let the files vanish at generation
$profile = dol_strtoupper(trim(getDolGlobalString('EINVOICING_XML_PROFILE')));
if (in_array($profile, array('MINIMUM', 'BASICWL', 'BASIC'), true)) {
	print info_admin($langs->trans('EInvAttachmentProfileTooLow', $profile), 0, 0, 'warning');
}

print '<form method="POST" action="'.$selfurl.'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="setcodes">';
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans('Document').'</td>';
print '<td class="right">'.$langs->trans('Size').'</td>';
print '<td class="center">'.$langs->trans('DateModification').'</td>';
print '<td>'.$form->textwithpicto($langs->trans('EInvAttachmentCode'), $langs->trans('EInvAttachmentCodeHelp')).'</td>';
print '<td></td>';
print '</tr>';

if (empty($attached)) {
	print '<tr class="oddeven"><td colspan="5"><span class="opacitymedium">'.$langs->trans('EInvAttachmentNone').'</span></td></tr>';
}
foreach ($attached as $rowid => $file) {
	$relative = dol_sanitizeFileName($object->ref).'/'.$file['filename'];
	print '<tr class="oddeven">';
	print '<td class="tdoverflowmax300">'.img_mime($file['filename']).' ';
	print '<a href="'.DOL_URL_ROOT.'/document.php?modulepart=facture&entity='.((int) $object->entity).'&file='.urlencode($relative).'" target="_blank" rel="noopener">'.dol_escape_htmltag($file['filename']).'</a></td>';
	print '<td class="right nowraponall">'.dol_print_size($file['size'], 1, 1).'</td>';
	print '<td class="center nowraponall">'.dol_print_date($file['date'], 'dayhour', 'tzuser').'</td>';
	print '<td>';
	print '<select name="code_'.$rowid.'" id="code_'.$rowid.'" class="flat minwidth300"'.($permissiontoadd ? '' : ' disabled').'>';
	print '<option value="">'.$langs->trans('EInvAttachmentCodeNone').'</option>';
	foreach ($codes as $code) {
		print '<option value="'.$code.'"'.($code === $file['code'] ? ' selected' : '').'>'.$code.' - '.$langs->trans('EInvAttachmentCode_'.$code).'</option>';
	}
	print '</select>';
	print ajax_combobox('code_'.$rowid);
	print '</td>';
	print '<td class="right nowraponall">';
	if ($permissiontoadd) {
		print '<a class="reposition" href="'.$selfurl.'&action=deletefile&token='.newToken().'&fileid='.$rowid.'">'.img_delete().'</a>';
	} else {
		print '<span class="opacitymedium" title="'.dol_escape_htmltag($langs->trans('NotEnoughPermissions')).'">'.img_delete().'</span>';
	}
	print '</td>';
	print '</tr>';
}
print '</table>';
print '</div>';
if (!empty($attached)) {
	print '<div class="center"><input type="submit" class="button button-save" value="'.$langs->trans('Save').'"'.($permissiontoadd ? '' : ' disabled title="'.dol_escape_htmltag($langs->trans('NotEnoughPermissions')).'"').'></div>';
}
print '</form>';

print '<br>';
// No saving mask: a name without the invoice ref cannot be mistaken for a document of the invoice
$accept = '.'.implode(',.', array_unique(array_merge(array_values(CIIProtocol::ATTACHMENT_MIME_EXTENSIONS), array('jpeg'))));
$formfile->form_attach_new_file($selfurl, $langs->trans('EInvAttachmentAdd'), 0, 0, $permissiontoadd, 50, $object, '', 1, '', 0, 'formuserfile', $accept);

llxFooter();
$db->close();
