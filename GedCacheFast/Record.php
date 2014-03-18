<?php

// https://github.com/stuporglue/GedCacheFast
//
// Copyright 2013 Michael Moore <stuporglue@gmail.com>
//
// Licensed under the MIT License

namespace GedCacheFast;

/*
 * A record for a single line from a GEDCOM file
 */
class Record
{

    /*
     * The parent record. Will be unset for 0-level lines
     */
    protected $_parent;

    /*
     * What level is this record
     */
    protected $_level;

    /*
     * What type is this record
     */
    protected $_type;

    /*
     * What is the ID for this record? 
     */
    protected $_id;

    /*
     * What's the value of this record?
     */
    protected $_value;

    /*
     * All this record's children records
     */
    protected $_children = Array();

    /*
     * Make a new record
     *
     */
    function __construct($parsedLineArray,\GedCacheFast\Record $parent = NULL){
        foreach($parsedLineArray as $k => $v){
            if($v !== FALSE){
                $varname = "_$k";
                $this->$varname = $v;
            }
        }

        if(!is_null($parent)){
            $this->_parent = $parent;
        }
    }

    /*
     * Get the current record's level
     *
     * @return integer
     */
    function getLevel(){
        return $this->_level;
    }

    /*
     * Get the current record's parent record
     *
     * @return \GedCacheFast\Record or undefined
     */
    function getParent(){
        return $this->_parent;
    }

    /*
     * Add a new child record to this record
     *
     * @return The newly created record
     */
    function addChild($parsedLineArray){
        $child = new \GedCacheFast\Record($parsedLineArray,$this);
        $this->_children[$parsedLineArray['type']][] = $child;
        return $child;
    }
}
