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
 * along with this program. If not, see https://www.gnu.org/licenses/
 */

/**
 *      \file       test/phpunit/SupplierInvoiceStatusFromCdarTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for AbstractPDPProvider::processSupplierInvoiceStatusFromCdar(): the
 *                  lifecycle status of a supplier invoice that no row of this Dolibarr identifies -
 *                  one the vendor issued, or one issued for our account outside Dolibarr (issue #1020).
 *                  What is checked here needs no invoice in database: which failures are retried and
 *                  which are stored, and that each direction gets its own translated wording.
 *      \remarks    To run this script as CLI: phpunit filename.php
 */

global $conf, $user, $langs, $db;

// See RecipientDirectoryTest for why DOLIBARR_HTDOCS is honoured here.
$dolibarrHtdocs = getenv('DOLIBARR_HTDOCS');
if (!$dolibarrHtdocs) {
	$dolibarrHtdocs = dirname(__FILE__) . '/../../htdocs';
}
if (!file_exists($dolibarrHtdocs . '/master.inc.php')) {
	throw new \RuntimeException('Could not locate master.inc.php under "' . $dolibarrHtdocs . '/". Set the environment variable (export DOLIBARR_HTDOCS=...) to the htdocs directory of the Dolibarr instance to test against.');
}

require_once $dolibarrHtdocs . '/master.inc.php';
dol_include_once('einvoicing/class/providers/AbstractPDPProvider.class.php');
dol_include_once('einvoicing/class/document.class.php');
dol_include_once('einvoicing/class/einvoicing.class.php');
require_once __DIR__ . '/CommonClassTestCompat.inc.php';

if (empty($user->id)) {
	print "Load permissions for admin user nb 1\n";
	$user->fetch(1);
	// User::loadRights() only exists from Dolibarr 19 on, older versions name it getrights()
	if (method_exists($user, 'loadRights')) {
		$user->loadRights();
	} else {
		$user->getrights();
	}
}
$conf->global->MAIN_DISABLE_ALL_MAILS = 1;


/**
 * Provider double: answers the flow document lookups from a canned queue instead of the network.
 * Every other abstract method of the base class is stubbed out, none of them is reached by the
 * tested code path.
 */
class FakeLifecycleStatusPDPProvider extends AbstractPDPProvider
{
	/** @var array<int,array<string,mixed>> Canned answers, consumed in order by callApi() */
	public $cannedResponses = [];

	/** @var array<int,string> Resources requested so far, in order */
	public $calledResources = [];

	/**
	 * Constructor. The real one loads credentials and instantiates the protocol manager: the tested
	 * path uses neither, so only the database handler is set.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Return the next canned answer instead of calling a platform.
	 *
	 * @param 	string 			$resource 		Resource path
	 * @param 	string 			$method 		HTTP method
	 * @param 	array|string|false $options 	Request body
	 * @param 	array 			$extraHeaders 	Extra HTTP headers
	 * @param 	string 			$callType 		Call type used for logging
	 * @return 	array{status_code:int,response:mixed}
	 */
	public function callApi($resource, $method, $options = false, $extraHeaders = [], $callType = '')
	{
		$this->calledResources[] = $resource;

		$next = array_shift($this->cannedResponses);

		return $next !== null ? $next : array('status_code' => 500, 'response' => '');
	}

	/**
	 * @return array{status_code:int,message:string}
	 */
	public function getRemoteInfo()
	{
		return array('status_code' => 200, 'message' => '');
	}

	/**
	 * @param  int $mode Mode
	 * @return int
	 */
	public function validateConfiguration($mode = 1)
	{
		return 1;
	}

	/**
	 * @return string|null
	 */
	public function getAccessToken()
	{
		return null;
	}

	/**
	 * @return string|null
	 */
	public function refreshAccessToken()
	{
		return null;
	}

	/**
	 * @return array{res:int,message:string}
	 */
	public function checkHealth()
	{
		return array('res' => 1, 'message' => '');
	}

	/**
	 * @param  int $onlymake Only build the sample
	 * @return array{res:int,message:string}
	 */
	public function sendSampleInvoice($onlymake = 0)
	{
		return array('res' => 1, 'message' => '');
	}

	/**
	 * @param  int    $idinvoice Invoice id
	 * @param  string $filePath  File to validate
	 * @return array{res:int,message:string}
	 */
	public function validateEInvoiceFile($idinvoice, $filePath)
	{
		return array('res' => 1, 'message' => '');
	}

	/**
	 * @param  int $syncFromDate Sync from
	 * @param  int $limit        Max flows
	 * @return array{res:int,messages:array<string>}
	 */
	public function syncFlows($syncFromDate = 0, $limit = 0)
	{
		return array('res' => 1, 'messages' => array());
	}

	/**
	 * @param  string  $flowId  Flow id
	 * @param  ?string $call_id Call id
	 * @return array{res:int,message:string}
	 */
	public function syncFlow($flowId, $call_id = null)
	{
		return array('res' => 1, 'message' => '');
	}

	/**
	 * @param  object $object Invoice
	 * @return int
	 */
	public function sendInvoice($object)
	{
		return 0;
	}

	/**
	 * Declared with the widest signature on purpose: a provider may take an extra optional
	 * argument for the payment details, and a child that only adds an optional parameter stays
	 * compatible with the narrower declaration too.
	 *
	 * @param  object $object      Invoice
	 * @param  int    $statusCode  Lifecycle status
	 * @param  string $reasonCode  Reason code
	 * @param  array  $paymentData Payment details carried by some statuses
	 * @return array{res:int,message:string}
	 */
	public function sendStatusMessage($object, $statusCode, $reasonCode = '', $paymentData = [])
	{
		return array('res' => 1, 'message' => '');
	}
}


/**
 * Class for PHPUnit tests
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class SupplierInvoiceStatusFromCdarTest extends CommonClassTest
{
	/**
	 * Run the tested method on a flow of the given direction, with the answers the platform is to give.
	 *
	 * @param	string							$direction			Direction of the flow, 'In' or 'Out'
	 * @param	array<int,array<string,mixed>>	$cannedResponses	Answers callApi() hands back, in order
	 * @param	?Document						$document			Set to the flow document the method completed
	 * @return	array<string,mixed>									Return of processSupplierInvoiceStatusFromCdar()
	 */
	private function processFlow($direction, $cannedResponses, &$document = null)
	{
		global $db;

		$provider = new FakeLifecycleStatusPDPProvider($db);
		$provider->cannedResponses = $cannedResponses;

		$document = new Document($db);
		$document->flow_id = 'ie_2031952';
		$document->flow_type = 'SupplierInvoiceLC';
		$document->flow_direction = $direction;
		$document->tracking_idref = 'SUPERPDP ie_2031952';

		$einvoicing = new EInvoicing($db);

		$method = new ReflectionMethod('AbstractPDPProvider', 'processSupplierInvoiceStatusFromCdar');
		$method->setAccessible(true);

		return $method->invoke($provider, 'ie_2031952', $document, $einvoicing);
	}

	/**
	 * A status issued for our account outside Dolibarr (from the web interface of the access point, or
	 * by another system sharing the account) has no row in einvoicing_lifecycle_msg, so the flow reaches
	 * the CDAR reading. A platform that will not serve the document must leave it unstored and retried,
	 * and say so in its own words: its vendor has nothing to do with it.
	 *
	 * @return void
	 */
	public function testOutgoingStatusUnreadableThisRunIsPostponedWithItsOwnWording()
	{
		global $langs;

		$langs->load('einvoicing@einvoicing');

		$res = $this->processFlow('Out', array(
			array('status_code' => 500, 'response' => ''),		// Original
			array('status_code' => 500, 'response' => ''),		// Converted
		));

		$this->assertLessThan(0, $res['res'], 'A platform failure must not be stored as a read status');
		$this->assertSame(1, $res['postponeflow'], 'The flow must be retried on the next run');
		$this->assertSame('CANT_READ_OUTGOING_LIFECYCLE_STATUS', $res['actioncode']);
		$this->assertNotSame('CantReadTheStatusIssuedOutsideDolibarr', $res['businessmessage'], 'Translation key is missing from the lang file');
		$this->assertStringContainsString('ie_2031952', $res['businessmessage']);
	}

	/**
	 * The same failure on a status the vendor issued keeps the wording it has always had.
	 *
	 * @return void
	 */
	public function testIncomingStatusUnreadableThisRunKeepsTheVendorWording()
	{
		global $langs;

		$langs->load('einvoicing@einvoicing');

		$res = $this->processFlow('In', array(
			array('status_code' => 500, 'response' => ''),		// Original
			array('status_code' => 500, 'response' => ''),		// Converted
		));

		$this->assertLessThan(0, $res['res']);
		$this->assertSame(1, $res['postponeflow']);
		$this->assertSame('CANT_READ_INCOMING_LIFECYCLE_STATUS', $res['actioncode']);
		$this->assertNotSame('CantReadTheStatusSentByTheVendor', $res['businessmessage'], 'Translation key is missing from the lang file');
	}

	/**
	 * A CDAR issued by the web interface of an access point, which is the case this fallback exists
	 * for. Every element of it sits in a DEFAULT namespace, with no prefix anywhere, unlike the one
	 * the module writes - so it is what proves the xpath of CdarHandler reads such a document:
	 * matching is on the namespace URI, not on the prefix. No invoice carries that reference here, so
	 * the run stops on the lookup, naming the reference it read.
	 *
	 * @return void
	 */
	public function testStatusIssuedByTheAccessPointInterfaceIsReadFromItsCdar()
	{
		$cdar = (string) file_get_contents(__DIR__ . '/fixtures/received/lifecycle_cdar_from_access_point_ui.xml');

		$document = null;
		$res = $this->processFlow('Out', array(
			array('status_code' => 200, 'response' => $cdar),
		), $document);

		$this->assertSame(0, $res['res']);
		$this->assertStringContainsString('EINV-TEST-1020-REF', $res['message'], 'The vendor reference of the CDAR was not read');
		$this->assertSame('211', (string) $document->cdar_lifecycle_code);
		$this->assertSame('Paiement transmis', (string) $document->cdar_lifecycle_label);
	}

	/**
	 * A document that is served but carries no lifecycle code will carry none on the next run either:
	 * it is stored (res 0) so the synchronization stops reading it, the opposite of the case above.
	 *
	 * @return void
	 */
	public function testDocumentWithoutLifecycleCodeIsStoredRatherThanRetried()
	{
		$res = $this->processFlow('Out', array(
			array('status_code' => 200, 'response' => '<?xml version="1.0" encoding="UTF-8"?><root/>'),
		));

		$this->assertSame(0, $res['res'], 'A document with nothing to record must not be retried run after run');
		$this->assertArrayNotHasKey('postponeflow', $res);
	}
}
