<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * Description of gcFeatureQGis
 *
 * @author geosim2
 */
class gcFeatureQGis extends gcFeature {

    /**
     * @var array $qFeature QGis feature data
     */
    private $qFeature;
    /**
     * [$layertype description]
     * @var array $layertypes array from e_layertype table in gisclient database
     */
    private $layertypes = array();

    /**
     * [__destruct description]
     */
    function __destruct() {
        parent::__destruct();
    }

    /**
     * [__construct description]
     * @param [type] $i18n [description]
     */
    function __construct($i18n = null) {
        parent::__construct($i18n);
        $stmt = $this->db->query('SELECT layertype_id, layertype_name FROM ' . DB_SCHEMA . '.e_layertype');
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $this->layertypes[$row['layertype_id']] = trim($row['layertype_name']);
        }
    }

    /**
     * [initFeature description]
     * @param  [type] $layerId [description]
     * @return [type]          [description]
     */
    public function initFeature($layerId) {
        parent::initFeature($layerId);
        $this->qFeature = parent::getFeatureData();
        list($usec, $sec) = explode(' ', microtime());
        $this->qFeature['qgis_id'] = $this->qFeature['layer_name'] . date('YmdHis', $sec) . substr($usec, 2, 6);
    }

    public function getFeatureData() {
        return $this->qFeature;
    }

    /**
     * Set feature data
     *
     * @param array $qFeature
     */
    public function setFeatureData(array $qFeature) {
        $this->qFeature = $qFeature;
    }

    /**
     * [getFeatureTreeNode description]
     * @return DOMDocument XML entry for QGis layer tree reperesenting single layer
     */
    public function getFeatureTreeNode() {
        $resDom = new DOMDocument;
        $treeElem = $resDom->createElement('layer-tree-layer');
        $treeElem->setAttribute('expanded', '1');
        $treeElem->setAttribute('checked', 'Qt::Checked');
        $treeElem->setAttribute('id', $this->qFeature['qgis_id']);
        $treeElem->setAttribute('name', $this->qFeature['layer_name']); // **** O layer title?
        $custPropElem = $resDom->createElement('customproperties');
        $treeElem->appendChild($custPropElem);
        $resDom->appendChild($treeElem);
        return $resDom;
    }

    /**
     * [getFeatureLegendNode description]
     * @return DOMDocument XML entry for QGis legend reperesenting single layer
     */
    public function getFeatureLegendNode() {
        $resDom = new DOMDocument;
        $legendElem = $resDom->createElement('legendlayer');
        $legendElem->setAttribute('drawingOrder', '-1');
        $legendElem->setAttribute('open', 'true');
        $legendElem->setAttribute('checked', 'Qt::Checked');
        $legendElem->setAttribute('name', $this->qFeature['layer_name']); // **** O layer title?
        $legendElem->setAttribute('showFeatureCount', '0');

        $filegroupElem = $resDom->createElement('filegroup');
        $filegroupElem->setAttribute('open', 'true');
        $filegroupElem->setAttribute('hidden', 'false');

        $legendlayerElem = $resDom->createElement('legendlayerfile');
        $legendlayerElem->setAttribute('isInOverview', '0');
        $legendlayerElem->setAttribute('id', $this->qFeature['qgis_id']);
        $legendlayerElem->setAttribute('visible', '1');

        $filegroupElem->appendChild($legendlayerElem);
        $legendElem->appendChild($filegroupElem);
        $resDom->appendChild($legendElem);

        return $resDom;
    }

    /**
     * [getFeatureMapNode description]
     * @return DOMDocument XML entry for QGis maplayer reperesenting single layer
     */
    public function getFeatureMapNode ($layerData) {
        $resDom = new DOMDocument;

        // **** Max and min scale
        $maxScale = "1e+08";
        $minScale = "0";
        if ($layerData) {
            if (!empty($this->qFeature['maxscale']))
                $maxScale = $this->qFeature['maxscale'];
            else if (!empty($layerData['layergroup_maxscale']))
                $maxScale = $layerData['layergroup_maxscale'];

            if (!empty($this->qFeature['minscale']))
                $minScale = $this->qFeature['minscale'];
            else if (!empty($layerData['layergroup_minscale']))
                $minScale = $layerData['layergroup_minscale'];
        }

        $maplayerElem = $resDom->createElement('maplayer');
        $maplayerElem->setAttribute('minimumScale', $minScale);
        $maplayerElem->setAttribute('maximumScale',$maxScale);
        $maplayerElem->setAttribute('simplifyDrawingHints',"0");
        $maplayerElem->setAttribute('minLabelScale', $minScale);
        $maplayerElem->setAttribute('maxLabelScale', $maxScale);
        $maplayerElem->setAttribute('simplifyDrawingTol',"1");
        $maplayerElem->setAttribute('geometry', $this->layertypes[$this->qFeature['layertype_id']]);
        $maplayerElem->setAttribute('simplifyMaxScale',"1");
        $maplayerElem->setAttribute('type',"vector");
        $maplayerElem->setAttribute('hasScaleBasedVisibilityFlag',"1");
        $maplayerElem->setAttribute('simplifyLocal',"1");
        $maplayerElem->setAttribute('scaleBasedLabelVisibilityFlag',"0");

        // **** Set QGis ID
        $idElem =  $resDom->createElement('id', $this->qFeature['qgis_id']);
        $maplayerElem->appendChild($idElem);

        // **** Set datasource
        $connString = $this->_getLayerConnection();
        $dsElem =  $resDom->createElement('datasource', $connString);
        $maplayerElem->appendChild($dsElem);

        // **** Keywordlist
        $kwElem = $resDom->createElement('keywordList');
        $kwvElem = $resDom->createElement('value', '');
        $kwElem->appendChild($kwvElem);
        $maplayerElem->appendChild($kwElem);

        // ** layername
        $lnameElem = $resDom->createElement('layername', $this->qFeature['layer_name']); // **** O layer title?
        $maplayerElem->appendChild($lnameElem);

        // **** src
        $srcElem = $resDom->createElement('src');
        $refsysDom = $this->getSpatialRefSysNode($this->qFeature['data_srid']);
        $refsysRes = $refsysDom->getElementsByTagName('spatialrefsys')->item( 0 );
        $refsysImport = $resDom->importNode($refsysRes, TRUE);
        $srcElem->appendChild($refsysImport);
        $maplayerElem->appendChild($srcElem);

        // **** Provider
        $prElem = $resDom->createElement('provider', 'postgres'); // **** TODO: handle other providers
        $prElem->setAttribute('encoding', 'System');
        $maplayerElem->appendChild($prElem);

        // **** Edittypes
        $fieldsDom = $this->_getEditTypesNode();
        $fieldsRef = $fieldsDom->getElementsByTagName('edittypes')->item(0);
        $fieldsImport = $resDom->importNode($fieldsRef, TRUE);
        $maplayerElem->appendChild($fieldsImport);

        //**************************************************
        //**** STYLES

        // **** TODO: optimize query
        $sql = "select * from gisclient_3.class c
                left join gisclient_3.style s using (class_id)
                left join gisclient_3.symbol using (symbol_name)
                left join gisclient_3.e_pattern using(pattern_id)
                where c.layer_id=?
                order by style_order";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$this->qFeature['layer_id']]);
        $res = $stmt->fetchAll();


        $resDom->appendChild($maplayerElem);
        return $resDom;
    }

    /**
     * [_getLayerConnection description]
     * @return [type] [description]
     */
    private function _getLayerConnection() {
        $layerConnStr = '';
        if ($this->qFeature["layertype_id"] == 10 && !$this->qFeature["tileindex"]) {//TILERASTER

        }
        else {
            switch ($this->qFeature["connection_type"]) {
                case MS_SHAPEFILE: //Local folder shape and raster

                    break;

                case MS_WMS:

                    break;

                case MS_WFS:

                    break;

                case MS_POSTGIS:
                    $layerConnStr = $this->qFeature['connection_string'];
                    $layerConnStr = str_replace('localhost', $_SERVER['SERVER_NAME'], $layerConnStr);
                    $layerConnStr .= " sslmode=disable key='gc_objid' table=\"";
                    $tblQuery = $this->_getLayerData();
                    $tblQuery = substr($tblQuery, 13);
                    $tblQuery = substr($tblQuery, 0, -7);
                    $layerConnStr .= $tblQuery . '" (gc_geom)';
                    if ($this->qFeature['data_filter'])
                        $layerConnStr .= ' sql=' . $this->qFeature['data_filter'];
                    break;

                case MS_ORACLESPATIAL:

                    break;

                case MS_SDE:
                    break;

                case MS_OGR:

                    break;
                case MS_GRATICULE:
                    break;
                case MS_MYGIS:
                    break;
                    break;
                case MS_PLUGIN:
                    break;
            }
        }
        return $layerConnStr;
    }

    /**
     * [getSpatialRefSysNode description]
     * @param  int $srid srid of layer/project
     * @return DOMDocument      DOM node for QGIS spatialrefsys entry
     */
    public function getSpatialRefSysNode($srid) {
        $resDom = new DOMDocument;
        if (!$srid) {
            return $resDom;
        }

        $sql =  'SELECT auth_name, auth_srid, proj4text,
                        array_to_string(regexp_matches(srtext, \'^[^"]*"([^"]*)\'), \';\') AS description,
                        array_to_string(regexp_matches(proj4text, \'proj=([^ ]*)\'), \';\') AS projectionacronym,
                        array_to_string(regexp_matches(proj4text, \'ellps=([^ ]*)\'), \';\') AS ellipsoidacronym
                        FROM spatial_ref_sys WHERE srid=?';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$srid]);
        $res = $stmt->fetchAll();

        if (count($res) != 1) {
            return $resDom;
        }
        $srsData = $res[0];

        $srsItem = $resDom->createElement('spatialrefsys');
        $proj4Item = $resDom->createElement('proj4', $srsData['proj4text']);
        $srsItem->appendChild($proj4Item);
        $sridItem = $resDom->createElement('srid', $srsData['auth_srid']);
        $srsItem->appendChild($sridItem);
        $authIdItem = $resDom->createElement('authid', $srsData['auth_name'] . ':' . $srsData['auth_srid']);
        $srsItem->appendChild($authIdItem);
        $descItem = $resDom->createElement('description', $srsData['description']);
        $srsItem->appendChild($descItem);
        $projAcrItem = $resDom->createElement('projectionacronym', $srsData['projectionacronym']);
        $srsItem->appendChild($projAcrItem);
        $elAcrItem = $resDom->createElement('ellipsoidacronym', $srsData['ellipsoidacronym']);
        $srsItem->appendChild($elAcrItem);

        $resDom->appendChild($srsItem);
        return $resDom;

    }

    /**
     * [_getEditTypesNode description]
     * @return DOMDocument      DOM node for QGIS edittypes entry
     */
    private function _getEditTypesNode() {
        $resDom = new DOMDocument;

        $etElem = $resDom->createElement('edittypes');

        foreach ($this->qFeature['fields'] as $field) {
            $etField = $resDom->createElement('edittype');
            $etField->setAttribute('widgetv2type', 'TextEdit'); // **** TODO: map mapserver/qGis field type?
            $etField->setAttribute('name', $field['field_name']);
            $widgetElem = $resDom->createElement('widgetv2config');
            $widgetElem->setAttribute('IsMultiline', '0');
            $widgetElem->setAttribute('fieldEditable', '1');
            $widgetElem->setAttribute('UseHtml', '0');
            $widgetElem->setAttribute('labelOnTop', '0');

            $etField->appendChild($widgetElem);
            $etElem->appendChild($etField);
        }

        $resDom->appendChild($etElem);
        return $resDom;
    }

}
