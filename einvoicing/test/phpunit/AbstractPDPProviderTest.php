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
 *      \file       test/phpunit/AbstractPDPProviderTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for AbstractPDPProvider::makeStorableDebugPayload(): the payloads written
 *                  in the trace of an API call stay inside their column. A response bigger than the column
 *                  had its INSERT refused whole, so the call left no trace at all (issue #995).
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
// AbstractPDPProvider is abstract: its reference implementation is the one instantiated here.
dol_include_once('einvoicing/class/providers/TestPDPProvider.class.php');
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
 * Class for PHPUnit tests
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class AbstractPDPProviderTest extends CommonClassTest
{
	/**
	 * Call the protected makeStorableDebugPayload() of AbstractPDPProvider.
	 *
	 * @param	string|null	$payload	Payload as the provider received it
	 * @return	string					What would be written in the trace
	 */
	private function makeStorable($payload)
	{
		// Without the constructor: the method under test reads no property, and no setup is loaded
		$provider = (new ReflectionClass('TestPDPProvider'))->newInstanceWithoutConstructor();
		$method = new ReflectionMethod('AbstractPDPProvider', 'makeStorableDebugPayload');
		$method->setAccessible(true);

		return $method->invoke($provider, $payload);
	}

	/**
	 * A payload that fits its column is stored as it is.
	 *
	 * @return	void
	 */
	public function testPayloadThatFitsIsUntouched()
	{
		$xml = '<?xml version="1.0" encoding="UTF-8"?><Invoice>' . str_repeat('<Line>Caf&#233; 1,00</Line>', 100) . '</Invoice>';

		$this->assertSame($xml, $this->makeStorable($xml), 'A payload under the limit must be stored unchanged');
		$this->assertSame('', $this->makeStorable(''), 'An empty payload stays empty');
		$this->assertSame('', $this->makeStorable(null), 'A null payload is stored as an empty string');
	}

	/**
	 * A payload over the limit is stored truncated and says so, instead of losing the whole trace.
	 *
	 * @return	void
	 */
	public function testOversizedPayloadIsTruncatedAndMarked()
	{
		$max = AbstractPDPProvider::LOGCALL_MAX_PAYLOAD_SIZE;
		$xml = str_repeat('a', $max + 5000);

		$stored = $this->makeStorable($xml);

		$this->assertLessThanOrEqual($max, strlen($stored), 'A stored payload never exceeds the size of its column');
		$this->assertSame(str_repeat('a', 1000), substr($stored, 0, 1000), 'The beginning of the payload is the one received');

		// What the marker announces is really what was dropped
		$reg = array();
		$this->assertSame(1, preg_match('/\n\[truncated: (\d+) more bytes\]$/', $stored, $reg), 'A truncated payload says how much it left out');
		$kept = strlen($stored) - strlen($reg[0]);
		$this->assertSame(strlen($xml), $kept + (int) $reg[1], 'Kept bytes plus dropped bytes make the payload back');
	}

	/**
	 * The cut never leaves a multi-byte character in half: half a character makes the column invalid
	 * UTF-8, which is the SQL error 1366 the storable payload exists to avoid.
	 *
	 * @return	void
	 */
	public function testTruncationCutsOnACharacterBoundary()
	{
		$max = AbstractPDPProvider::LOGCALL_MAX_PAYLOAD_SIZE;

		// Four sizes so the cut falls on each byte of the "é" (2 bytes) and of the "€" (3 bytes)
		foreach (array(0, 1, 2, 3) as $shift) {
			$payload = str_repeat('a', $max - 64 - $shift) . str_repeat('é€', 100);

			$stored = $this->makeStorable($payload);

			$this->assertSame(1, preg_match('//u', $stored), 'A truncated payload stays valid UTF-8 (shift ' . $shift . ')');
			$this->assertLessThanOrEqual($max, strlen($stored), 'A stored payload never exceeds the size of its column');
		}
	}

	/**
	 * A binary payload is base64-encoded, and what is stored of it decodes: the trace is cut on a
	 * 4-character boundary, not in the middle of an encoded group.
	 *
	 * @return	void
	 */
	public function testBinaryPayloadStaysDecodable()
	{
		$binary = str_repeat("\x00\xff\xfe", 100);	// not valid UTF-8

		$stored = $this->makeStorable($binary);
		$this->assertStringStartsWith('[base64] ', $stored, 'A binary payload is stored base64-encoded');
		$this->assertSame($binary, base64_decode(substr($stored, strlen('[base64] '))), 'A short binary payload is stored whole');

		$max = AbstractPDPProvider::LOGCALL_MAX_PAYLOAD_SIZE;
		$big = str_repeat("\x00\xff\xfe", $max);

		$stored = $this->makeStorable($big);
		$this->assertLessThanOrEqual($max, strlen($stored), 'A stored payload never exceeds the size of its column');
		$this->assertSame(1, preg_match('/\n\[truncated: \d+ more bytes\]$/', $stored), 'A truncated payload says how much it left out');

		$encoded = substr($stored, strlen('[base64] '), strpos($stored, "\n[truncated:") - strlen('[base64] '));
		$decoded = base64_decode($encoded, true);
		$this->assertNotSame(false, $decoded, 'What is stored of a binary payload can still be decoded');
		$this->assertSame(substr($big, 0, strlen($decoded)), $decoded, 'It decodes to the beginning of the payload');
	}

	/**
	 * A provider whose calls to the access point are answered from a fixed list, and counted.
	 *
	 * @param	array<string,array{status_code:int,response:string}>	$answers	Answer by docType
	 * @param	string[]	$asked		Filled with each docType asked for
	 * @return	AbstractPDPProvider
	 */
	private function providerAnswering(array $answers, array &$asked)
	{
		$provider = new class ($answers, $asked) extends TestPDPProvider {
			/** @var array<string,array{status_code:int,response:string}> */
			public $answers;
			/** @var string[] */
			public $asked;

			/**
			 * @param array<string,array{status_code:int,response:string}> $answers Answer by docType
			 * @param string[] $asked Filled with each docType asked for
			 */
			public function __construct(array $answers, array &$asked)
			{
				$this->answers = $answers;
				$this->asked = &$asked;
			}

			/**
			 * @param string $flowId Flow
			 * @param string $docType Doc type
			 * @param string $callType Call type
			 * @return array{status_code:int,response:string}
			 */
			public function fetchFlowData($flowId, $docType, $callType = '')
			{
				$this->asked[] = $docType;
				return $this->answers[$docType] ?? array('status_code' => 404, 'response' => '');
			}
		};

		return $provider;
	}

	/**
	 * Call the protected fetchOtherReceivedFormats() of AbstractPDPProvider.
	 *
	 * @param	AbstractPDPProvider	$provider	The provider
	 * @param	array<string,mixed>	$importable	What fetchImportableFlowDocument() returned
	 * @return	array<string,string>
	 */
	private function otherFormats($provider, array $importable)
	{
		$method = new ReflectionMethod('AbstractPDPProvider', 'fetchOtherReceivedFormats');
		$method->setAccessible(true);

		return $method->invoke($provider, 'flow1', $importable);
	}

	/**
	 * Without the option, nothing more is asked of the access point (issue #980).
	 *
	 * @return	void
	 */
	public function testOtherFormatsAreNotFetchedWithoutTheOption()
	{
		global $conf;

		$conf->global->EINVOICING_SAVE_ALL_RECEIVED_FORMATS = 0;
		$asked = array();
		$provider = $this->providerAnswering(array('Original' => array('status_code' => 200, 'response' => '<Invoice/>')), $asked);

		$this->assertSame(array(), $this->otherFormats($provider, array('doc_type' => 'Converted', 'documents' => array())));
		$this->assertSame(array(), $asked);
	}

	/**
	 * With the option, the format that was not imported is fetched and handed back.
	 *
	 * @return	void
	 */
	public function testTheOriginalIsFetchedBesideTheConverted()
	{
		global $conf;

		$conf->global->EINVOICING_SAVE_ALL_RECEIVED_FORMATS = 1;
		$asked = array();
		$provider = $this->providerAnswering(array('Original' => array('status_code' => 200, 'response' => '<Invoice/>')), $asked);

		$others = $this->otherFormats($provider, array('doc_type' => 'Converted', 'documents' => array('Converted' => '<rsm/>')));
		$conf->global->EINVOICING_SAVE_ALL_RECEIVED_FORMATS = 0;

		$this->assertSame(array('Original' => '<Invoice/>'), $others);
		$this->assertSame(array('Original'), $asked);
	}

	/**
	 * A document the import already fetched is not asked for twice, and a failure is not fatal.
	 *
	 * @return	void
	 */
	public function testAnAlreadyFetchedFormatIsReusedAndAFailureSkipped()
	{
		global $conf;

		$conf->global->EINVOICING_SAVE_ALL_RECEIVED_FORMATS = 1;
		$asked = array();
		$provider = $this->providerAnswering(array(), $asked);

		// The Converted was fetched but unreadable, the Original was imported
		$reused = $this->otherFormats($provider, array('doc_type' => 'Original', 'documents' => array('Converted' => '<rsm/>', 'Original' => '<Invoice/>')));
		// Nothing was fetched, and the access point answers 404 for the Converted
		$failed = $this->otherFormats($provider, array('doc_type' => 'Original', 'documents' => array()));
		$conf->global->EINVOICING_SAVE_ALL_RECEIVED_FORMATS = 0;

		$this->assertSame(array('Converted' => '<rsm/>'), $reused);
		$this->assertSame(array(), $failed);
		$this->assertSame(array('Converted'), $asked);
	}
}
