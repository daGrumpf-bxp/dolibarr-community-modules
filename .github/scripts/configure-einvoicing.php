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
 */

/**
 * \file    .github/scripts/configure-einvoicing.php
 * \brief   Sets up the einvoicing module of a CI instance the way a user does after activating it.
 * \remarks An activated module left unset is no instance anyone runs: a test reading the setup was then
 *          green on a configured instance and red here. No credential is set, so nothing reaches a
 *          platform. Reads DOLIBARR_HTDOCS. Run it after activate-einvoicing.php.
 */

if (PHP_SAPI !== 'cli') {
	echo "Error: this script must be run from the command line.\n";
	exit(1);
}

global $conf, $db, $langs, $user, $mysoc;

$htdocs = getenv('DOLIBARR_HTDOCS');
if (!$htdocs || !file_exists($htdocs . '/master.inc.php')) {
	fwrite(STDERR, 'DOLIBARR_HTDOCS does not point at an htdocs directory (got "' . $htdocs . '")' . "\n");
	exit(2);
}

require_once $htdocs . '/master.inc.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';

// The setup of the module page, with the test platform of SuperPDP chosen and no account on it. Nothing
// that sends on its own: no automatic sending, no status 211 on a supplier payment, no API validation.
$setup = array(
	'EINVOICING_PDP' => 'SUPERPDP',
	'EINVOICING_PROTOCOL' => 'CII',
	'EINVOICING_LIVE' => '0',
	'EINVOICING_EINVOICE_IN_REAL_TIME' => '1',
	'EINVOICING_AUTO_SEND_ON_GENERATION' => '0',
	'EINVOICING_FLOWS_SYNC_CALL_LIMIT' => '1',
	'EINVOICING_FLOWS_SYNC_CALL_SIZE' => '100',
	'EINVOICING_PRODUCTS_AUTO_GENERATION' => '1',
	'EINVOICING_THIRDPARTIES_AUTO_GENERATION' => '1',
	'EINVOICING_THIRDPARTIES_COMPLETE_INFO' => '1',
	'EINVOICING_SUPPLIER_INVOICE_COMPARISON_ROUND_PRECISION' => '3',
	'EINVOICING_SYNC_MARGIN_TIME_HOURS' => '12',
);

foreach ($setup as $name => $value) {
	if (dolibarr_set_const($db, $name, $value, 'chaine', 0, '', $conf->entity) <= 0) {
		fwrite(STDERR, 'Could not set ' . $name . ': ' . $db->lasterror() . "\n");
		exit(1);
	}
}

echo 'Module set up (' . count($setup) . " constants)\n";
