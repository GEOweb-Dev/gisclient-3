<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * Description of gc_mapObj
 *
 * @author Marco Giraudi
 */


trait gc_mcClass {
	public function __set($name, $value) {
			$this->msObj->$name = $value;
	}

	public function __get($name) {
			return $this->msObj->$name;
	}

	public function __call($name, $args) {
		foreach($args as &$arg) {
			$arg = (is_object($arg) && @property_exists($arg, 'msObj')) ? $arg->msObj : $arg;
		}
			$res = call_user_func_array([$this->msObj, $name], $args);
			return $res;
	}
}

class gc_mapObj {
	use gc_mcClass;
	public $msObj = null;
	/**
     * @var array $gcMapLayerNames
     */
    private $gcMapLayerNames = array();
    /**
     * @var array $gcMapGroupNames
     */
    private $gcMapGroupNames = array();
    /**
     * @var array $gcMapGroupLayerIdx
     */
    private $gcMapGroupLayerIdx = array();

	/**
     * [__construct description]
     * @param [type] $map_file_name [description]
     * @param [type] $new_map_file_name [description]
     */
	public function __construct($map_file_name = '', $new_map_file_name = null) {
		if (!empty($new_map_file_name)) {
			$this->msObj = new mapObj($map_file_name, $new_map_file_name);
		}
		else {
			$this->msObj = new mapObj($map_file_name);
		}
		for ($i = 0; $i < $this->msObj->numlayers; $i++) {
            $layerObj = $this->msObj->getLayer($i);
            $this->gcMapLayerNames[] = $layerObj->name;
            if (in_array($layerObj->group, $this->gcMapGroupNames) === false) {
                $this->gcMapGroupNames[] = $layerObj->group;
                $this->gcMapGroupLayerIdx[$layerObj->group]  = array($layerObj->index);
            }
            else {
                $this->gcMapGroupLayerIdx[$layerObj->group][] = $layerObj->index;
            }
        }
	}

	public function getAllLayerNames() {
		$this->gcMapLayerNames = array();
		$this->gcMapGroupNames = array();
		$this->gcMapGroupLayerIdx = array();
		for ($i = 0; $i < $this->msObj->numlayers; $i++) {
            $layerObj = $this->msObj->getLayer($i);
            $this->gcMapLayerNames[] = $layerObj->name;
        }
        return $this->gcMapLayerNames;
    }

    public function getAllGroupNames() {
		$this->gcMapLayerNames = array();
		$this->gcMapGroupNames = array();
		$this->gcMapGroupLayerIdx = array();
		for ($i = 0; $i < $this->msObj->numlayers; $i++) {
            $layerObj = $this->msObj->getLayer($i);
            $this->gcMapLayerNames[] = $layerObj->name;
            if (in_array($layerObj->group, $this->gcMapGroupNames) === false) {
                $this->gcMapGroupNames[] = $layerObj->group;
                $this->gcMapGroupLayerIdx[$layerObj->group]  = array($layerObj->index);
            }
            else {
                $this->gcMapGroupLayerIdx[$layerObj->group][] = $layerObj->index;
            }
        }
        return $this->gcMapGroupNames;
    }

    public function getLayersIndexByGroup($groupname) {
		$this->gcMapLayerNames = array();
		$this->gcMapGroupNames = array();
		$this->gcMapGroupLayerIdx = array();
		for ($i = 0; $i < $this->msObj->numlayers; $i++) {
            $layerObj = $this->msObj->getLayer($i);
            $this->gcMapLayerNames[] = $layerObj->name;
            if (in_array($layerObj->group, $this->gcMapGroupNames) === false) {
                $this->gcMapGroupNames[] = $layerObj->group;
                $this->gcMapGroupLayerIdx[$layerObj->group]  = array($layerObj->index);
            }
            else {
                $this->gcMapGroupLayerIdx[$layerObj->group][] = $layerObj->index;
            }
        }
        if (in_array($groupname, $this->gcMapGroupNames) === false) {
            return array();
        }
        else {
            return $this->gcMapGroupLayerIdx[$groupname];
        }
    }

	public function set($property_name, $property_value) {
        if (empty($property_name)) {
            return MS_FAILURE;
        }
		try {
			$this->msObj->$property_name = $property_value;
		}
		catch (Exception $ex) {
			return MS_FAILURE;
		}
    }

	public function outputformat_set($property_name, $property_value) {
        if (empty($property_name)) {
            return MS_FAILURE;
        }
		try {
			$of = $this->msObj->getOutputFormat(0);
			if (is_null($of)) {
				$of = new  mapscript.outputFormatObj('AGG/PNG');
			}
			$of->$property_name = $property_value;
			$this->msObj->setOutputFormat($of);
		}
		catch (Exception $ex) {
			return MS_FAILURE;
		}
    }

    public function extent_set($property_name, $property_value) {
        if (empty($property_name)) {
            return MS_FAILURE;
        }
		try {
			$this->msObj->extent->$property_name = $property_value;
		}
		catch (Exception $ex) {
			return MS_FAILURE;
		}
    }

    public function web_set($property_name, $property_value) {
        if (empty($property_name)) {
            return MS_FAILURE;
        }
		try {
			$this->msObj->web->$property_name = $property_value;
		}
		catch (Exception $ex) {
			return MS_FAILURE;
		}
    }

    public function extent_setextent($minx, $miny, $maxx, $maxy) {
        $this->msObj->setExtent($minx, $miny, $maxx, $maxy);
    }

	public function getMetaData($name) {
        if (empty($name)) {
            return '';
        }
        return $this->msObj->web->metadata->get($name);
    }

    public function setMetaData($name, $value) {
        try {
            return $this->msObj->web->metadata->set($name, $value);
        }
        catch (Exception $e) {
            return MS_FAILURE;
        }
    }

    public function getLayer($idx) {
        $layer = $this->msObj->getLayer($idx);
        $res = new gc_layerObj();
        $res->msObj = $layer;
        return $res;
    }

    public function getLayerByName($name) {
        $layer = $this->msObj->getLayerByName($name);
        $res = new gc_layerObj();
        $res->msObj = $layer;
        return $res;
    }

	public function draw() {
		$img = $this->msObj->draw();
        $res = new gc_imageObj($this->msObj->width, $this->msObj->height);
        $res->msObj = $img;
        return $res;
	}
}

/**
 * Description of gc_layerObj
 *
 * @author Marco Giraudi
 */
class gc_layerObj {
	use gc_mcClass;
	public $msObj = null;
	public function __construct($arg1 = null) {
		$arg_1 = (is_object($arg1) && property_exists($arg1, 'msObj')) ? $arg1->msObj : $arg1;
		$this->msObj = new layerObj($arg_1);
	}

	public function set($property_name, $property_value) {
        if (empty($property_name)) {
            return MS_FAILURE;
        }
		try {
			$this->msObj->$property_name = $property_value;
		}
		catch (Exception $ex) {
			return MS_FAILURE;
		}
    }

	public function getMetaData($name) {
        if (empty($name)) {
            return '';
        }
        return $this->msObj->metadata->get($name);
    }

    public function setMetaData($name, $value) {
        try {
            return $this->msObj->metadata->set($name, $value);
        }
        catch (Exception $e) {
            return MS_FAILURE;
        }
    }

	public function setProcessing($directive) {
        return $this->msObj->addProcessing($directive);
    }

	public function getClass($idx) {
        $class = $this->msObj->getClass($idx);
        $res = new gc_classObj();
        $res->msObj = $class;
        return $res;
    }

	public function getProcessing($idx = null) {
		if (isset($idx)) {
			return $this->msObj->getProcessing($idx);
		}
		$res = array();
		for ($i = 0; $i < $this->msObj->numprocessing; $i++) {
			$res[] = $this->msObj->getProcessing($i);
		}
		return $res;
	}

	public function queryByRect($rectObj) {
		return $this->msObj->queryByRect($this->msObj->map, $rectObj->msObj);
	}
}

/**
 * Description of gc_classObj
 *
 * @author Marco Giraudi
 */
class gc_classObj {
	use gc_mcClass;
	public $msObj = null;
	public function __construct($arg1 = null) {
		$arg_1 = (is_object($arg1) && property_exists($arg1, 'msObj')) ? $arg1->msObj : $arg1;
		$this->msObj = new classObj($arg_1);
	}

	public function set($property_name, $property_value) {
        if (empty($property_name)) {
            return MS_FAILURE;
        }
		try {
			$this->msObj->$property_name = $property_value;
		}
		catch (Exception $ex) {
			return MS_FAILURE;
		}
    }

	public function getStyle($idx) {
        $style = $this->msObj->getStyle($idx);
        $res = new gc_styleObj();
        $res->msObj = $style;
        return $res;
    }

	public function getMetaData($name) {
        if (empty($name)) {
            return '';
        }
        return $this->msObj->metadata->get($name);
    }

    public function setMetaData($name, $value) {
        try {
            return $this->msObj->metadata->set($name, $value);
        }
        catch (Exception $e) {
            return MS_FAILURE;
        }
    }

	public function createLegendIcon($width, $height, $map=NULL, $layer = NULL) {
        $img = $this->msObj->createLegendIcon($this->msObj->layer->map, $this->msObj->layer, $width, $height);
        $res = new gc_imageObj($width, $height);
        $res->msObj = $img;
        return $res;
    }

}

/**
 * Description of gc_styleObj
 *
 * @author Marco Giraudi
 */
class gc_styleObj {
	use gc_mcClass;
	public $msObj = null;
	public function __construct($arg1 = null) {
		$arg_1 = (is_object($arg1) && property_exists($arg1, 'msObj')) ? $arg1->msObj : $arg1;
		$this->msObj = new styleObj($arg_1);
	}

	public function set($property_name, $property_value) {
        if (empty($property_name)) {
            return MS_FAILURE;
        }
		try {
			$this->msObj->$property_name = $property_value;
		}
		catch (Exception $ex) {
			return MS_FAILURE;
		}
    }

	public function getMetaData($name) {
        if (empty($name)) {
            return '';
        }
        return $this->msObj->metadata->get($name);
    }

    public function setMetaData($name, $value) {
        try {
            return $this->msObj->metadata->set($name, $value);
        }
        catch (Exception $e) {
            return MS_FAILURE;
        }
    }
}

/**
 * Description of gc_labelObj
 *
 * @author Marco Giraudi
 */
class gc_labelObj {
	use gc_mcClass;
	public $msObj = null;
	public function __construct() {
		$this->msObj = new labelObj();
	}

	public function set($property_name, $property_value) {
        if (empty($property_name)) {
            return MS_FAILURE;
        }
		try {
			$this->msObj->$property_name = $property_value;
		}
		catch (Exception $ex) {
			return MS_FAILURE;
		}
    }
}

/**
 * Description of gc_rectObj
 *
 * @author Marco Giraudi
 */
class gc_rectObj {
	use gc_mcClass;
	public $msObj = null;
	public function __construct($minx = -1.0, $miny = -1.0, $maxx = -1.0, $maxy = -1.0, $imageunits = 0) {
		$this->msObj = new rectObj($minx, $miny, $maxx, $maxy, $imageunits);
	}

	public function set($property_name, $property_value) {
        if (empty($property_name)) {
            return MS_FAILURE;
        }
		try {
			$this->msObj->$property_name = $property_value;
		}
		catch (Exception $ex) {
			return MS_FAILURE;
		}
    }

	public function setextent($minx, $miny, $maxx, $maxy) {
        $this->msObj->minx = $minx;
        $this->msObj->miny = $miny;
        $this->msObj->maxx = $maxx;
        $this->msObj->maxy = $maxy;
    }
}

/**
 * Description of gc_imageObj
 *
 * @author Marco Giraudi
 */
class gc_imageObj {
	use gc_mcClass;
	public $msObj = null;
	public function __construct($width, $height, $format = NULL, $filename = NULL) {
		$this->msObj = new imageObj($width, $height, $format, $filename);
	}

	public function saveImage($filename = NULL, $map = NULL) {
        try {
            if (isset($filename) && $filename != '') {
				$map_1 = (is_object($map) && property_exists($map, 'msObj')) ? $map->msObj : $map;
                $this->msObj->save($filename, $map_1);
            }
            else {
                echo $this->msObj->getBytes();
            }
            return MS_SUCCESS;
        }
        catch (Exception $e) {
            return MS_FAILURE;
        }
    }
}

?>
