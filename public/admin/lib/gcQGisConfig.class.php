<?php
/*
GisClient

This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 3
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.

*/
define('WMS_LAYER_TYPE',1);
define('WMTS_LAYER_TYPE',2);
define('WMS_CACHE_LAYER_TYPE',3);
define('TMS_LAYER_TYPE',6);

// **** globals
// **** TODO: in Conf file
define('QGIS_VERSION', '2.14.11-Essen');
define('QGIS_QUALIFIED_NAME', 'http://mrcc.com/qgis.dtd');
define('QGIS_SAVE_PATH', 'public/admin/export/');

class gcQGisConfig{
    var $db;
    var $projectName='';
    private $projectTitle;
    var $symbolText='';
    var $layerText='';
    var $qGisDOMs = array();
    var $mapTitle='';
    var $mapAbstract='';
    var $printMap = false;
    var $serviceOnlineresource='';
    var $layersWithAccessConstraints = array();
    var $srsParams = array();
    var $epsgList;
    var $mapInfo=array();
    var $srsCustom=array();
    private $projectMaxScale;
    private $projectSrid;
    private $xCenter;
    private $yCenter;
    private $msVersion;
    private $grids = array();
    private $iconSize = array(16,10);


    private $i18n;
    private $languageId;

    function __construct ($languageId = null){
        $this->db = GCApp::getDB();
        $this->languageId = $languageId;
        $this->msVersion = substr(ms_GetVersionInt(),0,1);

    }

    function __destruct (){

        unset($this->db);
        unset($this->filter);
        unset($this->mapError);
        unset ($this->qGisDOMs);
    }

    public function setIconSize($size) {
        $this->iconSize = $size;
    }

    function writeMap($keytype,$keyvalue){

        $sqlParams = array();

        if($keytype=="mapset") {    //GENERO IL MAPFILE PER IL MAPSET
                $filter="mapset.mapset_name=:keyvalue";
                $joinMapset="INNER JOIN ".DB_SCHEMA.".mapset using (project_name) INNER JOIN ".DB_SCHEMA.".mapset_layergroup using (mapset_name,layergroup_id)";
                $fieldsMapset="mapset_layergroup.status as layergroup_status, mapset_name,mapset_title,mapset_extent,mapset_srid,mapset.maxscale as mapset_maxscale,mapset_def,";
                $sqlParams['keyvalue'] = $keyvalue;

                $sql = 'select project_name from '.DB_SCHEMA.'.mapset where mapset_name=:mapset';
                $stmt = $this->db->prepare($sql);
                $stmt->execute(array('mapset'=>$keyvalue));
                $projectName = $stmt->fetchColumn(0);

        } elseif($keytype=="project") { //GENERO TUTTI I MAPFILE PER IL PROGETTO OPPURE UNICO MAPFILE PER PROGETTO
            $filter="project.project_name=:keyvalue";
            if(defined('PROJECT_MAPFILE') && PROJECT_MAPFILE) {
                $joinMapset="";
                $fieldsMapset = '1 as layergroup_status, project_name as mapset_name, project_title as mapset_title, project_srid as mapset_srid, null as mapset_extent,';
            } else {
                $joinMapset="INNER JOIN ".DB_SCHEMA.".mapset using (project_name) INNER JOIN ".DB_SCHEMA.".mapset_layergroup using (mapset_name,layergroup_id)";
                $fieldsMapset="mapset_layergroup.status as layergroup_status, mapset_name,mapset_title,mapset_extent,mapset_srid,mapset.maxscale as mapset_maxscale,mapset_def,";
            }
            $sqlParams['keyvalue'] = $keyvalue;
            $projectName = $keyvalue;

        }

        if(!empty($this->languageId)) {
          // inizializzo l'oggetto i18n per le traduzioni
            $this->i18n = new GCi18n($projectName, $this->languageId);
        }


        $sql="select project_name,".$fieldsMapset."base_url,max_extent_scale,project_srid,xc,yc,outputformat_mimetype,
        theme_title,theme_name,theme_order,layergroup_name,layergroup_title,layergroup_id,layergroup_description,layergroup_maxscale,layergroup_minscale,layergroup_order
        isbaselayer,tree_group,tiletype_id,owstype_id,layer_id,layer_name,layer_title,layer.hidden,layertype_id, project_title, set_extent
        from ".DB_SCHEMA.".layer
        INNER JOIN ".DB_SCHEMA.".layergroup  using (layergroup_id)
        INNER JOIN ".DB_SCHEMA.".theme using (theme_id)
        INNER JOIN ".DB_SCHEMA.".project using (project_name) ".$joinMapset."
        LEFT JOIN ".DB_SCHEMA.".e_outputformat using (outputformat_id)
        LEFT JOIN ".DB_SCHEMA.".catalog using (catalog_id, project_name)
        where ".$filter." order by mapset_name, theme_order, layergroup_order, layer_order;";
        //where ".$filter." order by theme_order desc, layergroup_order desc, layer_order desc;";   SERVE PER SCRIVERE I LAYER NEL MAPFILE UTILIZZANDO L'ORDINE RELATIVO TEMA-LAYERGROUP-LAYER. Sarebbe da sviluppare la funzione che permette all'utente di sceglierlo a livello di progetto

        print_debug($sql,null,'writeqgis');

        $stmt = $this->db->prepare($sql);
        $stmt->execute($sqlParams);
        $res = $stmt->fetchAll();

        if($stmt->rowCount() == 0) {
            $this->mapError=200;//Mancano i layers
            echo 'NO LAYERS';
            return;
        }

        $aLayer=$res[0];
        $this->projectName = $aLayer["project_name"];
        $this->projectSrid = $aLayer["project_srid"];
        $this->xCenter = $aLayer['xc'];
        $this->yCenter = $aLayer['yc'];

        //SCALA MASSIMA DEL PROGETTO
        $projectMaxScale = floatval($aLayer["max_extent_scale"])?floatval($aLayer["max_extent_scale"]):100000000;
        $projectExtent = $this->_calculateExtentFromCenter($projectMaxScale, $this->projectSrid);
        $this->projectMaxScale = $projectMaxScale;

        $mapText=array();
        $mapSrid=array();
        $mapExtent=array();
        $symbolsList=array();
        $oFeature = new gcFeatureQGis($this->i18n);

        //mapproxy
        $this->mpxLayers=array();
        $this->mpxCaches=array();

        $this->_setMapProjections();
        $oFeature->srsParams = $this->srsParams;

        $defaultLayers = array();
        foreach ($res as $aLayer){
            // **** Allowed layer types
            // TODO: expand? configure?
            if ($aLayer['layertype_id'] != 1 && $aLayer['layertype_id'] != 2 && $aLayer['layertype_id'] != 3 && $aLayer['layertype_id'] != 5)
                continue;

            $mapName = $aLayer["mapset_name"];

            if (!array_key_exists($mapName,$this->qGisDOMs)) {
                // **********************************************************************************************
                // **** Create QGis mapfile DOM structure
                $this->qGisDOMs[$mapName] = DOMImplementation::createDocument(null, 'qgis',
                        DOMImplementation::createDocumentType('qgis', QGIS_QUALIFIED_NAME, 'SYSTEM'));
                $this->qGisDOMs[$mapName]->formatOutput = true;
                $docElem = $this->qGisDOMs[$mapName]->documentElement;
                $docElem->setAttribute('projectname', $mapName);
                $docElem->setAttribute('version', QGIS_VERSION);

                // **** Title
                $titleElem = $this->qGisDOMs[$mapName]->createElement('title', $aLayer["mapset_title"]);
                $docElem->appendChild($titleElem);

                // ***** layer-tree-group
                $layertreeElem = $this->qGisDOMs[$mapName]->createElement('layer-tree-group');
                $layertreeElem->setAttribute('expanded', '1');
                $layertreeElem->setAttribute('checked', 'Qt::Checked');
                $layertreeElem->setAttribute('name', '');
                $custPropsElem = $this->qGisDOMs[$mapName]->createElement('customproperties');
                $layertreeElem->appendChild($custPropsElem);
                $docElem->appendChild($layertreeElem);

                // **** mapcanvas
                $canvasElem = $this->qGisDOMs[$mapName]->createElement('mapcanvas');
                // ** units
                $unitsElem = $this->qGisDOMs[$mapName]->createElement('units', 'meters'); // **** TODO : get unit from project
                $canvasElem->appendChild($unitsElem);
                // ** Extent
                $extentElem = $this->qGisDOMs[$mapName]->createElement('extent');
                $extentXMin = $this->qGisDOMs[$mapName]->createElement('xmin', $projectExtent[0]);
                $extentElem->appendChild($extentXMin);
                $extentYMin = $this->qGisDOMs[$mapName]->createElement('ymin', $projectExtent[1]);
                $extentElem->appendChild($extentYMin);
                $extentXMax = $this->qGisDOMs[$mapName]->createElement('xmax', $projectExtent[2]);
                $extentElem->appendChild($extentXMax);
                $extentYMax = $this->qGisDOMs[$mapName]->createElement('ymax', $projectExtent[3]);
                $extentElem->appendChild($extentYMax);
                $canvasElem->appendChild($extentElem);
                // ** rotation
                $rotElem = $this->qGisDOMs[$mapName]->createElement('rotation', '0');
                $canvasElem->appendChild($rotElem);
                // ** projections - TODO: check if must populate
                $projElem = $this->qGisDOMs[$mapName]->createElement('projections', '0');
                $canvasElem->appendChild($projElem);
                // ** destinationsrs
                $destsrsElem = $this->qGisDOMs[$mapName]->createElement('destinationsrs');
                $refsysDom = $oFeature->getSpatialRefSysNode($this->projectSrid );
                $refsysRes = $refsysDom->getElementsByTagName('spatialrefsys')->item( 0 );
                $refsysImport = $this->qGisDOMs[$mapName]->importNode($refsysRes, TRUE);
                $destsrsElem->appendChild($refsysImport);
                $canvasElem->appendChild($destsrsElem);
                // ** rendermaptile
                $rmtElem = $this->qGisDOMs[$mapName]->createElement('rendermaptile', '0');
                $canvasElem->appendChild($rmtElem);
                // ** layer_coordinate_transform_info
                $lctiElem = $this->qGisDOMs[$mapName]->createElement('layer_coordinate_transform_info');
                $canvasElem->appendChild($lctiElem);
                $docElem->appendChild($canvasElem);

                // **** layer-tree-canvas
                $ltcanvasElem = $this->qGisDOMs[$mapName]->createElement('layer-tree-canvas');
                $corderElem = $this->qGisDOMs[$mapName]->createElement('custom-order');
                $corderElem->setAttribute('enabled', '0');
                $ltcanvasElem->appendChild($corderElem);
                $docElem->appendChild($ltcanvasElem);

                // **** legend
                $legendElem = $this->qGisDOMs[$mapName]->createElement('legend');
                $legendElem->setAttribute('updateDrawingOrder','true');
                $docElem->appendChild($legendElem);

                // **** projectlayers
                $projlayersElem = $this->qGisDOMs[$mapName]->createElement('projectlayers');
                $docElem->appendChild($projlayersElem);

                // **** properties
                $propsElem = $this->qGisDOMs[$mapName]->createElement('properties');
                $docElem->appendChild($propsElem);
            }


            $mapQGisConf = $this->qGisDOMs[$mapName];
            $mapXpath = new DOMXPath($mapQGisConf);

            $layergroupName = NameReplace($aLayer["layergroup_name"]);
            $layerTreeGroup = $aLayer["tree_group"];
            $mapSrid[$mapName] = $aLayer["mapset_srid"];
            $mapTitle[$mapName] = $aLayer["mapset_title"];
            $mapExtent[$mapName] = $aLayer["mapset_extent"];
            $mapMaxScale[$mapName] = floatval($aLayer["mapset_maxscale"])?min(floatval($aLayer["mapset_maxscale"]), $projectMaxScale):$projectMaxScale;

            $oFeature->initFeature($aLayer["layer_id"]);
            $oFeatureData = $oFeature->getFeatureData();

            // **** Layer in layer-tree-group
            $queryRes = $mapXpath->query("/qgis/layer-tree-group/layer-tree-group[@name='" . $aLayer['theme_title'] . "']");
            if ($queryRes->length == 0) {
                $themeTreeItem = $mapQGisConf->createElement('layer-tree-group');
                $themeTreeItem->setAttribute('expanded', '1');
                $themeTreeItem->setAttribute('checked', 'Qt::Checked');
                $themeTreeItem->setAttribute('name', $aLayer['theme_title']);
                $themeCustPropsElem = $mapQGisConf->createElement('customproperties');
                $themeTreeItem->appendChild($themeCustPropsElem);
                $layergroupTreeItem = $mapQGisConf->createElement('layer-tree-group');
                $layergroupTreeItem->setAttribute('expanded', '1');
                $layergroupTreeItem->setAttribute('checked', 'Qt::Checked');
                $layergroupTreeItem->setAttribute('name', $aLayer['layergroup_title']);
                $layergroupCustPropsElem = $mapQGisConf->createElement('customproperties');
                $layergroupTreeItem->appendChild($layergroupCustPropsElem);
                $layerTreeDom = $oFeature->getFeatureTreeNode();
                $layerTreeRes = $layerTreeDom->getElementsByTagName('layer-tree-layer')->item( 0 );
                $layerTreeImport = $mapQGisConf->importNode($layerTreeRes, TRUE);
                $layergroupTreeItem->appendChild($layerTreeImport);
                $themeTreeItem->appendChild($layergroupTreeItem);
                $queryResItem = $mapXpath->query("//qgis/layer-tree-group")->item(0);
                $queryResItem->appendChild($themeTreeItem);
            }
            else {
                $queryRes = $mapXpath->query("/qgis/layer-tree-group/layer-tree-group[@name='" . $aLayer['theme_title'] . "']/layer-tree-group[@name='" . $aLayer['layergroup_title'] . "']");
                if ($queryRes->length == 0) {
                    $layergroupTreeItem = $mapQGisConf->createElement('layer-tree-group');
                    $layergroupTreeItem->setAttribute('expanded', '1');
                    $layergroupTreeItem->setAttribute('checked', 'Qt::Checked');
                    $layergroupTreeItem->setAttribute('name', $aLayer['layergroup_title']);
                    $layergroupCustPropsElem = $mapQGisConf->createElement('customproperties');
                    $layergroupTreeItem->appendChild($layergroupCustPropsElem);
                    $layerTreeDom = $oFeature->getFeatureTreeNode();
                    $layerTreeRes = $layerTreeDom->getElementsByTagName('layer-tree-layer')->item( 0 );
                    $layerTreeImport = $mapQGisConf->importNode($layerTreeRes, TRUE);
                    $layergroupTreeItem->appendChild($layerTreeImport);
                    $queryResItem = $mapXpath->query("/qgis/layer-tree-group/layer-tree-group[@name='" . $aLayer['theme_title'] . "']")->item(0);
                    $queryResItem->appendChild($layergroupTreeItem);
                }
                else {
                    $queryResItem = $queryRes->item(0);
                    $layerTreeDom = $oFeature->getFeatureTreeNode();
                    $layerTreeRes = $layerTreeDom->getElementsByTagName('layer-tree-layer')->item( 0 );
                    $layerTreeImport = $mapQGisConf->importNode($layerTreeRes, TRUE);
                    $queryResItem->appendChild($layerTreeImport);
                }
            }


            // **** Layer 's item in layer-tree-canvas
            $layerTreeCanvasElem = $mapXpath->query('//qgis/layer-tree-canvas/custom-order')->item(0);
            $ltcElem = $mapQGisConf->createElement('item', $oFeatureData['qgis_id']);
            $layerTreeCanvasElem->appendChild($ltcElem);


            // **** Layer in legend
            $queryRes = $mapXpath->query("/qgis/legend/legendgroup[@name='" . $aLayer['theme_title'] . "']");
            if ($queryRes->length == 0) {
                $themeTreeItem = $mapQGisConf->createElement('legendgroup');
                $themeTreeItem->setAttribute('open', 'true');
                $themeTreeItem->setAttribute('checked', 'Qt::Checked');
                $themeTreeItem->setAttribute('name', $aLayer['theme_title']);
                $layergroupTreeItem = $mapQGisConf->createElement('legendgroup');
                $layergroupTreeItem->setAttribute('open', 'true');
                $layergroupTreeItem->setAttribute('checked', 'Qt::Checked');
                $layergroupTreeItem->setAttribute('name', $aLayer['layergroup_title']);
                $layerTreeDom = $oFeature->getFeatureLegendNode();
                $layerTreeRes = $layerTreeDom->getElementsByTagName('legendlayer')->item( 0 );
                $layerTreeImport = $mapQGisConf->importNode($layerTreeRes, TRUE);
                $layergroupTreeItem->appendChild($layerTreeImport);
                $themeTreeItem->appendChild($layergroupTreeItem);
                $queryResItem = $mapXpath->query("//qgis/legend")->item(0);
                $queryResItem->appendChild($themeTreeItem);
            }
            else {
                $queryRes = $mapXpath->query("/qgis/legend/legendgroup[@name='" . $aLayer['theme_title'] . "']/legendgroup[@name='" . $aLayer['layergroup_title'] . "']");
                if ($queryRes->length == 0) {
                    $layergroupTreeItem = $mapQGisConf->createElement('legendgroup');
                    $layergroupTreeItem->setAttribute('open', 'true');
                    $layergroupTreeItem->setAttribute('checked', 'Qt::Checked');
                    $layergroupTreeItem->setAttribute('name', $aLayer['layergroup_title']);
                    $layerTreeDom = $oFeature->getFeatureLegendNode();
                    $layerTreeRes = $layerTreeDom->getElementsByTagName('legendlayer')->item( 0 );
                    $layerTreeImport = $mapQGisConf->importNode($layerTreeRes, TRUE);
                    $layergroupTreeItem->appendChild($layerTreeImport);
                    $queryResItem = $mapXpath->query("/qgis/legend/legendgroup[@name='" . $aLayer['theme_title'] . "']")->item(0);
                    $queryResItem->appendChild($layergroupTreeItem);
                }
                else {
                    $queryResItem = $queryRes->item(0);
                    $layerTreeDom = $oFeature->getFeatureLegendNode();
                    $layerTreeRes = $layerTreeDom->getElementsByTagName('legendlayer')->item( 0 );
                    $layerTreeImport = $mapQGisConf->importNode($layerTreeRes, TRUE);
                    $queryResItem->appendChild($layerTreeImport);
                }
            }

            // **** Layer 's item in projectlayers
            $prjlayersElem = $mapXpath->query('//qgis/projectlayers')->item(0);
            $maplayerDom = $oFeature->getFeatureMapNode($aLayer);
            $maplayerRes = $maplayerDom->getElementsByTagName('maplayer')->item( 0 );
            $maplayerImport = $mapQGisConf->importNode($maplayerRes, TRUE);
            $prjlayersElem->appendChild($maplayerImport);

        }

        foreach($this->qGisDOMs as $mapName=>$mapQGisDom){
/*
            $this->layerText = implode("\n",$mapContent);
            $this->mapsetSrid = $mapSrid[$mapName];
            $this->mapsetTitle = $mapTitle[$mapName];

            $this->mapsetMaxScale = $mapMaxScale[$mapName];
            $this->mapsetExtent = $projectExtent;

            //non ho fissato un restricted extent per il mapset, quindi prendo l'extent in funzione della scala massima
            if(empty($mapExtent[$mapName])){
                //EXTENT DEL MAPSET LO RICALCOLO SE NON POSSO USARE QUELLO DEL PROGETTO
                if(($mapSrid[$mapName] != $this->projectSrid) || ($mapMaxScale[$mapName] != $projectMaxScale)){
                    $this->mapsetExtent = $this->_calculateExtentFromCenter($this->mapsetMaxScale, $this->mapsetSrid);
                }
            }else{
                $v = preg_split('/[\s]+/', $mapExtent[$mapName]);
                for ($i=0;$i<count($v);$i++){
                    $v[$i] = round(floatval($v[$i]),8);
                }
                $this->mapsetExtent = $v;
            }

            if($symbolsList[$mapName]) $this->layerText .= $this->_getSymbolText($symbolsList[$mapName]);
*/
            $this->_writeFile($mapName);

        }

        return $mapName;
    }

    function _writeFile(&$mapName){

        $mapFilePath = ROOT_PATH . QGIS_SAVE_PATH . '/' .$mapName . '.qgs';
        $fileContent = $this->qGisDOMs[$mapName]->saveXML();


        if (false === ($f = fopen ($mapFilePath,"w"))) {
            $errorMsg = "Could not open $mapFilePath for writing";
            GCError::register($errorMsg);
            return;
        }
        if (false === (fwrite($f, substr($fileContent, strpos($fileContent, "\n") + 1)))) {
            $errorMsg = "Could not write to $mapFilePath";
            GCError::register($errorMsg);
            return;
        }
        fclose($f);

        return;
    }

    function _getCacheType($fileName){
        $ret = array('type'=>MAPPROXY_CACHE_TYPE);
        if(MAPPROXY_CACHE_TYPE == 'mbtiles') $ret["filename"] = $fileName.'.mbtiles';
        return $ret;
    }

    function _getPrintFormat(){

        $formatText ="
OUTPUTFORMAT
    NAME \"aggpng24\"
    DRIVER \"AGG/PNG\"
    MIMETYPE \"image/png\"
    IMAGEMODE RGB
    EXTENSION \"png\"
    FORMATOPTION \"INTERLACE=OFF\"
    TRANSPARENT OFF
END";
        return $formatText;

    }

    function _isDriverSupported($driverName) {
        $mapserverSupport = ms_GetVersion();

        list($driver, $format) = explode('/', $driverName);

        // check on support
        if (preg_match_all ("/SUPPORTS=([A-Z_]+)/", $mapserverSupport, $supports)) {
            if (!in_array($driver, $supports[1]))
                return false;
        }

        // check on output
        if (preg_match_all ("/OUTPUT=([A-Z]+)/", $mapserverSupport, $outputs)) {
            if (!in_array($format, $outputs[1]))
                return false;
        }

        return true;
    }

    function _getOutputFormat($mapName){
            $formatText = '';
            $sql="select distinct e_outputformat.* from ".DB_SCHEMA.".e_outputformat;";
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
           // print_debug($sql);
            $numResults = $stmt->rowCount();
            if($numResults > 0) {
                while($row = $stmt->fetch(PDO::FETCH_ASSOC)){
                    // ignore outputformat  with unsupported driver
                    if (!$this->_isDriverSupported($row["outputformat_driver"]))
                        continue;
                    $formatText .= "OUTPUTFORMAT
    NAME \"".$row["outputformat_name"]."\"
    DRIVER \"".$row["outputformat_driver"]."\"
    MIMETYPE \"".$row["outputformat_mimetype"]."\"
    IMAGEMODE ".$row["outputformat_imagemode"] ."
    EXTENSION \"".$row["outputformat_extension"]."\"
    FORMATOPTION \"INTERLACE=OFF\"";
                    if($row["outputformat_option"]) $formatText.= "\n".$row["outputformat_option"];
                    $formatText .= "\nEND\n";
                }
            } else {
                $formatText = file_get_contents (ROOT_PATH."config/mapfile.outputformats.inc");
            }
            return $formatText;
        }

    function _getEncoding(){
        $ows_wfs_encoding ='';
        $sql = "select charset_encodings_name
            from ".DB_SCHEMA.".e_charset_encodings INNER JOIN ".DB_SCHEMA.".project on e_charset_encodings.charset_encodings_id=project.charset_encodings_id
            where project_name=:projectName";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(array(':projectName' => $this->projectName));
        $res=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!empty($res)) $ows_wfs_encoding = "\t\"wfs_encoding\"\t\"".$res['charset_encodings_name']."\"\n".
                                            "\t\t\"wms_encoding\"\t\"".$res['charset_encodings_name']."\"\n";
        return $ows_wfs_encoding;
    }


    function _getLegendSettings(){
        // default font
        $legendFont = 'verdana';

        // get project font if assigned
        $sql="SELECT imagelabel_font,icon_w,icon_h,legend_font_size FROM ".DB_SCHEMA.".project WHERE project_name = ?;";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(array($this->projectName));

        $numResults = $stmt->rowCount();
        if($numResults > 0) {
            $row=$stmt->fetch(PDO::FETCH_ASSOC);
            if (trim($row['imagelabel_font']) != '')
                $legendFont = $row['imagelabel_font'];
            $iconW = $row['icon_w']?$row['icon_w']:16;
            $iconH = $row['icon_h']?$row['icon_h']:10;
            $fontSize = $row['legend_font_size']?$row['legend_font_size']:10;
        }

        // mapfile snippet
        $formatText = "LEGEND\n" .
                      "    STATUS ON\n" .
                      "    OUTLINECOLOR 0 0 0 \n" .
                      "    KEYSIZE ".$iconW." ".$iconH."\n" .
                      "    LABEL\n" .
                      "       TYPE TRUETYPE\n" .
                      "       FONT '{$legendFont}'\n" .
                      "       SIZE ".$fontSize."\n" .
                      "       COLOR 0 0 0\n" .
                      "    END\n" .
                      "END\n";

        return $formatText;
    }


    function _getSymbolText($aSymbols){
                $_in = GCApp::prepareInStatement($aSymbols);
                $sqlParams = $_in['parameters'];
                $inQuery = $_in['inQuery'];

                $sql="select * from ".DB_SCHEMA.".symbol
                    where symbol_name in (".$inQuery.");";

                $stmt = $this->db->prepare($sql);
                $stmt->execute($sqlParams);
                $res = $stmt->fetchAll();

        $smbText=array();
        for($i=0;$i<count($res);$i++){
            $smbText[]="SYMBOL";
            $smbText[]="\tNAME \"".$res[$i]["symbol_name"]."\"";
            if($res[$i]["symbol_type"])$smbText[]="\tTYPE ".$res[$i]["symbol_type"];
            if($res[$i]["font_name"]) $smbText[]="\tFONT \"".$res[$i]["font_name"]."\"";
            //if($res[$i]["ascii_code"]) $smbText[]="\tCHARACTER \"&#".$res[$i]["ascii_code"].";\"";//IN MAPSERVER 5.0 SEMBRA DARE PROBLEMI
            if($res[$i]["ascii_code"]) {
                if($res[$i]["ascii_code"]==34)
                    $smbText[]="\tCHARACTER '".chr($res[$i]["ascii_code"])."'";
                else if($res[$i]["ascii_code"]==92)
                    $smbText[]="\tCHARACTER '".chr($res[$i]["ascii_code"]).chr($res[$i]["ascii_code"])."'";
                else
                    $smbText[]="\tCHARACTER \"".chr($res[$i]["ascii_code"])."\"";

            }
            if($res[$i]["filled"]) $smbText[]="\tFILLED TRUE";
            if($res[$i]["points"]) $smbText[]="\tPOINTS ".$res[$i]["points"]." END";
            if($res[$i]["image"]) $smbText[]="\tIMAGE \"".$res[$i]["image"]."\"";
            if($res[$i]["symbol_def"]) $smbText[]=$res[$i]["symbol_def"];
            $smbText[]="END";
        }
        $txt = "\n###### SYMBOLS #######\n";
        $txt.= implode("\n",$smbText);
        return $txt;
    }

    function _calculateExtentFromCenter($maxScale, $srid) {
        $sql = "SELECT ".
        "st_x(st_transform(st_geometryfromtext('POINT('||".$this->xCenter."||' '||".$this->yCenter."||')',".$this->projectSrid."),$srid)) as xc, ".
        "st_y(st_transform(st_geometryfromtext('POINT('||".$this->xCenter."||' '||".$this->yCenter."||')',".$this->projectSrid."),$srid)) as yc, ".
        "CASE WHEN proj4text like '%+units=m%' then 'm' ".
        "WHEN proj4text LIKE '%+units=ft%' OR proj4text LIKE '%+units=us-ft%' THEN 'ft' ".
        "WHEN proj4text LIKE '%+proj=longlat%' THEN 'dd' ELSE 'm' END AS um ".
        "FROM spatial_ref_sys WHERE srid=:srid;";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(array(':srid' => $srid));
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        $x = $row["xc"];
        $y = $row["yc"];
        $factor = GCAuthor::$aInchesPerUnit[$row["um"]];
        $precision = $row["um"] == "dd"?6:2;
        $maxResolution = $maxScale/( MAP_DPI * $factor );
        $extent = $maxResolution * TILE_SIZE * 4; //4 tiles??

        return array(
            0 => round($x - $extent, $precision),
            1 => round($y - $extent, $precision),
            2 => round($x + $extent, $precision),
            3 => round($y + $extent, $precision)
        );
    }

    function _setMapProjections(){
        //COSTRUISCO UNA LISTA DI PARAMETRI PER OGNI SRID CONTENUTO NEL PROGETTO PER EVITARE DI CALCOLARLI PER OGNI LAYER
        $sql="SELECT DISTINCT srid, projparam FROM ".DB_SCHEMA.".layer
            INNER JOIN ".DB_SCHEMA.".catalog USING(catalog_id)
            INNER JOIN ".DB_SCHEMA.".project_srs using(project_name)
            WHERE project_name = ?;";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(array($this->projectName));

        //GENERO LA LISTA DEGLI EXTENT PER I SISTEMI DI RIFERIMENTO
        while($row =  $stmt->fetch(PDO::FETCH_ASSOC)){
            $this->srsParams[$row["srid"]] = $row["projparam"];
        }

        //ELENCO DEI SISTEMI DI RIFERIMENTO NEI QUALI SI ESPONE IL SERVIZIO:(GRIDS)
        //DEFAULT WEB MERCATOR
        $epsgList = array("EPSG:3857");
        $gridList = array(
            "epsg3857" => array(
                'base'=>'GLOBAL_WEBMERCATOR',
                'srs'=>'EPSG:3857',
                'num_levels'=>MAPPROXY_GRIDS_NUMLEVELS
            )
        );

        $sql = "SELECT srid,".
        "st_x(st_transform(st_geometryfromtext('POINT('||".$this->xCenter."||' '||".$this->yCenter."||')',".$this->projectSrid."),srid)) as xc, ".
        "st_y(st_transform(st_geometryfromtext('POINT('||".$this->xCenter."||' '||".$this->yCenter."||')',".$this->projectSrid."),srid)) as yc, ".
        "CASE WHEN proj4text like '%+units=m%' then 'm' ".
        "WHEN proj4text LIKE '%+units=ft%' OR proj4text LIKE '%+units=us-ft%' THEN 'ft' ".
        "WHEN proj4text LIKE '%+proj=longlat%' THEN 'dd' ELSE 'm' END AS um ".
        "FROM ".DB_SCHEMA.".project_srs inner join spatial_ref_sys using(srid) WHERE srid<>3857 AND project_name = ?;";
        $stmt = $this->db->prepare($sql);

        $stmt->execute(array($this->projectName));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $srs = "epsg".$row["srid"];
            $epsgList[] = "EPSG:".$row["srid"];
            $gridList[$srs] = array("srs"=>"EPSG:".$row["srid"]);
            $gridList[$srs]["res"] = array();
            $convFact = GCAuthor::$aInchesPerUnit[$row["um"]]*MAP_DPI;
            $precision = $row["um"] == "dd"?10:2;
            if (defined('DEFAULT_SCALE_LIST')) {
                $scaleList = preg_split('/[\s]+/', DEFAULT_SCALE_LIST);
            } else {
                $scaleList = GCAuthor::$defaultScaleList;
            }
            foreach($scaleList as $scaleValue)  $gridList[$srs]["res"][] = round((float)$scaleValue/$convFact, $precision);

            $aExtent=array();
            $extent = round($gridList[$srs]["res"][0] * TILE_SIZE);
            //echo $extent;return;
            $aExtent[0] = round((float)($row["xc"] - $extent), $precision);
            $aExtent[1] = round((float)($row["yc"] - $extent), $precision);
            $aExtent[2] = round((float)($row["xc"] + $extent), $precision);
            $aExtent[3] = round((float)($row["yc"] + $extent), $precision);
            $gridList[$srs]["bbox"] = $aExtent;
            $gridList[$srs]["bbox_srs"] = "EPSG:".$row["srid"];
        };

/*      while($row =  $stmt->fetch(PDO::FETCH_ASSOC)){
            $epsgList[] = "EPSG:".$row["srid"];
            if(isset($row["bbox"])){
                $gridList["epsg".$row["srid"]] = array("srs"=>"EPSG:".$row["srid"]);
                $gridList["epsg".$row["srid"]]["bbox"] = preg_split('/[\s]+/', $row["bbox"]);
                $gridList["epsg".$row["srid"]]["bbox_srs"] = "EPSG:4326";
                if(isset($row["resolutions"])){
                    $res = preg_split('/[\s]+/', $row["resolutions"]);
                    if(count($res)==1)
                        $gridList["epsg".$row["srid"]]["max_res"] = $res[0];
                    elseif(count($res)>1)
                        $gridList["epsg".$row["srid"]]["resolutions"] = $res;
                }
            }
        }*/

        $this->epsgList = $epsgList;
        $this->grids = $gridList;
    }

    function _writeMapProxyConfig($mapName){
        $config = array(
            'services'=>array(
                'tms'=>array(
                    'srs'=>$this->epsgList,
                    'use_grid_names'=>false,
                    'origin'=>'nw'
                ),
                'kml'=>array(
                    'use_grid_names'=>false
                ),
                'wmts'=>array(
                    'srs'=>$this->epsgList
                ),
                'wms'=>array(
                    'srs'=>$this->epsgList,
                    'md'=>array(
                        'title'=>$this->mapsetTitle,
                        'abstract'=>$this->mapsetTitle,
                        'online_resource'=>GISCLIENT_OWS_URL."?project=".$this->projectName."&amp;map=".$mapName,
                        'contact'=>array(
                            //ma serve sta roba?!?!
                            'person'=>'Roberto'
                        ),
                        'access_constraints'=>'None',
                        'fees'=>'None'
                    )
                )
            ),
            'sources'=>array(
                'mapserver_wms_source'=>array(
                    'type'=>'wms',
                    'supported_srs'=>$this->epsgList,
                    'req'=>array(
                        'url'=>MAPSERVER_URL,
                        'map'=>ROOT_PATH.'map/'.$this->projectName."/".$mapName.".map",
                        'format'=>'image/png',
                        'transparent'=> true,
                        'exceptions'=> 'inimage'
                    ),
                    'coverage'=>array(
                        'bbox'=>$this->mapsetExtent,
                        'srs'=>'EPSG:'.$this->mapsetSrid
                    ),
                    'image'=>array(
                        'transparent_color'=>'#ffffff',
                        'transparent_color_tolerance'=>0
                    )
                ),
                'mapserver_source'=>array(
                    'type'=>'mapserver',
                    'req'=>array(
                        'transparent'=>true,
                        'map'=>ROOT_PATH.'map/'.$this->projectName."/".$mapName.".map",
                        'exceptions'=> 'inimage'
                    ),
                    'coverage'=>array(
                        'bbox'=>$this->mapsetExtent,
                        'srs'=>'EPSG:'.$this->mapsetSrid
                    ),
                    'image'=>array(
                        'transparent_color'=>'#ffffff',
                        'transparent_color_tolerance'=>0
                    ),
                    'mapserver'=>array(
                        'binary'=>MAPSERVER_BINARY_PATH,
                        'working_dir'=>ROOT_PATH.'map/'.$this->projectName
                    )

                )
            ),
            'globals'=>array(
                'srs'=>array(
                    'proj_data_dir'=>PROJ_LIB
                ),
                'cache'=>array(
                    'type'=>MAPPROXY_CACHE_TYPE,
                    'base_dir'=>MAPPROXY_CACHE_PATH.$this->projectName.'/',
                    'lock_dir'=>MAPPROXY_CACHE_PATH.'locks/',
                    'tile_lock_dir'=>MAPPROXY_CACHE_PATH.'tile_locks/'
                )
            )
        );

        if(defined('MAPPROXY_DEMO') && MAPPROXY_DEMO) $config["services"]["demo"]=array('name'=>$mapName);
        if($this->grids) $config["grids"] = $this->grids;
        if($this->mpxCaches && count($this->mpxCaches[$mapName]) > 0) $config["caches"] = $this->mpxCaches[$mapName];
        if($this->mpxLayers) $config["layers"] = array_values($this->mpxLayers[$mapName]);

        if(count($this->grids)==0) unset($config["grids"]);


        //if(!is_dir(MAPPROXY_FILES)) mkdir(MAPPROXY_FILES);
        //if(!is_dir(ROOT_PATH.'mapproxy/'.$this->projectName)) mkdir(ROOT_PATH.'mapproxy/'.$this->projectName);

        //Verifica esistenza cartella dei tiles
        if(!is_dir(TILES_CACHE)) mkdir(TILES_CACHE);
        if(!is_dir(TILES_CACHE.$this->projectName)) mkdir(TILES_CACHE.$this->projectName);

        //$content = yaml_emit($config,YAML_UTF8_ENCODING);

        print_debug($config,null,'yaml');
        $content = Spyc::YAMLDump($config,1,0);

        //file_put_contents(MAPPROXY_FILES.$mapName.'.yaml', $content);
        //AGGIUNGO I LIVELLI WMS (che non hanno layer definiti nella tabella layer)


        $mapfileDir = ROOT_PATH.'map/';
        $projectDir = $mapfileDir.$this->projectName.'/';

        //CREO IL FILE DI CONFIGURAZIONE SE NON ESISTE
        $wsgiConfigFile = $mapfileDir.$this->projectName.".wsgi";
        if(!file_exists ($wsgiConfigFile)){
            $content = "activate_this = '".MAPPROXY_PATH."bin/activate_this.py'\n";
            $content.= "execfile(activate_this, dict(__file__=activate_this))\n";
            $content.= "from mapproxy.multiapp import make_wsgi_app\n";
            $content.= "application = make_wsgi_app('".$projectDir."', allow_listing=True)";
            file_put_contents($wsgiConfigFile, $content);
        }


        file_put_contents($projectDir.$mapName.'.yaml', $content);


    }


}
