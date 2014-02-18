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
     * @param $level (required,int) Level of the record
     *
     * @param $type (required,string) Type of the record
     *
     * @param $value (optional,string) Value of the record
     *
     * @param $parent (optional,GedFast\Record) Parent record of this record
     *
     */
    function __construct($level,$type,$value,\GedCacheFast\Record $parent = NULL){
        $this->_level = $level;
        $this->_type = $type;
        if($value !== FALSE){
            $this->_value = $value;
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
    function addChild($level,$type,$value){
        $child = new \GedCacheFast\Record($level,$type,$value,$this);
        $this->_children[$type][] = $child;
        return $child;
    }
}
