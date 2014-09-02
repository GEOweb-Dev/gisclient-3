<?php

define('SKIP_INCLUDE', true);
require_once '../../config/config.php';
require_once __DIR__.'/include/OwsHandler.php';

if(!defined('GC_SESSION_NAME')) die('Undefined GC_SESSION_NAME in config');

// dirotta una richiesta PUT/DELETE GC_EDITMODE
if(($_SERVER['REQUEST_METHOD'] == 'POST' && strpos($_SERVER['REQUEST_URI'],'GC_EDITMODE=')!==false )|| $_SERVER['REQUEST_METHOD'] == 'PUT' || $_SERVER['REQUEST_METHOD'] == 'DELETE'){
	include ("./include/putrequest.php");
	exit(0);
}

// dirotta una richiesta POST di tipo OLWFS al cgi mapserv, per bug su loadparams
if (!empty($_REQUEST['gcRequestType']) && $_SERVER['REQUEST_METHOD'] == 'POST' && $_REQUEST['gcRequestType'] == 'OLWFS') {
	$url = MAPSERVER_URL.'map='.ROOT_PATH.'map/'.$request['PROJECT'].'/'.$request['MAP'].'.map';
	$postFields = file_get_contents('php://input');
	$owsHandler = new OwsHandler();
	$owsHandler->post($url, $postFields);
	exit(0);
}

if(defined('DEBUG') && DEBUG == true) {
	ini_set('display_errors', 'On');
	error_reporting(E_ALL ^ E_NOTICE);
}

$objRequest = ms_newOwsrequestObj();
$skippedParams = array();
$invertedAxisOrderSrids = array(31467);

foreach ($_REQUEST as $k => $v) {
    // SLD parameter is handled later (to work also with getlegendgraphic)
    // skipping this parameter does avoid a second request made by mapserver
    if (in_array(strtolower($k), array('sld', 'filter'))) {
		$skippedParams[strtolower($k)] = $k;
        continue;
    }
    if (is_string($v)) {
        $objRequest->setParameter($k, stripslashes($v));
    }
}

/* ------ stabilisco i layer da usare ------ */

// recupero lista layer dal parametro layers
$layersParameter = null;
$parameterName = null;
if($objRequest->getValueByName('service') == 'WMS') {
	$parameterName = 'LAYERS';
	$layersParameter = $objRequest->getValueByName('layers');
} else if($objRequest->getValueByName('service') == 'WFS') {
	$parameterName = 'TYPENAME';
	$layersParameter = $objRequest->getValueByName('typename');
	if (isset($skippedParams['filter'])) {
		$owsHandler = new OwsHandler();
		$prunedFilter = $owsHandler->pruneSrsFromFilter($_REQUEST[$skippedParams['filter']], $invertedAxisOrderSrids);
		$objRequest->setParameter($skippedParams['filter'], $prunedFilter);
	}
}

//OGGETTO MAP MAPSCRIPT
$directory = "../../map/".$objRequest->getvaluebyname('project')."/";

// se è definita una lingua, apro il relativo mapfile
$mapfile = $objRequest->getvaluebyname('map');
if($objRequest->getvaluebyname('lang') && file_exists($directory.$objRequest->getvaluebyname('map').'_'.$objRequest->getvaluebyname('lang').'.map')) {
	$mapfile = $objRequest->getvaluebyname('map').'_'.$objRequest->getvaluebyname('lang');
}
//Files temporanei
$showTmpMapfile = $objRequest->getvaluebyname('tmp');
if(!empty($showTmpMapfile)) {
	$mapfile = "tmp.".$mapfile;
}

$oMap = ms_newMapobj($directory.$mapfile.".map");

$resolution = $objRequest->getvaluebyname('resolution');
if(!empty($resolution) && $resolution != 72) {
	$oMap->set('resolution', (int)$objRequest->getvaluebyname('resolution'));
	$oMap->set('defresolution', 96);
}

// visto che mapserver non riesce a scaricare il file sld, lo facciamo noi, con l'url nel parametro SLD_BODY o SLD
if(!empty($_REQUEST['SLD_BODY']) && substr($_REQUEST['SLD_BODY'],-4)=='.xml'){
	$sldContent = file_get_contents($_REQUEST['SLD_BODY']);
	if($sldContent !== false) {
        $objRequest->setParameter('SLD_BODY', $sldContent);
        $oMap->applySLD($sldContent); // for getlegendgraphic
    }
} else if(!empty($_REQUEST['SLD'])) {
	$ch = curl_init($_REQUEST['SLD']);
	curl_setopt($ch, CURLOPT_HEADER, 0);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_BINARYTRANSFER, 1);
	curl_setopt($ch ,CURLOPT_TIMEOUT, 10); 
	$sldContent = curl_exec($ch);
        if($sldContent === false) {
                throw new RuntimeException("Call to $url return with error:". var_export(curl_error($ch), true));
	}
	if (200 != ($httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE))) {
		throw new RuntimeException("Call to $url return HTTP code $httpCode and body ".$sldContent);
	}
	curl_close($ch);
    
	$objRequest->setParameter('SLD_BODY', $sldContent);
	$oMap->applySLD($sldContent); // for getlegendgraphic
}


//CAMBIA EPSG CON QUELLO CON PARAMETRI DI CORREZIONE SE ESISTE 
if($objRequest->getvaluebyname('srsname')) $objRequest->setParameter('srs', $objRequest->getvaluebyname('srsname'));// QUANTUM GIS PASSAVA SRSNAME... DA VERIFICARE
if($objRequest->getvaluebyname('srs') && $oMap->getMetaData($objRequest->getvaluebyname('srs'))) $objRequest->setParameter("srs", $oMap->getMetaData($objRequest->getvaluebyname('srs')));
if($objRequest->getvaluebyname('srs')) {
	$srsParts = explode(':', strtolower($objRequest->getvaluebyname('srs')));
	if (count($srsParts) == 7) {
		// e.g.: 'urn:ogc:def:crs:EPSG::4306'
		$srs = $srsParts[4].':'.$srsParts[6];
	} elseif (count($srsParts) == 2) {
		// e.g.: 'EPSG:4306'
		$srs = $srsParts[0].':'.$srsParts[1];
	}
	$oMap->setProjection("+init=".strtolower($srs));
}

$url = OwsHandler::currentPageURL();
$oMap->setMetaData("ows_onlineresource",$url.'?project='.$objRequest->getvaluebyname('project')."&map=".$objRequest->getvaluebyname('map'));


if(!empty($_REQUEST['GCFILTERS'])){

	$v = explode(',',stripslashes($_REQUEST['GCFILTERS']));
	for($i=0;$i<count($v);$i++){
		list($layerName,$gcFilter)=explode('@',$v[$i]);

		$oLayer = $oMap->getLayerByName($layerName);
		if($oLayer)	OwsHandler::applyGCFilter($oLayer,$gcFilter);
		//print_debug($oLayer->getFilterString());
	}
}


// avvio la sessione
if(!isset($_SESSION)) {
	if(defined('GC_SESSION_NAME')) {
		session_name(GC_SESSION_NAME);
		if(isset($_REQUEST['GC_SESSION_ID']) && !empty($_REQUEST['GC_SESSION_ID'])) {
			session_id($_REQUEST['GC_SESSION_ID']);
 		}
	}
	session_start();
}

$cacheExpireTimeout = isset($_SESSION['GC_SESSION_CACHE_EXPIRE_TIMEOUT']) ? $_SESSION['GC_SESSION_CACHE_EXPIRE_TIMEOUT'] : null;
if(!isset($_SESSION['GISCLIENT_USER_LAYER']) && !empty($layersParameter) && empty($_REQUEST['GISCLIENT_MAP'])) {
	$hasPrivateLayers = false;
	if(!empty($layersParameter)) {
		$layersArray = OwsHandler::getRequestedLayers($oMap, $objRequest, $layersParameter);
	}
	foreach($layersArray as $layer) {
		$privateLayer = $layer->getMetaData('gc_private_layer');
		if(!empty($privateLayer)) {
			$hasPrivateLayers = true;
			break;
		}
	}
	if($hasPrivateLayers) {
		if (!isset($_SERVER['PHP_AUTH_USER'])) {
			header('WWW-Authenticate: Basic realm="Gisclient"');
			header('HTTP/1.0 401 Unauthorized');
		} else {
            $user = new GCUser();
            if($user->login($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])) {
                $user->setAuthorizedLayers(array('mapset_name'=>$objRequest->getValueByName('map')));
            }
		}
	}
}

if(!empty($layersParameter)) {
	$layersArray = OwsHandler::getRequestedLayers($layersParameter);
	
	// stabilisco i layer da rimuovere (nascosti, privati e con filtri obbligatori non definiti) e applico i filtri
	$layersToRemove = array();
	$layersToInclude = array();
	foreach($layersArray as $layer) {
	
		//layer aggiunto x highlight
		$highlight = $objRequest->getvaluebyname('highlight');
		if(strtoupper($objRequest->getvaluebyname('request')) == 'GETMAP' && !empty($highlight)) $layer->set('sizeunits',MS_PIXELS);
	
		// layer nascosto
		$hideLayer = $layer->getMetaData("gc_hide_layer");
		if(strtoupper($objRequest->getvaluebyname('request')) == 'GETMAP' && !empty($hideLayer)) {
			array_push($layersToRemove, $layer->name);
			continue;
		}
		// layer privato
		$privateLayer = $layer->getMetaData('gc_private_layer');
		if(!empty($privateLayer)) {
			if(!OwsHandler::checkLayer($objRequest->getvaluebyname('project'), $objRequest->getvaluebyname('service'), $layer->name)) {
				array_push($layersToRemove, $layer->name); // al quale l'utente non ha accesso
				continue;
			}
		}
		$n = 0;
		// se ci sono filtri definiti per il layer, li ciclo
		while($authFilter = $layer->getMetaData('gc_authfilter_'.$n)) {
			if(empty($authFilter)) break; // se l'ennesimo filtro +1 non è definito, interrompo il ciclo
			$required = $layer->getMetaData('gc_authfilter_'.$n.'_required');
			$n++;
			// se il filtro è obbligatorio
			if(!empty($required)) {
				if(!isset($_SESSION['AUTHFILTERS'][$authFilter])) { // e se l'utente non ha quel filtro definito
					array_push($layersToRemove, $layer->name); // rimuovo il layer
					break;
				}
			}
			// se ci sono filtri definiti
			if(isset($_SESSION['AUTHFILTERS'][$authFilter])) {
				$filter = $layer->getFilterString();
				$filter = trim($filter, '"');
				if(!empty($filter)) { // se esiste già un filtro lo aggiungo
					$filter = $filter.' AND '.$_SESSION['AUTHFILTERS'][$authFilter];
				} else {
					$filter = $_SESSION['AUTHFILTERS'][$authFilter];
				}
				// aggiorno il FILTER del layer
				$layer->setFilter($filter);
			}
		}
		
		if(!empty($_SESSION['GC_LAYER_FILTERS'])) {
            if(!empty($_SESSION['GC_LAYER_FILTERS'][$layer->name])) {
                $filter = $layer->getFilterString();
                $filter = trim($filter, '"');
                if(!empty($filter)) {
                    $filter = $filter.' AND ('.$_SESSION['GC_LAYER_FILTERS'][$layer->name].')';
                } else {
                    $filter = $_SESSION['GC_LAYER_FILTERS'][$layer->name];
                }
                $layer->setFilter($filter);
            }
        }
		
		if(!in_array($layer->name, $layersToRemove)) array_push($layersToInclude, $layer->name);
	}
	
	// rimuovo i layer che l'utente non può visualizzare
	foreach($layersToRemove as $layerName) {
		$layer = $oMap->getLayerByName($layerName);
		$oMap->removeLayer($layer->index);
	}
	// aggiorno il parametro layers con i soli layers che l'utente può vedere 
	$objRequest->setParameter($parameterName, implode(",",$layersToInclude));		
}
session_write_close();

// Cache part 1
$owsCacheTTL = defined('OWS_CACHE_TTL') ? OWS_CACHE_TTL : 0;
$owsCacheTTLOpen = defined('OWS_CACHE_TTL_OPEN') ? OWS_CACHE_TTL_OPEN : 0;
if ((isset($_REQUEST['REQUEST']) && 
     strtolower($_REQUEST['REQUEST']) == 'getmap') || 
	(isset($_REQUEST['request']) && 
     strtolower($_REQUEST['request']) == 'getmap')) {
	
	if ($owsCacheTTL > 0 && isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && strtotime($_SERVER["HTTP_IF_MODIFIED_SINCE"]) < time() - $owsCacheTTL) {
		header('HTTP/1.1 304 Not Modified');
		die(); // Dont' return image
	}
}

if(strtoupper($objRequest->getvaluebyname('request')) == 'GETLEGENDGRAPHIC') {
	include './include/wmsGetLegendGraphic.php';
}

//SE NON SONO IN CGI CARICO I PARAMETRI
$sapi_type = php_sapi_name();
if (substr($sapi_type, 0, 3) != 'cgi') {
	if ($objRequest->getvaluebyname('service') != "WFS" && $objRequest->type == -1) $oMap->loadowsparameters($objRequest);
}


/* Enable output buffer */ 
ms_ioinstallstdouttobuffer(); 

/* Execute request */ 
$oMap->owsdispatch($objRequest);
$contenttype = ms_iostripstdoutbuffercontenttype(); 
$ctt = explode("/",$contenttype); 

/* Send response with appropriate header */ 
if ($ctt[0] == 'image') {

	$hasDynamicLayer = false;
	if (defined('DYNAMIC_LAYERS')) {
		$dynamicLayers = explode(',', DYNAMIC_LAYERS);
		if (isset($layersToInclude)) {
			foreach($layersToInclude as $currentLayer) {
				if (in_array($currentLayer, $dynamicLayers)) {
					$hasDynamicLayer = true;
					break;
				}
			}
		}
    }

	header('Content-type: image/'. $ctt[1]); 
    
    // Cache part 2
	if (!$hasDynamicLayer && $cacheExpireTimeout > 0 && $cacheExpireTimeout > time()) {
		$cacheTime = gmdate("D, d M Y H:i:s", time() + $owsCacheTTLOpen) . " GMT";
		$serverTime = gmdate("D, d M Y H:i:s", time()) . " GMT";
		header("Cache-Control: public, max-age={$owsCacheTTLOpen}, pre-check={$owsCacheTTLOpen}	");
		header("Pragma: public");
        header("Date: {$serverTime}");
		header("Cache-Control: max-age={$owsCacheTTLOpen}");
		header("Last-Modified: {$serverTime}");
		header("Expires: {$cacheTime}");
	} else if ($owsCacheTTL > 0) {
		// OL FIX: Prevent multiple request for the same layer. Fixed setting cache to 60 sec
		$cacheTime = gmdate("D, d M Y H:i:s", time() + $owsCacheTTL) . " GMT";
		$serverTime = gmdate("D, d M Y H:i:s", time()) . " GMT";
		header("Cache-Control: public, max-age={$owsCacheTTL}, pre-check={$owsCacheTTL}	");
		header("Pragma: public");
        header("Date: {$serverTime}");
		header("Cache-Control: max-age={$owsCacheTTL}");
		header("Last-Modified: {$serverTime}");
		header("Expires: {$cacheTime}");
	}
    
	ms_iogetStdoutBufferBytes(); 
} else { 
	header("Content-Type: application/xml"); 
	ms_iogetStdoutBufferBytes(); 
} 

ms_ioresethandlers();

