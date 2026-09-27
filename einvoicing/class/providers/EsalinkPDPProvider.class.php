<?php
/* Copyright (C) 2025       Laurent Destailleur         <eldy@users.sourceforge.net>
 * Copyright (C) 2026		Jose Martinez				<jose.martinez@pichinov.com>
 * Copyright (C) 2025       Mohamed DAOUD               <mdaoud@dolicloud.com>
 * Copyright (C) 2026       Frédéric France             <frederic.france@free.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */


/**
 * \file    einvoicing/class/providers/EsalinkPDPProvider.class.php
 * \ingroup einvoicing
 * \brief   Esalink PDP provider integration class
 */

dol_include_once('einvoicing/class/providers/AbstractPDPProvider.class.php');
dol_include_once('einvoicing/class/protocols/ProtocolManager.class.php');
dol_include_once('einvoicing/class/call.class.php');
dol_include_once('einvoicing/class/einvoicing.class.php');
dol_include_once('einvoicing/lib/einvoicing.lib.php');
require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';


/**
 * Class to manage Esalink PDP provider integration.
 */
class EsalinkPDPProvider extends AbstractPDPProvider
{
	/**
	 * @var string		Name
	 */
	public $name = 'Esalink';

	/**
	 * @var string		Help to get credentials and set up the provider configuration.
	 */
	public $helpToGetCredentials = '';


	/**
	 * Constructor
	 *
	 * Load setup properties and last token.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $langs;

		parent::__construct($db);

		$this->config = array(
			'provider_url' => 'https://hubtimize.fr',
			'prod_auth_url' => 'https://hubtimize.fr/api/orchestrator/v1/',
			'prod_api_url' => 'https://hubtimize.fr/api/orchestrator/v1/',
			'test_auth_url' => 'https://ppd.hubtimize.fr/api/orchestrator/v1/',
			'test_api_url' => 'https://ppd.hubtimize.fr/api/orchestrator/v1/',
			// The AFNOR Directory Service (XP Z12-013) is served from the same base as the flows on this
			// platform, so the recipient reachability pre-check of AbstractPDPProvider works here too.
			'prod_afnor_directory_url' => 'https://hubtimize.fr/api/orchestrator/v1/',
			'test_afnor_directory_url' => 'https://ppd.hubtimize.fr/api/orchestrator/v1/',
			'username' => getDolGlobalString('EINVOICING_ESALINK_USERNAME'.(getDolGlobalInt('EINVOICING_LIVE') ? '_PROD' : '')),
			'password' => getDolGlobalString('EINVOICING_ESALINK_PASSWORD'.(getDolGlobalInt('EINVOICING_LIVE') ? '_PROD' : '')),
			'api_key'  => getDolGlobalString('EINVOICING_ESALINK_API_KEY'.(getDolGlobalInt('EINVOICING_LIVE') ? '_PROD' : '')),
			'dol_prefix' => 'EINVOICING_ESALINK',
			'has_validator' => 0,
			'live' => getDolGlobalInt('EINVOICING_LIVE', 0)
		);


		$this->helpToGetCredentials = $langs->trans("EINVOICING_ESALINKP_HELP_CREDENTIAL1");
		$this->helpToGetCredentials .= '<br>' . $langs->trans("EINVOICING_ESALINKP_HELP_CREDENTIAL2", '{s1}');

		// Retrieve and complete the OAuth token information from the database
		$this->tokenData = $this->fetchOAuthTokenDB(getDolGlobalInt("EINVOICING_MULTICOMPANY_USE_MASTER_SETUP"));

		/*
		$exchangeProtocolConf = getDolGlobalString('EINVOICING_PROTOCOL');
		$ProtocolManager = new ProtocolManager($this->db);
		$this->exchangeProtocol = $ProtocolManager->getProtocol($exchangeProtocolConf);
		*/
	}


	/**
	 * Set the setup factory specific to the provider.
	 *
	 * @param FormSetup $formSetup 			The form setup object to initialize
	 * @param string 	$prefix 			The prefix for configuration keys
	 * @param string 	$prefixenv 			The prefix for environment variable keys
	 * @param array 	$providersConfig 	The array containing providers configuration
	 * @param array 	$TFieldProtocols 	The array of available protocols to set in the select field
	 * @param array 	$TFieldProfiles 	The array of available profiles to set in the select field
	 * @return void
	 */
	public function initFormSetup(&$formSetup, $prefix, $prefixenv, $providersConfig, $TFieldProtocols, $TFieldProfiles)
	{
		global $langs, $mysoc;

		$tokenData = $this->getTokenData();

		$langs->load("oauth");

		// Set content of the help page
		$url = $providersConfig[getDolGlobalString('EINVOICING_PDP')][$prefixenv . '_account_admin_url'];
		$urltosubscribe = img_picto('', 'url', 'class="pictofixedwidth"') . '<a href="' . $url . '" target="_new">' . $url . '</a>';

		if (empty($tokenData['token'])) {
			$this->helpToGetCredentials = str_replace('{s1}', $urltosubscribe, $this->helpToGetCredentials);

			$this->helpToGetCredentials = '<div class="formborderx info">' . $this->helpToGetCredentials . '</div>';
		} else {
			$this->helpToGetCredentials = '<div class="green greenborder">';
			$this->helpToGetCredentials .= '<center>';
			$this->helpToGetCredentials .= $langs->trans("YourSoftwareSeemsConnectedWith", strtoupper($this->name));
			$this->helpToGetCredentials .= ' <a href="'.$this->config['provider_url'].'" target="_blank">('.$this->config['provider_url'].')</a>';
			$this->helpToGetCredentials .= '<br><br>' . img_picto('', 'delete', 'class="pictofixedwidth"') . '<a href="' . $_SERVER["PHP_SELF"] . '?action=delete' . $prefix . "TOKEN&token=" . newToken() . '">' . $langs->trans("ClickHereToRemoveConnection") . '</a>';
			$this->helpToGetCredentials .= '</center>';
			$this->helpToGetCredentials .= '</div>';
		}


		// E-Invoice ID
		$item = $formSetup->newItem($prefix . 'ROUTING_ID');
		$item->nameText = $langs->transnoentities('EINVOICING_ROUTING_ID');
		$item->helpText = $langs->transnoentities('EINVOICING_ROUTING_ID_HELP');
		$item->helpText .= '<br><br>'.img_picto('', 'warning').' '.$langs->trans('WarningIfYouSetAnIDItMustExistsInAnnuary');
		// @phan-suppress-next-line PhanTypeMismatchArgumentNullable
		$item->fieldAttr['placeholder'] = idprof($mysoc);
		$item->fieldParams['isMandatory'] = 0;
		$item->fieldAttr['autocomplete'] = "new-password";
		$item->cssClass = 'minwidth300';

		// Setup conf to choose a protocol of exchange
		/* Moved into the tab "Options"
		$item = $formSetup->newItem('EINVOICING_PROTOCOL')->setAsSelect($TFieldProtocols);
		$item->helpText = $langs->transnoentities('EINVOICING_PROTOCOL_HELP');
		$item->defaultFieldValue = 'FACTURX';
		$item->cssClass = 'minwidth500';
		$item->fieldParams['trClass'] = 'advancedoption';
		*/

		// Setup conf to choose a profil of exchange
		// $item = $formSetup->newItem('EINVOICING_PROFILE')->setAsSelect($TFieldProfiles);
		// $item->helpText = $langs->transnoentities('EINVOICING_PROFILE_HELP');
		// $item->defaultFieldValue = 'EN16931';
		// $item->cssClass = 'minwidth500';
		// $item->fieldParams['trClass'] = 'advancedoption';

		// Client ID
		$item = $formSetup->newItem($prefix . 'USERNAME'.(getDolGlobalInt('EINVOICING_LIVE') ? '_PROD' : ''));
		$item->nameText = $langs->transnoentities('EINVOICING_CLIENT_ID');
		$item->cssClass = 'minwidth500';
		$item->fieldAttr['autocomplete'] = "new-password";

		// Client secret
		$item = $formSetup->newItem($prefix . 'PASSWORD'.(getDolGlobalInt('EINVOICING_LIVE') ? '_PROD' : ''));
		if (method_exists($item, 'setAsGenericPassword')) {
			$item->setAsGenericPassword();
		} else {
			// Dolibarr 18/19 fallback: setAsGenericPassword() does not exist yet.
			// Force a masked password input so the secret is not displayed in clear text.
			$item->fieldAttr['type'] = 'password';
		}
		$item->fieldAttr['autocomplete'] = "new-password";
		$item->nameText = $langs->transnoentities('EINVOICING_CLIENT_SECRET');
		$item->cssClass = 'minwidth500';
		$this->storeThisFieldEncrypted($item);

		// API_KEY
		$item = $formSetup->newItem($prefix . 'API_KEY'.(getDolGlobalInt('EINVOICING_LIVE') ? '_PROD' : ''));
		$item->nameText = $langs->transnoentities('EINVOICING_API_KEY');
		$item->cssClass = 'minwidth500';
		$this->storeThisFieldEncrypted($item);

		// Token
		if (getDolGlobalString($prefix . 'API_KEY'.(getDolGlobalInt('EINVOICING_LIVE') ? '_PROD' : ''))) {
			$texttoshow = $langs->trans('ConnectTo').' ('.$langs->trans('generateAccessToken').')';
			$urltogeneratetoken = $_SERVER["PHP_SELF"] . "?action=set" . $prefix . "TOKEN&token=" . newToken();

			$item = $formSetup->newItem($prefix . 'TOKEN'.(getDolGlobalInt('EINVOICING_LIVE') ? '_PROD' : ''));
			$item->nameText = $langs->trans('AccessToken');
			$item->cssClass = 'maxwidth500 ';
			$item->fieldOverride = "";
			if (!empty($tokenData['token'])) {
				$item->fieldOverride = htmlspecialchars('**************' . substr($tokenData['token'], -4));

				if (!empty($tokenData['token_expires_at'])) {
					$item->fieldOverride .= ' &nbsp; <span class="opacitymedium hideonsmartphone">(' . $langs->trans("Until") . ' ' . dol_print_date($tokenData['token_expires_at'], 'dayhoursec', 'tzuserrel') . ')</span>';
				}
				//var_dump($tokenData);
			}
			if (empty($tokenData['token'])) {
				$item->fieldOverride .= '<a class="reposition" href="' . $urltogeneratetoken . '">' . $texttoshow . '<i class="fa fa-key paddingleft"></i></a>';
			}
			if (!empty($tokenData['token'])) {
				$item->fieldOverride .= ' &nbsp; &nbsp; &nbsp; <a class="reposition" href="' . $urltogeneratetoken . '"><i class="fa fa-key paddingright"></i>' . $langs->trans('reGenerateAccessToken') . '</a>';
			}

			if (!empty($tokenData['token'])) {
				$item->fieldOverride .= ' &nbsp; &nbsp; <a class="reposition" href="' . $_SERVER["PHP_SELF"] . "?action=delete" . $prefix . "TOKEN&token=" . newToken() . '">' . img_picto($langs->trans("Delete"), 'delete') . '</a>';
			}
		}

		if (!empty($tokenData['token'])) {
			// Actions
			$item = $formSetup->newItem($prefix . 'ACTIONS');
			$item->nameText = "&nbsp;";

			$item->fieldOverride .= '<a class="reposition" href="' . $_SERVER["PHP_SELF"] . "?action=call" . $prefix . "HEALTHCHECK&token=" . newToken() . '"><i class="fa fa-heartbeat pictofixedwidth centerimp"></i>' . $langs->trans('testConnection') . ' (Healthcheck)</a><br>';
			$item->cssClass = 'minwidth500';

			if ($tokenData['token'] && getDolGlobalString('EINVOICING_PROTOCOL')) {
				if (getDolGlobalString('EINVOICING_LIVE')) {
					$item->fieldOverride .= '<span class="opacitymedium" title="'.$langs->trans("DisabledInProductionMode").'"><i class="fa fa-file pictofixedwidth centerimp"></i>' . $langs->trans('generateSendSampleInvoice') . '</span><br>';
				} else {
					if (getDolGlobalInt('EINVOICING_ALLOW_DEVTOOLS')) {
						$item->fieldOverride .= '<a class="reposition" href="' . $_SERVER["PHP_SELF"] . "?action=make" . $prefix . "sampleinvoice&token=" . newToken() . '"><i class="fa fa-file pictofixedwidth centerimp"></i>' . $langs->trans('generateSampleInvoice') . '</a><br>';
					}
					$item->fieldOverride .= '<a class="reposition" href="' . $_SERVER["PHP_SELF"] . "?action=makesend" . $prefix . "sampleinvoice&token=" . newToken() . '"><i class="fa fa-file pictofixedwidth centerimp"></i>' . $langs->trans('generateSendSampleInvoice') . '</a><br>';
				}
			}

			// Check your ID in E-Invoice Annuary
			$showannuary = 0;
			$idtocheck = '';
			if ($mysoc->country_code == 'FR') {
				$showannuary++;

				$item->fieldOverride .= '<i class="fa fa-list-alt pictofixedwidth centerimp"></i>'.$langs->trans('CheckYourIDInEInvoiceAnnuary');

				$einvoicing = new EInvoicing($this->db);
				$idtocheck = (string) $einvoicing->getSellerCommunicationURI(0);

				if (getDolGlobalString('EINVOICING_LIVE')) {
					$item->fieldOverride .= ': <a class="reposition" href="https://facturation.chorus-pro.gouv.fr/annuaire/#/" target="_blank">' . $langs->trans('FrenchGovAnnuary') . '</a>';
				}
			}
			if (!getDolGlobalString('EINVOICING_LIVE')) {
				if ($showannuary) {
					$item->fieldOverride .= ' - ';
				}
				$item->fieldOverride .= '<a class="reposition" href="https://test-directory.peppol.eu/public/locale-en_US/menuitem-search?q='.urlencode($idtocheck).'&mode=fr&env=sandbox" target="_blank">' . $langs->trans('PeppolTestAnnuary') . '</a>';
			}
		}
	}

	/**
	 * Validate configuration parameters before API calls.
	 *
	 * @param 	int		$mode 	0 check that user/pass is set, 1 check that api key is set
	 * @return 	bool 			True if configuration is valid.
	 */
	public function validateConfiguration($mode = 1)
	{
		global $langs;

		$error = array();
		if ($mode == 0) {
			if (empty($this->config['username'])) {
				$langs->loadLangs(array("main", "oauth"));
				$error[] = $langs->trans('ErrorFieldRequired', $langs->transnoentities('EINVOICING_CLIENT_ID'));
			}
			if (empty($this->config['password'])) {
				$langs->loadLangs(array("main", "oauth"));
				$error[] = $langs->trans('ErrorFieldRequired', $langs->transnoentities('EINVOICING_CLIENT_SECRET'));
			}
		} elseif ($mode == 1) {
			if (empty($this->config['api_key'])) {
				$error[] = $langs->trans('ApiKeyIsRequired');
			}
		}

		if (!empty($error)) {
			$this->errors[] = $langs->trans("CheckPdpConfiguration");
			$this->errors = array_merge($this->errors, $error);
		}
		return empty($error);
	}

	/**
	 * Get access token from OAUth server and save it into database.
	 * This erase old token.
	 *
	 * @return string|null 		Access token or null on failure.
	 * @see getTokenData() to get current token in memory (loaded by fetchOAuthTokenDB in constructor)
	 */
	public function getAccessToken()
	{
		global $langs;

		$extraHeaders = [];

		// OAuth2 client_credentials — RFC 6749, application/x-www-form-urlencoded
		// Replace old POST /v1/token (JSON username/password) disabled since v1.2.6 (2026-07).
		// grant_type is REQUIRED by RFC 6749 section 4.4.2 whatever the client authentication method is:
		// without it the endpoint answers 400 and no token is ever issued. Credentials go in the body, or in
		// an HTTP Basic header (RFC 6749 section 2.3.1) with ESALINK_AUTHENT_USING_BASIC_AUTH, never in both.
		if (getDolGlobalString('ESALINK_AUTHENT_USING_BASIC_AUTH')) {
			$param = http_build_query(array(
				'grant_type'    => 'client_credentials',
			));
			$extraHeaders["Authorization"] = "Basic ".base64_encode(urlencode($this->config['username']).":".urlencode($this->config['password']));
		} else {
			$param = http_build_query(array(
				'grant_type'    => 'client_credentials',
				'client_id'     => $this->config['username'],
				'client_secret' => $this->config['password'],
			));
		}

		$extraHeaders['Content-Type'] ='application/x-www-form-urlencoded';

		$response = $this->callApi("oauth2/token", "POSTALREADYFORMATED", $param, $extraHeaders, 'get_access_token');

		$status_code = $response['status_code'];
		$body = $response['response'];

		if ($status_code == 200 && isset($body['access_token']) && isset($body['expires_in'])) {
			$this->saveOAuthTokenDB($body['access_token'], $body['refresh_token'] ?? '', $body['expires_in']);

			return $body['access_token'];
		} else {
			$this->errors[] = $langs->trans("FailedToRetrieveAccessToken");
			return null;
		}
	}

	/**
	 * Refresh access token.
	 *
	 * @return string|null New access token or null on failure.
	 */
	public function refreshAccessToken()
	{
		// Get access token from OAUth server and save it into database.
		$result = $this->getAccessToken();

		return $result;
	}

	/**
	 * Perform a health check call for PDP provider.
	 *
	 * @return array Contains 'status' (bool) and 'message' (string)
	 */
	public function checkHealth()
	{
		global $langs;

		$response = $this->callApi("healthcheck", "GET", false, [], 'healthcheck');		// This include the refresh of token
		$returnarray = array();

		$nameOfAccessPoint = getDolGlobalString('EINVOICING_PDP');
		$nameOfAccessPoint = preg_replace('/ViaPartner/', '', $nameOfAccessPoint);

		if ($response['status_code'] === 200) {
			$returnarray['status_code'] = true;
			$returnarray['message'] = $langs->trans('APApiReachable', $nameOfAccessPoint);
		} else {
			$returnarray['status_code'] = false;
			$returnarray['message'] = $langs->trans('APApiNotReachable', $nameOfAccessPoint) . ' (HTTP ' . ($response['status_code'] ?? 'N/A') . ')' . (!empty($response['response']) ? ' - ' . $response['response'] : '');
		}

		return $returnarray;
	}

	/**
	 * Retrieve and format remote account/company information from the provider and peppol directory, if available,
	 * for display to the user.
	 *
	 * @return array{status_code:int,message:string}
	 */
	public function getRemoteInfo()
	{
		return array(
			'status_code' => -1,
			'message' => 'Not yet implemented',
		);
	}

	/**
	 * Validate an electronic invoice file using the provider validation service.
	 *
	 * @param 	int 	$idinvoice 	ID of the invoice to validate
	 * @param 	string 	$filePath 	Path to the invoice file to validate
	 * @return 	array|string 		Validation result or error message.
	 */
	public function validateEInvoiceFile($idinvoice, $filePath)
	{
		global $langs;

		if (empty($this->config['has_validator']) || $this->config['has_validator'] != 1) {
			return array('res' => -1, 'message' => $langs->trans('NoAvailableValidatorforThisAccessPoint'));
		}

		return array('res' => 0, 'message' => $langs->trans('Skipped'));
	}

	/**
	 * Send an electronic invoice.
	 *
	 * This function send an invoice to PDP
	 *
	 * @param	Facture		$object 	Invoice object
	 * @return 	false|string|array{res:int<-1,-1>,message:string}   flowId if the invoice was successfully sent, false otherwise.
	 */
	public function sendInvoice($object)
	{
		global $conf, $langs;

		$outputLog = array(); // Feedback to display

		$filename = dol_sanitizeFileName($object->ref);
		$filedir = $conf->invoice->multidir_output[$object->entity ?? $conf->entity] . '/' . dol_sanitizeFileName($object->ref);
		switch (getDolGlobalString('EINVOICING_PROTOCOL')) {
			case 'FACTURX':
				$suffix = '_facturx.pdf';
				$mime_type = 'application/pdf';
				$flowSyntax = 'Factur-X';
				break;
			case 'CII':
				$suffix = '_cii.xml';
				$mime_type = 'application/xml';
				$flowSyntax = 'CII';
				break;
			default:
				$suffix = '_facturx.pdf';
				$mime_type = 'application/pdf';
				$flowSyntax = 'Factur-X';
		}
		$invoice_path = $filedir . '/' . $filename . $suffix;

		if (!file_exists($invoice_path)) {
			$this->errors[] = "Electronic Invoice file not found";
			return false;
		}

		$file_info = pathinfo($invoice_path);

		// UUID used to correlate our logs with the ones of the Access Point. The flow API declares
		// Request-Id as a header, not as a query parameter: put in the URL it is simply ignored, so
		// the correlation it exists for was never established. callApi() records it in the call log.
		$uuid = $this->generateUuidV4();

		// Format AP resource Url
		$resource = 'flows';

		// Extra headers
		$extraHeaders = [
			'Content-Type' => 'multipart/form-data',
			'Request-Id' => $uuid,
		];

		// Params
		// The profile is declared from what the document actually carries, never hardcoded, so the
		// declaration cannot contradict the transmitted file (issue #395). Empty means "omit it",
		// which both platforms accept and which is the only correct answer for a profile that has
		// no AFNOR flowProfile of its own.
		$flowInfo = [
			"flowSyntax" => $flowSyntax,			// CII or Factur-X
			"trackingId" => $object->ref,
			"name" => "Invoice_" . $object->ref,
			"sha256" => hash_file('sha256', $invoice_path)
		];

		$flowProfile = $this->resolveFlowProfile($invoice_path);
		if ($flowProfile !== '') {
			$flowInfo = array("flowProfile" => $flowProfile) + $flowInfo;
		}

		$params = [
			'flowInfo' => json_encode($flowInfo),
			'file' => new CURLFile($invoice_path, $mime_type, basename($invoice_path))
		];

		$response = $this->callApi($resource, "POSTALREADYFORMATED", $params, $extraHeaders, 'send_invoice');

		if ($response['status_code'] == 200 || $response['status_code'] == 202) {
			$flowId = $response['response']['flowId'] ?? '';
			$callId = $response['id'];
			$callRef = $response['call_id'];

			/**
			 * Document validation on the PDP side may take some time, so we initially set the status to
			 * "Sent", then make an additional call to retrieve the acknowledgment information:
			 * - If the validation is successful, we update the status to "Sent (awaiting acknowledgment)".
			 * - If the PDP validation fails, we set the status to "Error".
			 * If no response is available yet, we wait for the next synchronization.
			 **/

			// Update einvoice status with awaiting validation
			$einvoicing = new EInvoicing($this->db);
			$einvoicing->insertOrUpdateExtLink($object->id, $object->element, $flowId, EInvoicing::STATUS_AWAITING_VALIDATION, $object->ref);

			// Call the API to retrieve flow details and check the validation status.
			// A short delay is applied to allow the PDP time to process the document.
			$resource = 'flows/' . $flowId;
			$urlparams = array(
				'docType' => 'Metadata',
			);
			$resource .= '?' . http_build_query($urlparams);
			$response = $this->callApi(
				$resource,
				"GET",
				false,
				['Accept' => 'application/octet-stream'],
				'check_invoice_validation'
			);

			if ($response['status_code'] != 200 && $response['status_code'] != 202) {
				return array('res' => -1, 'message' => "FlowId: " . $flowId . " - Failed to retrieve flow details");
			}

			// Process flow data
			$flowData = array();
			try {
				$flowData = json_decode($response['response'], true);
			} catch (Exception $e) {
				return array('res' => -1, 'message' => "FlowId: " . $flowId . " - Failed to parse the json answer");
			}

			// Update einvoice status with received validation result
			$syncStatus = $einvoicing::STATUS_AWAITING_VALIDATION;
			$ack_statusLabel = $flowData['acknowledgement']['status'] ?? '';
			if ($ack_statusLabel) {
				$syncStatus = $einvoicing->getDolibarrStatusCodeFromPdpLabel($ack_statusLabel);
			}
			$syncRef = $flowData['trackingId'] ?? '';
			$syncComment = $flowData['acknowledgement']['details'][0]['reasonMessage'] ?? '';
			$einvoicing->insertOrUpdateExtLink($object->id, $object->element, $flowId, $syncStatus, $syncRef, $syncComment);

			// Log an event in the invoice timeline
			$eventLabel = "EINVOICING - Status: " . $ack_statusLabel;
			$eventLabel .= " - " . $callRef;

			$eventMessage = "EINVOICING - Status: " . $ack_statusLabel . (!empty($syncComment) ? " - " . $syncComment : "");
			$eventMessage .= "\nFlowID=" . $flowId;
			$eventMessage .= "\nCallID " . $callRef;

			$resLogEvent = $this->addEvent('STATUS', $eventLabel, $eventMessage, $object);
			if ($resLogEvent < 0) {
				dol_syslog(__METHOD__ . " Failed to log event for flowId: {$flowId}", LOG_WARNING);
			}

			return $flowId;
		} else {
			$this->error = $langs->trans("ErrorSendingInvoiceToPDP");
			$this->error .= '<br>HTTP ' . $response['status_code'];
			if (!empty($response['errorCode'])) {
				$this->error .= ' - ' . $response['errorCode'] . (empty($response['errorMessage']) ? '' : ' - ' . $response['errorMessage']);
			}
			if (!empty($response['curl_error_no'])) {
				$this->error .= ' - Curl error ' . $response['curl_error_no'] . (empty($response['curl_error_msg']) ? '' : ' - ' . $response['curl_error_msg']);
			}
			$this->errors[] = $this->error;
			return false;
		}
	}

	/**
	 * Send a sample electronic invoice for testing purposes.
	 * This function generates a sample invoice and sends it to PDP
	 *
	 * @param 	int<0,1>		$onlymake		1=to only make the sample
	 * @return 	string[]|0	 					Array of messages if the invoice was successfully sent, 0 otherwise.
	 */
	public function sendSampleInvoice($onlymake = 0)
	{
		global $langs;

		$outputLog = array(); // Feedback to display
		$invoice_path = null;

		// Generate sample invoice
		$einvoicing = new EInvoicing($this->db);

		try {
			if (empty($this->exchangeProtocol)) {
				$exchangeProtocolConf = getDolGlobalString('EINVOICING_PROTOCOL');
				$ProtocolManager = new ProtocolManager($this->db);
				$this->exchangeProtocol = $ProtocolManager->getProtocol($exchangeProtocolConf);
			}

			if ((float) DOL_VERSION < 24.0) {
				$resarray = $this->exchangeProtocol->generateSampleInvoiceOld($einvoicing);
			} else {
				$resarray = $this->exchangeProtocol->generateSampleInvoice($einvoicing);
			}
			if ($resarray === -1) {
				$this->errors[] = $this->exchangeProtocol->error;
				return 0;
			}
			$invoice_path = (string) $resarray['path'];
			$ref = $resarray['ref'];
		} catch (Exception $e) {
			$this->errors[] = $e->getMessage();
			return 0;
		}

		if (empty($ref) || empty($invoice_path)) {
			$this->errors[] = 'Failed to generate the sample invoice';
			return 0;
		}

		// invoice_path is something like "/.../documents/einvoicing/temp/..." or "/.../documents/facture/temp/..."

		$invoice_path = (string) $invoice_path;  // Phan workaround
		if ($invoice_path) {
			$outputLog[] = "Sample invoice generated successfully.";
		}


		// Stop here if we want just generation
		if ($onlymake) {
			return $outputLog;
		}


		$file_info = pathinfo($invoice_path);
		$fileext = $file_info['extension'] ?? ''; // Should be "pdf" or "xml" depending on the protocol
		if (strtolower($fileext) == 'pdf') {
			$mime_type = 'application/pdf';
		} else {
			$mime_type = 'text/xml';
		}

		// Format PDP resource Url
		/* The URL must be like : https://ppd.hubtimize.fr/api/orchestrator/v1/flows?Request-Id={UUID}
		$uuid = $this->generateUuidV4(); // UUID used to correlate logs between Dolibarr and PDP
		$resource = 'flows';
		$urlparams = array(
			'Request-Id' => $uuid,
		);
		$resource .= '?' . http_build_query($urlparams);
		*/

		// Extra headers
		$extraHeaders = [
			'Content-Type' => 'multipart/form-data'
		];

		// Params
		$params = [
			'flowInfo' => json_encode([
				"trackingId" => $ref,
				"name" => "Invoice_" . $ref,
				"flowSyntax" => "Factur-X",
				"flowProfile" => "CIUS",
				"sha256" => hash_file('sha256', $invoice_path)
			]),
			'file' => new CURLFile($invoice_path, $mime_type, basename($invoice_path))
		];

		$response = $this->callApi("flows", "POSTALREADYFORMATED", $params, $extraHeaders, 'send_sample_invoice');


		if ($response['status_code'] == 200 || $response['status_code'] == 202) {
			if (!is_array($response['response']) || empty($response['response']['flowId'])) {
				// Accepted, but the answer is not the JSON body the platform announces. Everything below
				// is built on that flow id, so stop here rather than call 'flows/' with nothing.
				$this->errors[] = "Sample invoice sent but the platform returned no flow id.";
				return 0;
			}
			$flowId = $response['response']['flowId'];
			$outputLog[] = "Sample invoice sent successfully.";

			// Try to retrieve flow using callback information
			$resource = 'flows/' . $flowId;
			$urlparams = array(
				'docType' => 'Original',
			);
			$resource .= '?' . http_build_query($urlparams);

			$response = $this->callApi(
				$resource,
				"GET",
				false,
				['Accept' => 'application/octet-stream'],
				'retrieve_sample_invoice'
			);

			if ($response['status_code'] == 200 || $response['status_code'] == 202) {
				include_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
				$tmpobject = new Facture($this->db);
				$output_path = getMultidirTemp($tmpobject, 'einvoicing') . '/test_retrieved_invoice.' . $fileext;

				file_put_contents($output_path, $response['response']);

				$outputLog[] = "Sample invoice retrieved successfully.";

				return $outputLog;
			} else {
				$this->errors[] = "Failed to retrieve sample invoice.";
				return 0;
			}
		} else {
			$errormsg = $langs->trans("ErrorSendingInvoiceToPDP");
			$errormsg .= '<br>HTTP ' . $response['status_code'];
			if (!empty($response['errorCode'])) {
				$errormsg .= ' - ' . $response['errorCode'] . (empty($response['errorMessage']) ? '' : ' - ' . $response['errorMessage']);
			}
			if (!empty($response['curl_error_no'])) {
				$errormsg .= ' - Curl error ' . $response['curl_error_no'] . (empty($response['curl_error_msg']) ? '' : ' - ' . $response['curl_error_msg']);
			}
			$this->error = $errormsg;
			$this->errors[] = $errormsg;
			return 0;
		}
	}

	/**
	 * Call the provider API.
	 *
	 * @param string 						$resource 	    Resource relative URL ('token', 'healthcheck', 'Flows', or others)
	 * @param 'POST'|'GET'|'HEAD'|'PUT'|'PUTALREADYFORMATED'|'POSTALREADYFORMATED'|'DELETE' $method         HTTP method (dolibarr's types)
	 * @param string|false|array<string,mixed> 	$params 	    Body of the request: a JSON encoded string, or an array carrying a CURLFile for a multipart upload. False when there is none.
	 * @param array<string, string>         $extraHeaders   Optional additional headers
	 * @param string|null                   $callType       Functional type of the API call for logging purposes (e.g., 'sync_flows', 'send_invoice')
	 *
	 * @return array{status_code:int,response:null|string|array<string,mixed>|mixed,errorCode?:string,errorMessage?:string,id?:int,call_id?:?string,curl_error_no?:int,curl_error_msg?:string}
	 */
	public function callApi($resource, $method, $params = false, $extraHeaders = [], $callType = '')
	{
		global $conf, $user;

		// Validate configuration
		if (!$this->validateConfiguration(($callType == 'get_access_token') ? 0 : 1)) {
			return array('status_code' => 400, 'response' => $this->errors);
		}

		require_once DOL_DOCUMENT_ROOT . '/core/lib/geturl.lib.php';

		$url = $this->getApiUrl(($callType == 'get_access_token') ? 'auth' : 'api') . $resource;
		if (strpos($resource, 'afnor-directory/v1/') === 0) {
			// Standardized AFNOR Directory Service (XP Z12-013). The 'afnor-directory/v1/' prefix is only a
			// routing marker used by AbstractPDPProvider::checkRecipientDirectory(): this platform serves the
			// directory from its regular versioned base, so the marker is stripped instead of appended (the
			// prefixed path itself answers 403 here).
			$url = $this->getApiUrl('afnor_directory') . substr($resource, strlen('afnor-directory/v1/'));
		}

		$httpheader = array(
			'hubtimize-api-key: ' . $this->config['api_key']
		);

		if (!isset($extraHeaders['Content-Type'])) {
			$httpheader[] = 'Content-Type: application/json';
			$httpheader[] = 'Accept: application/json';
		}

		foreach ($extraHeaders as $key => $value) {
			$httpheader[] = $key . ': ' . $value;
		}

		// check or get access token
		if ($callType != 'get_access_token') {
			if (!empty($this->tokenData['token'])) {
				if ($this->isTokenExpired()) {
					$this->refreshAccessToken(); // This will fill again $this->tokenData['token'] and save it in database
				}
			} else {
				$this->getAccessToken(); // This will fill again $this->tokenData['token'] and save it in database
			}
		}

		// Add Authorization header if we have a token
		if (!empty($this->tokenData['token']) && $callType != 'get_access_token') {
			$httpheader[] = 'Authorization: Bearer ' . $this->tokenData['token'];
		}

		/*
		if (is_array($params)){
			$params = http_build_query($params);
		}*/

		$response = getURLContent($url, $method, $params, 1, $httpheader, array('http', 'https'), 0, -1, 0, 0, array(), '_einvoicing');

		$status_code = $response['http_code'] ?? 0;
		$content = $response['content'] ?? '';
		$body = 'Error';

		if ($status_code == 200 || $status_code == 202) {
			$body = $content;
			if (!isset($extraHeaders['Accept'])) { // Json if default format
				$body = json_decode($body, true);
			}
			$returnarray = array(
				'status_code' => $status_code,
				'response' => $body
			);
		} else {
			$returnarray = array(
				'status_code' => $status_code,
				'response' => 'Error ' . $status_code . ' - ' . (string) $content
			);
			if (!empty($response['curl_error_no'])) {
				$returnarray['curl_error_no'] = $response['curl_error_no'];
			}
			if (!empty($response['curl_error_msg'])) {
				$returnarray['curl_error_msg'] = $response['curl_error_msg'];
			}
			// An error body is not always the {errorCode, errorMessage} pair this expects: a plain JSON
			// string decodes into a string, and indexing that is a fatal on PHP 8, not a warning.
			// Each key is set only when the body really carries it: callers tell "no message" from
			// "empty message" with isset(), and would otherwise report an empty error instead of
			// falling back on the HTTP code.
			$contentarray = json_decode((string) $content, true);
			if (is_array($contentarray)) {
				if (isset($contentarray['errorCode'])) {
					$returnarray['errorCode'] = (string) $contentarray['errorCode'];
				}
				if (isset($contentarray['errorMessage'])) {
					$returnarray['errorMessage'] = (string) $contentarray['errorMessage'];
				}
			}
		}

		// Log the API call through an independent connection so the trace survives a
		// rollback of the caller's transaction on error (see logCall(), issue #291).
		$logged = $this->logCall($callType, $resource, $method, $params, $returnarray['response'], $returnarray['status_code'], (string) ($extraHeaders['Request-Id'] ?? ''));
		if ($logged !== null) {
			$returnarray['id'] = $logged['id'];
			$returnarray['call_id'] = $logged['call_id'];
		}

		return $returnarray;
	}

	/**
	 * Synchronize flows with Access Point.
	 *
	 * TODO Code very similar with syncFlows of other providers
	 *
	 * @param   int   $syncFromDate     Timestamp from which to start synchronization. If 0, begins from epoch (1970-01-01).
	 * @param   int   $limit            Maximum number of flows to synchronize. 0 means no limit.
	 * @return 	bool|array{res:int, messages:string[], totalFlows?:?int, alreadyExist?:int, syncedFlows?:int, batchlimit?:int, actions?:array<string,array{actionurl:string,actioncode:string,action:string,businessmessage:string}>, details?:string[], errors?:string[]} 	True on success, false on failure along with messages, details for debugging, the errors that aborted the run, and suggested optional actions.
	 */
	public function syncFlows($syncFromDate = 0, $limit = 0)
	{
		global $db, $langs, $user;
		global $form;

		if (!is_object($form)) {
			require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
			$form = new Form($db);
		}

		// Start the run with a clean "last unprocessed invoice" diagnostic; failed flows re-create it.
		$this->clearIncomingDiagnosticFiles();

		$results_messages = array();	// result message (technical error)
		$error_messages = array();		// subset of the above holding only what made the run fail
		$actions = array();				// business message (manual action to do)

		$resource = 'flows/search';
		// Correlation id, sent as the Request-Id header of the call below and recorded in the call log.
		$uuid = $this->generateUuidV4();

		//self::$EINVOICING_LAST_IMPORT_KEY = $uuid;
		self::$EINVOICING_LAST_IMPORT_KEY = dol_print_date(dol_now(), 'dayhourlog');

		// Calculate dateafter
		if ($syncFromDate > 0) {
			$dateafter = $syncFromDate;
		} else {
			$dateafter = dol_mktime(0, 0, 0, 1, 1, 1970, 'gmt');
		}

		// First call to get a total count of flows to sync
		$params = array(
			'where' => array(
				'updatedAfter' => dol_print_date($dateafter, '%Y-%m-%dT%H:%M:%S.000Z', 'gmt')
			)
		);

		dol_syslog(__METHOD__ . " syncFlows start from " . dol_print_date($dateafter, 'standard') . " limit " . $limit, LOG_DEBUG);
		dol_syslog(__METHOD__ . " syncFlows start from " . dol_print_date($dateafter, 'standard') . " limit " . $limit, LOG_DEBUG, 0, "_einvoicing");

		// If limit is 0, we first need to get the total number of flows to sync because AP set a default limit of 25 if not specified
		if ($limit == 0) {
			$jsonparams = json_encode($params);
			$response = $this->callApi($resource, "POST", $jsonparams, array('Request-Id' => $uuid));

			$totalFlows = 0;
			if ($response['status_code'] != 200) {
				$errormessage = "Failed to retrieve flows for synchronization.";
				$this->errors[] = $errormessage;
				$results_messages[] = $errormessage;
				$error_messages[] = $errormessage;
				return array('res' => 0, 'messages' => $results_messages, 'errors' => $error_messages);
			}

			$totalFlows = $response['response']['total'] ?? 0;
			$limit = $totalFlows;

			if ($limit == 0) {
				dol_syslog(__METHOD__ . " No flows to synchronize.", LOG_DEBUG);
				dol_syslog(__METHOD__ . " No flows to synchronize.", LOG_DEBUG, 0, "_einvoicing");

				$results_messages[] = "No flows to synchronize.";
				return array('res' => 1, 'messages' => $results_messages);
			}

			dol_syslog(__METHOD__ . " Total flows to synchronize: " . $totalFlows, LOG_DEBUG);
			dol_syslog(__METHOD__ . " Total flows to synchronize: " . $totalFlows, LOG_DEBUG, 0, "_einvoicing");
		}


		// Make a call to get all flows
		if ($limit) {
			$params['limit'] = $limit;
		}
		$jsonparams = json_encode($params);
		$response = $this->callApi($resource, "POST", $jsonparams, array('Request-Id' => $uuid), "synchronization");	// This will also create the Call entry

		if ($response['status_code'] != 200) {
			$errormessage = "Failed to retrieve flows for synchronization." . ' (HTTP ' . $response['status_code'] . ')';
			$this->errors[] = $errormessage;
			$results_messages[] = $errormessage;
			$error_messages[] = $errormessage;

			dol_syslog(__METHOD__ . " Failed to retrieve the list of flows for synchronization.", LOG_DEBUG, 0, "_einvoicing");
			return array('res' => 0, 'messages' => $results_messages, 'errors' => $error_messages);
		}

		// Some AP returns nb of lines into "total", others returns into "limit"
		$totalFlows = ($response['response']['total'] ?? null);		// If not defined (not into the spec), we set it to null
		$limitFlows = ($response['response']['limit'] ?? 0);

		$batchlimit = $limit; // Set batch limit for logging purposes
		$limit = (($limit > 0 && $limitFlows > 0) ? min($limit, $limitFlows) : ($limitFlows ? $limitFlows : $limit));

		if ($limit == 0) {
			dol_syslog(__METHOD__ . " No flows to synchronize.", LOG_DEBUG);
			dol_syslog(__METHOD__ . " No flows to synchronize.", LOG_DEBUG, 0, "_einvoicing");

			$results_messages[] = "No flows to synchronize.";
			return array('res' => 1, 'messages' => $results_messages);
		}

		$results = $response['response']['results'] ?? array();

		// Since AP may not return flows in the order they want (by updatedAt ASC), we sort them here.
		// On the sub-second key, because the cursor moves along it.
		dol_syslog(__METHOD__ . " Sort the flows per updatedAt", LOG_DEBUG, 0, "_einvoicing");
		usort($results, function ($a, $b) {
			return strcmp(self::updatedAtSortKey($a['updatedAt'] ?? ''), self::updatedAtSortKey($b['updatedAt'] ?? ''));
		});

		// Clean already processed flows from the list
		$alreadyProcessedFlowIds = [];
		$flowIds = array_column($results, 'flowId');
		$sanitizedFlowIds = array();
		foreach ($flowIds as $flowId) {
			$sanitizedFlowIds[] = "'" . $db->escape($flowId) . "'";
		}
		if (count($sanitizedFlowIds)) {
			$sql = "SELECT flow_id FROM " . MAIN_DB_PREFIX . "einvoicing_document";
			$sql .= " WHERE flow_id IN (" . implode(',', $sanitizedFlowIds) . ")";
			$resql = $db->query($sql);
			if ($resql) {
				while ($obj = $db->fetch_object($resql)) {
					$alreadyProcessedFlowIds[$obj->flow_id] = $obj->flow_id;
				}
			} else {
				$errormessage = "Failed to retrieve from database the list of flows already processed. ".$this->db->lasterror();
				$this->errors[] = $errormessage;
				$results_messages[] = $errormessage;
				$error_messages[] = $errormessage;

				dol_syslog(__METHOD__ . " Failed to retrieve flows already processed among the list of flows received. ".$this->db->lasterror(), LOG_DEBUG, 0, "_einvoicing");
				return array('res' => 0, 'messages' => $results_messages, 'errors' => $error_messages);
			}
		}

		// Update totalFlows after filtering
		// $totalFlows = count($response['response']['results']); // TODO : VERIFY IF NEEDED
		$error = 0;
		$alreadyExist = 0;
		$syncedFlows = 0;
		$postponedFlows = 0;	// Flows left unread on purpose, retried on the next run (see 'postponeflow')

		// Call ID for logging purposes
		$call_id = $response['call_id'] ?? null;

		//$lastsuccessfullSyncronizedFlow = null;

		// Loop on each flow received in list
		$i = 0;
		foreach ($response['response']['results'] ?? [] as $flow) {
			$i++;
			if (in_array($flow['flowId'], $alreadyProcessedFlowIds)) {
				dol_syslog(__METHOD__ . " #" . $i . " Flow " . $flow['flowId'] . " already processed, discard it.", LOG_DEBUG, 0, "_einvoicing");
				$alreadyExist++;
				continue;
			}

			$rescode = '';
			try {
				// Process flow

				dol_syslog(__METHOD__ . " #" . $i . " Process flow " . $flow['flowId'], LOG_DEBUG, 0, "_einvoicing");

				// Do a unitary sync of flow $flow['flowId'] instead the global transaction $call_id
				$res = $this->syncFlow($flow['flowId'], $call_id);

				// If res < 0, rollback
				if ($res['res'] < 0) {
					if (getDolGlobalInt('EINVOICING_ENABLE_POSTPONE_FLOWS') && !empty($res['postponeflow'])) {
						// This flow could not be read, but nothing was stored for it: it stays pending and
						// the next synchronization will try it again, so no invoice is lost. Report it with
						// the action to do and carry on, instead of stalling this batch - and every flow
						// behind it - on a problem that has to be fixed on the access point side anyway.
						$actions[$res['actioncode']] = array(
							'actionurl' => ($res['actionurl'] ?? ''),
							'actioncode' => $res['actioncode'],
							'action' => $res['action'],
							// A postponed flow says itself what happened when it can: only the caller knows
							// whether the document was unreadable or referenced an invoice that is missing.
							'businessmessage' => (empty($res['businessmessage']) ? $langs->trans("CantReadTheDocumentOfTheImportedInvoice", $flow['flowId']) : $res['businessmessage'])
								. $form->textwithpicto('', "ERROR_SYNCFLOW - Failed to synchronize flow " . $flow['flowId'] . ": " . $res['message'], 1, 'help', '', 0, 2, 'help')
						);

						dol_syslog(__METHOD__ . " Flow " . $flow['flowId'] . " postponed: " . $res['message'], LOG_WARNING, 0, "_einvoicing");
						$results_messages[] = "Flow " . dol_escape_htmltag((string) $flow['flowId']) . " postponed, it will be retried on the next synchronization: " . $res['message'];

						$postponedFlows++;
						continue;
					}

					if (isset($res['action']) && $res['action'] != '') {	// Save business errors if it is
						$rescode = $res['actioncode'] ?? '0';
						// Set the result code and label into array $actions.
						$actions[$rescode] = array(
							'actionurl' => $res['actionurl'],
							'actioncode' => ($res['actioncode'] ?? '0'),
							'action' => $res['action'],
							'actiondata' => $res['actiondata'] ?? array(),
						);
						// Some error return directly the business action to do.
						if (!empty($res['businessmessage'])) {
							$actions[$rescode]['businessmessage'] = $res['businessmessage'] . $form->textwithpicto('', "ERROR_SYNCFLOW - Failed to synchronize flow " . $flow['flowId'] . ": " . $res['message'], 1, 'help', '', 0, 2, 'help');
						}

						// Complete the $actions array with the Business error message for common known cases.
						if ($rescode == 'THIRDPARTY_NOT_FOUND') {
							$infostring = '';
							foreach ($res['actiondata'] ?? [] as $datakey => $dataval) {
								if ($datakey && $dataval && in_array($datakey, array('name', 'email', 'vatnumber', 'idprof1'))) {
									$transdatakey = ucfirst($datakey);
									if ($transdatakey == 'Vatnumber') {
										$transdatakey = 'VATIntraShort';
									}
									if ($transdatakey == 'Idprof1') {
										$transdatakey = 'ProfId1';
									}
									$infostring .= ($infostring ? ', ' : '');
									$infostring .= $langs->transnoentitiesnoconv($transdatakey);
									$infostring .= ': '.$dataval;
								}
							}
							$actions[$rescode]['businessmessage'] = $langs->trans("CantFindThirdpartyFromTheImportedInvoice", $infostring);
							// Add technical message in tooltip on the picto
							$actions[$rescode]['businessmessage'] .= $form->textwithpicto('', "ERROR_SYNCFLOW - Failed to synchronize flow " . $flow['flowId'] . ": " . $res['message'], 1, 'help', '', 0, 2, 'help');
						}
						if ($rescode == 'PRODUCT_NOT_FOUND') {
							$langs->load("products");
							$infostring = '';
							if (!empty($res['actiondata']['socid'])) {
								$socid = $res['actiondata']['socid'];
								$tmpthirdparty = new Societe($db);
								$tmpthirdparty->fetch($socid);
								$infostring .= $langs->transnoentitiesnoconv("Supplier") . ': ' . $tmpthirdparty->name;
							}
							foreach ($res['actiondata'] ?? [] as $datakey => $dataval) {
								if ($datakey && $dataval && in_array($datakey, array('supplierref', 'label'))) {
									$transdatakey = ucfirst($datakey);
									if ($transdatakey == 'Supplierref') {
										$transdatakey = 'SupplierRef';
									}
									if ($transdatakey == 'Label') {
										$transdatakey = 'ProductLabel';
									}
									$infostring .= ($infostring ? ', ' : '');
									$infostring .= $langs->transnoentitiesnoconv($transdatakey);
									$infostring .= ': '.$dataval;
								}
							}
							$actions[$rescode]['businessmessage'] = $langs->trans("CantFindProductFromTheImportedInvoice", $infostring);
							// Add technical message in tooltip on the picto
							$actions[$rescode]['businessmessage'] .= $form->textwithpicto('', "ERROR_SYNCFLOW - Failed to synchronize flow " . $flow['flowId'] . ": " . $res['message'], 1, 'help', '', 0, 2, 'help');
						}
					}
					dol_syslog(__METHOD__ . " Failed to synchronize flow " . $flow['flowId'] . ": " . $res['message'], LOG_DEBUG, 0, "_einvoicing");
					$errormessage = "ERROR_SYNCFLOW - Failed to synchronize flow " . dol_escape_htmltag((string) $flow['flowId']) . ": " . $res['message'];
					$results_messages[] = $errormessage;
					$error_messages[] = $errormessage;

					$error++;
				}

				// If res == 0, commit but count it as already existed
				if ($res['res'] == 0) {
					$results_messages[] = "<span class=\"opacitylow\">Flow " . dol_escape_htmltag((string) $flow['flowId']) . " skipped: " . $res['message'] . "</span>";
					$alreadyExist++;
					//$lastsuccessfullSyncronizedFlow = $flow['flowId'];
				}

				// If res == 1, commit and count as synced
				if ($res['res'] > 0) {
					$syncedFlows++;
					//$lastsuccessfullSyncronizedFlow = $flow['flowId'];
				}
			} catch (Exception $e) {
				$errormessage = "Exception occurred while synchronizing flow " . dol_escape_htmltag((string) $flow['flowId']) . ": " . dol_escape_htmltag($e->getMessage());
				$results_messages[] = $errormessage;
				$error_messages[] = $errormessage;
				$error++;
			}

			if ($error > 0) {
				if (in_array($rescode, array('THIRDPARTY_NOT_FOUND','PRODUCT_NOT_FOUND'))) {
					$results_messages[] = "Aborting synchronization due to a business error. There is a manual action to do.";
				} else {
					$results_messages[] = "Aborting synchronization due to errors.";
				}
				break;
			}
		}


		$globalres = ($error > 0 ? -1 : 1);

		$globalresultmessage = ($globalres == 1) ? $langs->trans("SyncCompletedSuccessfuly") . ($batchlimit > 0 ? ' <span class="opacitylow">(' . $langs->trans("maxNumberToProcess") . ': ' . $batchlimit . ")</span>" : "") : ($langs->trans("SyncAborted", $i, $limit, ($flow['flowId'] ?? 'N/A')));

		dol_syslog(__METHOD__ . " syncFlows end : " . $globalresultmessage, LOG_DEBUG, 0, "_einvoicing");


		$messages = array();
		$messages[] = $globalresultmessage;
		if ($globalres == 1) {
			if (!is_null($totalFlows)) {
				$messages[] = $langs->trans("TotalToSync") . ": <b>" . $totalFlows . "</b>";
			}
		}
		$messages[] = $langs->trans("TotalSkippedSync") . ": <b>" . $alreadyExist . "</b> - " . $langs->trans("TotalNewSync") . ": <b>" . $syncedFlows . "</b>";
		if ($postponedFlows > 0) {
			// Counted apart from the skipped ones: those flows were not stored, they come back next run
			$messages[] = $langs->trans("TotalPostponedSync") . ": <b>" . $postponedFlows . "</b>";
		}

		// Processing result that will be saved in DB
		$processingResult = '';
		if (!empty($results_messages)) {
			$processingResult .= implode("<br>----------------------<br>", $results_messages);
		}
		$processingResult .= "<br>----------------------<br>" . implode("<br>", $messages);
		$processingResult = "Processing result:<br>" . $processingResult;
		// The recap grows with the number of flows: keep it inside its column, a refused UPDATE loses it whole
		$processingResult = $this->makeStorableDebugPayload($processingResult);

		// Save sync recap (only when this sync is attached to a Call row; otherwise $sql would be undefined/stale)
		if ($call_id) {
			$sql = "UPDATE " . MAIN_DB_PREFIX . "einvoicing_call";
			$sql .= " SET totalflow = " . (is_null($totalFlows) ? "null" : ((int) $totalFlows)) . ",
                successflow = " . ((int) $syncedFlows) . ",
                skippedflow = " . ((int) $alreadyExist) . ",
                batchlimit = " . ((int) $batchlimit) . ",
                processing_result = '" . $db->escape($processingResult) . "',
                    fk_user_modif = " . ((int) $user->id) . "
            WHERE call_id = '" . $db->escape($call_id) . "'";
			$db->query($sql);
		}

		// Return result
		// 'actions' contains the action to do (in case of business error)
		// 'details' will contain all technical error (for Log)
		// 'errors' holds only what aborted the run, for a caller that has to report a cause
		return [
			'res' => $globalres,
			'messages' => $messages,
			'totalFlows' => $totalFlows,
			'alreadyExist' => $alreadyExist,
			'syncedFlows' => $syncedFlows,
			'batchlimit' => $batchlimit,
			'actions' => $actions,
			'details' => $results_messages,
			'errors' => $error_messages
		];
	}

	/**
	 * Comparable key for the updatedAt of a flow.
	 *
	 * The platform does not pad the fractional seconds to a fixed width - '.47288Z' and '.626638Z'
	 * both occur - so the raw strings cannot be compared to each other, and strtotime() drops the
	 * fraction entirely, which is precisely what the cursor needs. Padding it to six digits gives a
	 * key that sorts on the microsecond.
	 *
	 * @param	string	$updatedAt	Timestamp as returned by the platform
	 * @return	string				Key to sort and compare on
	 */
	private static function updatedAtSortKey($updatedAt)
	{
		$reg = array();
		if (!preg_match('/^([^.Z]+)(?:\.(\d+))?/', (string) $updatedAt, $reg)) {
			return (string) $updatedAt;
		}

		return $reg[1] . '.' . str_pad(substr(isset($reg[2]) ? $reg[2] : '', 0, 6), 6, '0');
	}

	/**
	 * Send status message of an invoice to PDP/PA
	 *
	 * @param mixed $object Invoice object (CustomerInvoice or SupplierInvoice)
	 * @param int $statusCode   Status code to send (see class constants for available codes)
	 * @param string $reasonCode Reason code to send (optional)
	 * @param array{amount?:float,breakdown?:array<array{vatrate:float,amount:float}>,reason?:string} $paymentData Amount (TTC) moved for status 212 (Encaissee), negative on a refund, with the reason of the cancellation (rules BR-FR-CDV-14, P1.17)
	 *
	 * @return array{res:int, message:string}       Returns array with 'res' (1 on success, -1 on failure) with a 'message'.
	 */
	public function sendStatusMessage($object, $statusCode, $reasonCode = '', $paymentData = array())
	{
		global $langs, $db;

		$res = 1;
		$message = '';

		if (!in_array($object->element, ['facture', 'invoice_supplier'])) {
			$res = -1;
			$message = 'SendStatusMessage Not does not support this object type: ' . $object->element;
			return ['res' => $res, 'message' => $message];
		}

		//Clear reason code if status code is -1
		if ($reasonCode == '-1') {
			$reasonCode = '';
		}


		$einvoicing = new EInvoicing($db);
		$availableStatuses = $object->element === 'invoice_supplier'
			? $einvoicing->getEinvoiceStatusOptions(1, 1, 1)
			: [$einvoicing::STATUS_PAID => $einvoicing::STATUS_LABEL_KEYS[$einvoicing::STATUS_PAID]];	// Required to send the new status of customer invoices. We may need to consider a new method for obtaining these statuses or update the current method.
		if (!array_key_exists($statusCode, $availableStatuses)) {
			$res = -1;
			$message = 'SendStatusMessage Unsupported status code: ' . $statusCode;
			return ['res' => $res, 'message' => $message];
		}
		$statusLabelToSend = $einvoicing->getStatusLabel($statusCode);

		dol_include_once('/einvoicing/class/utils/CdarHandler.class.php');
		$cdarHandler = new CdarHandler($db);
		$result = $cdarHandler->generateCdarFile($object, $statusCode, $reasonCode, $paymentData);
		if ($result['res'] < 0) {
			$res = -1;
			$message = 'Failed to generate CDAR file: ' . $result['message'];
			return ['res' => $res, 'message' => $message];
		}

		$filepath = $result['file'];
		if (file_exists($filepath)) {
			dol_syslog(__METHOD__ . " Generated CDAR file path: " . $filepath, LOG_DEBUG, 0, "_einvoicing");

			// Extra headers
			$extraHeaders = [
				'Content-Type' => 'multipart/form-data'
			];

			// Params
			$params = [
				'flowInfo' => json_encode([
					"name" => "LC_" . $object->ref,
					"flowSyntax" => "CDAR"
				]),
				'file' => new CURLFile($filepath, 'application/xml', basename($filepath))
			];

			// Call API to send CDAR
			$response = $this->callApi("flows", "POSTALREADYFORMATED", $params, $extraHeaders, 'Send Status Message');

			// The CDAR temp file was only needed for the upload above; drop it now (unique name, #226).
			dol_delete_file($filepath);

			if ($response['status_code'] == 200 || $response['status_code'] == 202) {
				/**
				 * Document validation on the PDP side may take some time, so we initially set the status to
				 * "Sent", then make an additional call to retrieve the acknowledgment information:
				 * - If the validation is successful, we update the status of the electronic invoice accordingly.
				 * - If the PDP validation fails, we set the status to "Error" and log the reason.
				 * If no response is available yet, we wait for the next synchronization.
				 **/

				$flowId = $response['response']['flowId'] ?? '';

				// Update einvoice status with awaiting validation
				$einvoicing = new EInvoicing($db);
				//$einvoicing->insertOrUpdateExtLink($object->id, $object->element, $flowId, EInvoicing::STATUS_AWAITING_VALIDATION, $object->ref);
				$resStoreStatus = $einvoicing->storeStatusMessage($object->id, $object->element, $statusCode, '', 'out', $flowId, '', '', '', $reasonCode);

				// Call the API to retrieve flow details and check the validation status.
				$resource = 'flows/' . $flowId;
				$urlparams = array(
					'docType' => 'Metadata',
				);
				$resource .= '?' . http_build_query($urlparams);
				$response = $this->callApi(
					$resource,
					"GET",
					false,
					['Accept' => 'application/octet-stream'],
					'Check Status validation'
				);

				if ($response['status_code'] == 200 || $response['status_code'] == 202) {
					//dol_include_once('einvoicing/class/document.class.php');

					// Process flow data
					$flowData = array();
					try {
						$flowData = json_decode($response['response'], true);
					} catch (Exception $e) {
						return array('res' => -1, 'message' => "Failed to parse the json answer for flowId: " . $flowId);
					}

					// Update einvoice status with received validation result
					$syncStatus = $einvoicing::STATUS_AWAITING_VALIDATION;
					$ack_statusLabel = $flowData['acknowledgement']['status'] ?? '';
					if ($ack_statusLabel === 'Ok') { // So status is sent and validated so we log sent status
						$syncStatus = $statusCode;
					} else {
						if ($ack_statusLabel) {
							$syncStatus = $einvoicing->getDolibarrStatusCodeFromPdpLabel($ack_statusLabel);
						}
					}

					$syncRef = $flowData['trackingId'] ?? '';
					$syncComment = $flowData['acknowledgement']['details'][0]['reasonMessage'] ?? '';
					//$einvoicing->insertOrUpdateExtLink($object->id, $object->element, $flowId, $syncStatus, $syncRef, $syncComment);
					$einvoicing->updateStatusMessageValidation($resStoreStatus, '', $ack_statusLabel, $syncComment);

					// Log an event in the invoice timeline if status not pending
					// We have just POST a new status so we log a rcord here in agenda to remind date (even if message is pending, so not yet fully processed by AP)
					//if ($ack_statusLabel != 'Pending') {
						$eventLabel = "EINVOICING - ".$langs->trans("SendingStatus").' ['.$statusLabelToSend.']';
						$eventMessage = "EINVOICING - ".$langs->trans("SendingStatus")." (From sendStatusMessage) - [Dolibarr: " . $statusLabelToSend . ', '.$langs->trans("ResultOnAP").': '.$ack_statusLabel . (!empty($syncComment) ? " - " . $syncComment : "")."]";

						$resLogEvent = $this->addEvent('STATUS', $eventLabel, $eventMessage, $object);
					if ($resLogEvent < 0) {
						dol_syslog(__METHOD__ . " Failed to log event for flowId: {$flowId}", LOG_WARNING);
					}
					//}
				} else {
					dol_syslog(__METHOD__ . " Unable to retrieve flow details after sending status message for flowId: {$flowId}. Status code: " . $response['status_code'], LOG_WARNING);
					$res = 1;
					$message = 'Failed to retrieve flow details after sending status message. Status code: ' . $response['status_code'];
				}
			} else {
				$res = -1;
				$platformMessage = (string) (!empty($response['response']['message'])
					? $response['response']['message']
					: ($response['errorMessage'] ?? 'No message'));
				// Name the platform that refused: this wording is identical in every provider, so a bug
				// report quoting it alone never says which platform answered (issue #799).
				$message = 'Failed to send CDAR file to ' . $this->name . '. Status code: ' . $response['status_code'] . '. Message: ' . $platformMessage;
				// MDT-73 is the electronic address the status is sent to. The platform refuses the CDAR when
				// it does not know the vendor under the address the module used, and says nothing about what
				// to do next - while the received invoice stays impossible to approve or refuse, and so
				// impossible to delete. Name the third party and the field that fixes it.
				if (strpos($platformMessage, 'MDT-73') !== false) {
					if (empty($object->thirdparty)) {
						$object->fetch_thirdparty();
					}
					$vendorName = !empty($object->thirdparty->name) ? $object->thirdparty->name : ('#' . (int) $object->socid);
					$usedAddress = $cdarHandler->recipientURIID !== '' ? $cdarHandler->recipientURIID : '-';
					$message .= ' - ' . ($cdarHandler->recipientURIIDOrigin === 'routing'
						? $langs->trans('CdarAddressRefusedRecordedRouting', $vendorName, $usedAddress)
						: $langs->trans('CdarAddressRefusedNoRouting', $vendorName, $usedAddress));
				}
				return ['res' => $res, 'message' => $message];
			}
		} else {
			$res = -1;
			$message = 'CDAR file does not exist: ' . $filepath;
			return ['res' => $res, 'message' => $message];
		}

		return ['res' => $res, 'message' => $message];
	}
}
